"""Cutout features and scoring for "tag one bag, find the same bag" (issue #34).

numpy and Pillow only. No torch, no fastapi, no database, no argparse, so a test can
import this file on a machine without the model. dinov2_service.py owns the forward pass
and the database; this file owns every number that goes into or comes out of it.

Parity is the point. The cutoffs in the spec (0.85 / 0.90 similarity, 0.10 margin) were
measured with the reference scripts prop.py and prop3.py. The preprocessing and the score
below are those scripts' arithmetic, line for line. Do not "tidy" them: a different resize,
a different pad or a different histogram normalization moves every similarity and the
cutoffs stop meaning what they were measured as. scripts/test_bag_features.py compares
these functions to a verbatim copy of the reference.

A cutout's features are:
  dinov2  768 floats, L2-normalized (computed by the service from prepare_cutout()'s array)
  hist    1152 floats: sqrt of an 8 x 12 x 12 Lab histogram that sums to 1 over the bins

Pair score = mix * cos(dinov2) + (1 - mix) * dot(hist), mix 0.5.
"""

import io

import numpy as np
from PIL import Image

LONGEST_SIDE = 448      # prop.py: im.thumbnail((448, 448))
ALPHA_CUTOFF = 128      # prop.py: a[...,3] > 128 counts as product
MIN_OPAQUE = 1500       # prop.py: fewer opaque pixels than this is unusable
MODEL_SIDE = 224        # prop.py: padded square resized to 224
MIX = 0.5               # prop3.py gates: .5 * SD + .5 * SH
DIM = 768
HIST_BINS = 8 * 12 * 12

_MEAN = np.array([.485, .456, .406])
_STD = np.array([.229, .224, .225])


def lab(rgb):
    """sRGB (N,3) 0..255 to CIE Lab, D65. prop.py's lab(), unchanged."""
    c = rgb / 255.
    c = np.where(c > .04045, ((c + .055) / 1.055) ** 2.4, c / 12.92)
    x = c @ np.array([[.4124, .3576, .1805], [.2126, .7152, .0722], [.0193, .1192, .9505]]).T \
        / np.array([.95047, 1, 1.08883])
    f = np.where(x > .008856, np.cbrt(x), 7.787 * x + 16 / 116)
    return np.stack([116 * f[:, 1] - 16, 500 * (f[:, 0] - f[:, 1]), 200 * (f[:, 1] - f[:, 2])], 1)


def lab_sqrt_hist(px):
    """prop.py: Lab histogram over opaque pixels, normalized by the count INSIDE the bins.

    histogramdd drops a pixel whose a or b is outside +-60, so h.sum() can be below len(px).
    The reference divides by h.sum(), not len(px); that is kept.
    """
    L = lab(px)
    h, _ = np.histogramdd(L, bins=[np.linspace(0, 100, 9), np.linspace(-60, 60, 13),
                                    np.linspace(-60, 60, 13)])
    return np.sqrt(h.ravel() / max(h.sum(), 1))


def prepare_cutout(png_bytes):
    """Decode a cutout PNG into (model_input, hist), or None when it is unusable.

    model_input is the (224, 224, 3) float64 array prop.py feeds the model after
    transposing to (1, 3, 224, 224) and casting to float32 (see to_model_batch).
    hist is float64 (1152,). None means the file cannot be decoded or has fewer than
    MIN_OPAQUE opaque pixels once shrunk to LONGEST_SIDE.
    """
    try:
        im = Image.open(io.BytesIO(png_bytes)).convert('RGBA')
    except Exception:
        return None
    im.thumbnail((LONGEST_SIDE, LONGEST_SIDE))
    a = np.asarray(im)
    px = a[a[..., 3] > ALPHA_CUTOFF][:, :3].astype(float)
    if len(px) < MIN_OPAQUE:
        return None
    h = lab_sqrt_hist(px)
    n = max(im.size)
    bg = Image.new('RGBA', (n, n), (255,) * 4)
    bg.alpha_composite(im, ((n - im.width) // 2, (n - im.height) // 2))
    x = (np.asarray(bg.convert('RGB').resize((MODEL_SIDE, MODEL_SIDE), 3)) / 255. - _MEAN) / _STD
    return x, h


def to_model_batch(x):
    """(224, 224, 3) float64 to the (1, 3, 224, 224) array prop.py hands torch.tensor(...).float()."""
    return x.transpose(2, 0, 1)[None]


def unit(e):
    """prop.py: e / np.linalg.norm(e). Returns None for a zero or non-finite vector."""
    e = np.asarray(e)
    n = np.linalg.norm(e)
    if not np.isfinite(n) or n == 0:
        return None
    return e / n


def pair_scores(q_dino, q_hist, c_dino, c_hist, mix=MIX):
    """(Q, C) similarity matrix: mix * cosine + (1 - mix) * histogram dot product."""
    sd = np.asarray(q_dino, dtype=np.float64) @ np.asarray(c_dino, dtype=np.float64).T
    sh = np.asarray(q_hist, dtype=np.float64) @ np.asarray(c_hist, dtype=np.float64).T
    return mix * sd + (1 - mix) * sh


def _per_key(sims, kinds, keys):
    """Best score per key over all candidates and over seed candidates only.

    Returns {key: (best_all, best_seed or None, index_of_best_seed or -1)}.
    Competitors move best_all (the margin's runner-up) and never best_seed.
    """
    out = {}
    for key in set(keys):
        members = [i for i, k in enumerate(keys) if k == key]
        s = sims[members]
        best_all = float(s.max())
        best_seed = None
        seed_at = -1
        for j, i in enumerate(members):
            if kinds[i] == 'seed' and (best_seed is None or s[j] > best_seed):
                best_seed = float(s[j])
                seed_at = i
        out[key] = (best_all, best_seed, seed_at)
    return out


def _fold_unknown_product(pair):
    """Pair keys are 'product|colorway'. A competitor with no product is key '0|colorway'. It is a
    different key from another only when its colorway differs: with the same colorway it may be the
    very bag, so it joins that colorway's keys instead of rivaling them. With no such key it stays
    a rival of every key."""
    for key in [k for k in pair if k.startswith('0|')]:
        colorway = key.split('|', 1)[1]
        same = [k for k in pair if k != key and k.split('|', 1)[1] == colorway]
        if not same:
            continue
        entry = pair.pop(key)
        for k in same:
            pair[k]['all'] = max(pair[k]['all'], entry['all'])
            pair[k]['n'] += entry['n']


def match_queries(queries, cands, mix=MIX, near_copy=None):
    """Score query cutouts against seed and competitor cutouts. Gates are not applied here.

    queries: list of dicts {dino, hist, resource, checksum, shoot, products[, hide_colorway,
        hide_product]}
        products: iterable of product node ids on the query's photo (may be empty)
        shoot: string, '' when the photo has no Folder Path
        hide_*: report only. Drops candidates of that colorway (colorway pool) or of that
        product and colorway (pair pool), so a tag found is a tag that is wrong.
    cands: list of dicts {dino, hist, resource, checksum, kind, product, colorway, shoot, ref}
        kind: 'seed' or 'competitor'; ref: the seed's id (0 for a competitor)

    A pair is skipped when the candidate is on the query's photo, or has the query's file
    checksum (a duplicate file). Per query two pools are reduced to a best score per key:
      colorway  candidates whose product is one of the query photo's products, plus competitors
                with no product (rival colorways); key = colorway
      pair      candidates on the query's shoot (never when the shoot is ''); key = product|colorway
    near_copy: report only (prop3.py). A candidate whose photo holds a cutout with DINOv2 cosine
    above this to ANY cutout of the query's photo is dropped with its whole photo. Production
    matching leaves it None: it drops only the seed's own photo and checksum duplicates.
    Each entry is {'all': best over seeds and competitors, 'seed': best over seeds or None,
    'ref': the seed that scored it, 'n': candidates in the key}. 'all' is what a rival key is
    judged by; only 'seed' can be a match.
    """
    if not queries or not cands:
        return [{'colorway': {}, 'pair': {}} for _ in queries]
    sims = pair_scores([q['dino'] for q in queries], [q['hist'] for q in queries],
                       [c['dino'] for c in cands], [c['hist'] for c in cands], mix)
    cos = None
    if near_copy is not None:
        cos = np.asarray([q['dino'] for q in queries], dtype=np.float64) \
            @ np.asarray([c['dino'] for c in cands], dtype=np.float64).T
    c_res = np.array([c['resource'] for c in cands])
    c_chk = np.array([c['checksum'] for c in cands], dtype=object)
    c_prod = np.array([c['product'] for c in cands])
    c_color = np.array([c['colorway'] for c in cands])
    c_shoot = np.array([c['shoot'] for c in cands], dtype=object)
    kinds = [c['kind'] for c in cands]
    out = []
    for qi, q in enumerate(queries):
        ok = c_res != q['resource']
        if q['checksum']:
            ok &= c_chk != q['checksum']
        if cos is not None:
            same_photo = [j for j, other in enumerate(queries) if other['resource'] == q['resource']]
            near = set(c_res[(cos[same_photo] > near_copy).any(0)].tolist())
            if near:
                ok &= ~np.isin(c_res, list(near))
        hide_cw = int(q.get('hide_colorway') or 0)
        hide_pr = int(q.get('hide_product') or 0)
        res = {'colorway': {}, 'pair': {}}
        prods = set(int(p) for p in q['products'])
        # A competitor with no known product still says "this look is that colorway", so it counts
        # as a rival colorway for any query. Seeds always have a product.
        unknown = (c_prod == 0) & np.array([k == 'competitor' for k in kinds], dtype=bool)
        pool = ok & (np.isin(c_prod, list(prods)) | unknown) if prods else np.zeros(len(cands), bool)
        if hide_cw:
            pool &= c_color != hide_cw
        idx = np.where(pool)[0]
        if len(idx):
            keys = [str(cands[i]['colorway']) for i in idx]
            for key, (best_all, best_seed, at) in _per_key(sims[qi, idx], [kinds[i] for i in idx], keys).items():
                res['colorway'][key] = {'all': best_all, 'seed': best_seed,
                                        'ref': int(cands[idx[at]]['ref']) if at >= 0 else 0,
                                        'n': keys.count(key)}
        if q['shoot']:
            same = ok & (c_shoot == q['shoot'])
            if hide_cw:
                same &= ~((c_prod == hide_pr) & (c_color == hide_cw))
            idx = np.where(same)[0]
            if len(idx):
                keys = ['%d|%d' % (cands[i]['product'], cands[i]['colorway']) for i in idx]
                for key, (best_all, best_seed, at) in _per_key(sims[qi, idx], [kinds[i] for i in idx], keys).items():
                    res['pair'][key] = {'all': best_all, 'seed': best_seed,
                                        'ref': int(cands[idx[at]]['ref']) if at >= 0 else 0,
                                        'n': keys.count(key)}
            _fold_unknown_product(res['pair'])
        out.append(res)
    return out
