"""Fixture test for bag_features.py. Needs numpy and Pillow; no torch, no database.

    python3 scripts/test_bag_features.py

The REF_* functions below are copied from the reference scripts (prop.py for the features,
prop3.py for the pair score and the per-key reduction), with only the file and model
plumbing removed. They are the definition of "correct". If bag_features.py and this copy
disagree, the measured cutoffs in the spec no longer apply.
"""

import io
import os
import sys

import numpy as np
from PIL import Image

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import bag_features as bf  # noqa: E402

# ---- verbatim reference math (dam-local-notes/bag-match/prop.py) -------------------------
M = np.array([.485, .456, .406]); S = np.array([.229, .224, .225])


def ref_lab(rgb):
    c = rgb/255.;c = np.where(c > .04045, ((c+.055)/1.055)**2.4, c/12.92)
    x = c@np.array([[.4124, .3576, .1805], [.2126, .7152, .0722], [.0193, .1192, .9505]]).T/np.array([.95047, 1, 1.08883])
    f = np.where(x > .008856, np.cbrt(x), 7.787*x+16/116)
    return np.stack([116*f[:, 1]-16, 500*(f[:, 0]-f[:, 1]), 200*(f[:, 1]-f[:, 2])], 1)


def ref_features(png):
    """prop.py's loop body for one cutout, returning (x, h) or None where it `continue`s."""
    try: im = Image.open(io.BytesIO(png)).convert('RGBA')
    except Exception: return None
    im.thumbnail((448, 448)); a = np.asarray(im); px = a[a[..., 3] > 128][:, :3].astype(float)
    if len(px) < 1500: return None
    L = ref_lab(px); h, _ = np.histogramdd(L, bins=[np.linspace(0, 100, 9), np.linspace(-60, 60, 13), np.linspace(-60, 60, 13)]); h = np.sqrt(h.ravel()/max(h.sum(), 1))
    n = max(im.size); bg = Image.new('RGBA', (n, n), (255,)*4); bg.alpha_composite(im, ((n-im.width)//2, (n-im.height)//2))
    x = (np.asarray(bg.convert('RGB').resize((224, 224), 3))/255.-M)/S
    return x, h


# ---- reference scoring and reduction (prop3.py gate(), one query against a candidate set) --
def ref_reduce(SD, SH, i, idx, key, mix=.5):
    """Best score per key for query i over candidate columns idx: prop3's
    SIM[i,idx][key[idx]==k].max() for each k in set(key[idx])."""
    SIM = mix*SD+(1-mix)*SH
    return {k: SIM[i, idx][key[idx] == k].max() for k in set(key[idx])}


# ---- fixtures ------------------------------------------------------------------------------
def png(img):
    b = io.BytesIO(); img.save(b, 'PNG'); return b.getvalue()


def cutout(w, h, rgb_fn, opaque_box=None, alpha=255):
    """RGBA image whose opaque region is opaque_box (default all of it)."""
    arr = np.zeros((h, w, 4), np.uint8)
    x0, y0, x1, y1 = opaque_box or (0, 0, w, h)
    for y in range(y0, y1):
        for x in range(x0, x1):
            arr[y, x, :3] = rgb_fn(x, y); arr[y, x, 3] = alpha
    return png(Image.fromarray(arr, 'RGBA'))


rng = np.random.default_rng(34)
fails = 0


def check(label, ok):
    global fails
    print(('ok   ' if ok else 'FAIL ') + label)
    if not ok:
        fails += 1


def same_features(png_bytes):
    r = ref_features(png_bytes); m = bf.prepare_cutout(png_bytes)
    if r is None or m is None:
        return r is None and m is None
    return np.array_equal(r[0], m[0]) and np.array_equal(r[1], m[1])


noise = rng.integers(0, 256, (300, 200, 3), np.uint8)
fx = {
    'small noisy cutout': cutout(200, 300, lambda x, y: noise[y, x]),
    'larger than 448 (thumbnail path)': cutout(900, 1200, lambda x, y: ((x * 7) % 256, (y * 3) % 256, (x + y) % 256)),
    'wide, non-square pad': cutout(600, 150, lambda x, y: (200, (x * 2) % 256, 30)),
    # pure saturated colors have Lab a or b beyond +-60, so histogramdd drops them and
    # h.sum() < len(px): the divisor is the in-bin count, not the pixel count.
    'out-of-gamut colors': cutout(120, 120, lambda x, y: (255, 0, 0) if x < 60 else (0, 255, 0)),
    'mostly out of gamut, some in': cutout(120, 120, lambda x, y: (255, 0, 255) if x < 100 else (120, 120, 120)),
}
for name, data in fx.items():
    check('features identical to reference: ' + name, same_features(data))

# out-of-gamut: confirm the fixture really exercises the h.sum() != len(px) case
_, h_gamut = bf.prepare_cutout(fx['out-of-gamut colors'])
px = np.array([(255, 0, 0)] * 3600 + [(0, 255, 0)] * 3600, float)
hh, _ = np.histogramdd(bf.lab(px), bins=[np.linspace(0, 100, 9), np.linspace(-60, 60, 13), np.linspace(-60, 60, 13)])
check('out-of-gamut fixture drops pixels outside the bins (sum < count)', hh.sum() < len(px))
check('histogram is normalized by the in-bin count (squares sum to 1)',
      abs(float((h_gamut ** 2).sum()) - 1.0) < 1e-9 or hh.sum() == 0)

# opaque-pixel threshold: 1499 unusable, 1500 usable (after the 448 shrink, so keep it small)
# 1500 = 15 rows of 100, 1499 = 14 full rows + 99
def exact_opaque(n):
    arr = np.zeros((100, 100, 4), np.uint8)
    flat = arr.reshape(-1, 4)
    flat[:n] = (90, 140, 60, 255)
    return png(Image.fromarray(arr, 'RGBA'))
check('1500 opaque pixels is usable', bf.prepare_cutout(exact_opaque(1500)) is not None and same_features(exact_opaque(1500)))
check('1499 opaque pixels is unusable', bf.prepare_cutout(exact_opaque(1499)) is None and same_features(exact_opaque(1499)))
# alpha exactly 128 is NOT product (> 128 only); 129 is
check('alpha 128 does not count as opaque', bf.prepare_cutout(cutout(100, 100, lambda x, y: (1, 2, 3), None, 128)) is None)
check('alpha 129 counts as opaque', bf.prepare_cutout(cutout(100, 100, lambda x, y: (1, 2, 3), None, 129)) is not None)
check('a file that is not an image is unusable', bf.prepare_cutout(b'not a png') is None and ref_features(b'not a png') is None)

# model input layout
x, _ = bf.prepare_cutout(fx['small noisy cutout'])
b = bf.to_model_batch(x)
check('model batch is (1,3,224,224)', b.shape == (1, 3, 224, 224) and np.array_equal(b[0, 0], x[:, :, 0]))
check('unit(): normalizes, refuses zero and NaN',
      abs(np.linalg.norm(bf.unit(np.ones(768)))-1) < 1e-12 and bf.unit(np.zeros(768)) is None
      and bf.unit(np.full(768, np.nan)) is None)

# ---- scoring ---------------------------------------------------------------------------------
def rand_unit(n, d):
    v = rng.normal(size=(n, d)); return v / np.linalg.norm(v, axis=1, keepdims=True)


def rand_hist(n):
    h = rng.random((n, bf.HIST_BINS)) ** 4
    return np.sqrt(h / h.sum(axis=1, keepdims=True))


Q, C = 6, 40
qd, qh = rand_unit(Q, bf.DIM), rand_hist(Q)
cd, ch = rand_unit(C, bf.DIM), rand_hist(C)
SD = qd @ cd.T; SH = qh @ ch.T
check('pair score is 0.5*cos + 0.5*hist dot, as in prop3',
      np.allclose(bf.pair_scores(qd, qh, cd, ch), .5*SD+.5*SH, atol=0, rtol=1e-12))

# candidates: colorways 1..4 over products 7/8, three shoots, seeds and competitors mixed
c_color = rng.integers(1, 5, C); c_prod = rng.choice([7, 8], C)
c_shoot = rng.choice(['A', 'B', 'C'], C); c_kind = rng.choice(['seed', 'competitor'], C, p=[.5, .5])
c_res = np.arange(1000, 1000 + C); c_chk = np.array(['ck%d' % i for i in range(C)])
cands = [dict(dino=cd[i], hist=ch[i], resource=int(c_res[i]), checksum=str(c_chk[i]), kind=str(c_kind[i]),
              product=int(c_prod[i]), colorway=int(c_color[i]), shoot=str(c_shoot[i]),
              ref=(100 + i if c_kind[i] == 'seed' else 0)) for i in range(C)]
q_prods = [{7}, {8}, {7, 8}, set(), {7}, {8}]
q_shoot = ['A', 'B', 'C', 'A', '', 'C']
q_res = [1, 2, 1000 + 5, 4, 5, 6]                  # query 2 sits on candidate 5's photo
q_chk = ['q0', 'q1', 'q2', 'ck9', 'q4', 'q5']      # query 3 is a file duplicate of candidate 9
queries = [dict(dino=qd[i], hist=qh[i], resource=q_res[i], checksum=q_chk[i], shoot=q_shoot[i],
                products=q_prods[i]) for i in range(Q)]
out = bf.match_queries(queries, cands)

SIMM = .5*SD+.5*SH
all_ok = True
for i in range(Q):
    valid = (c_res != q_res[i]) & (c_chk != q_chk[i])
    # colorway pool: product in the query photo's products
    cw_pool = np.where(valid & np.isin(c_prod, list(q_prods[i])))[0] if q_prods[i] else np.array([], int)
    ref_cw = ref_reduce(SD, SH, i, cw_pool, c_color) if len(cw_pool) else {}
    got = {k: v['all'] for k, v in out[i]['colorway'].items()}
    all_ok &= set(map(str, ref_cw)) == set(got) and all(abs(ref_cw[int(k)] - v) < 1e-12 for k, v in got.items())
    # best seed per colorway
    for k, v in out[i]['colorway'].items():
        seeds = [j for j in cw_pool if c_color[j] == int(k) and c_kind[j] == 'seed']
        if not seeds:
            all_ok &= v['seed'] is None and v['ref'] == 0
        else:
            best = max(seeds, key=lambda j: SIMM[i, j])
            all_ok &= abs(v['seed'] - SIMM[i, best]) < 1e-12 and v['ref'] == 100 + best
    # pair pool: same shoot, key product|colorway
    pr_pool = np.where(valid & (c_shoot == q_shoot[i]))[0] if q_shoot[i] else np.array([], int)
    keyarr = np.array(['%d|%d' % (c_prod[j], c_color[j]) for j in range(C)])
    ref_pr = ref_reduce(SD, SH, i, pr_pool, keyarr) if len(pr_pool) else {}
    got = {k: v['all'] for k, v in out[i]['pair'].items()}
    all_ok &= set(ref_pr) == set(got) and all(abs(ref_pr[k] - v) < 1e-12 for k, v in got.items())
check('match_queries equals the reference reduction (colorway pool, pair pool, seed vs competitor)', bool(all_ok))
check('a query with no product has an empty colorway pool', out[3]['colorway'] == {})
check('a query with no shoot has an empty pair pool', out[4]['pair'] == {})
own = bf.match_queries([queries[2]], [cands[5]])
check("own photo skipped: a lone candidate on the query's photo leaves both pools empty",
      own == [{'colorway': {}, 'pair': {}}])
dup = bf.match_queries([queries[3]], [dict(cands[9], product=7, shoot='A')])
check('file checksum duplicate skipped', dup[0]['colorway'] == {} and dup[0]['pair'] == {})
comp_only = bf.match_queries([dict(queries[0], products={7}, shoot='')],
                             [dict(cands[0], kind='competitor', product=7, ref=0)])
entry = list(comp_only[0]['colorway'].values())[0]
check('a key with only competitors has a score but no seed', entry['seed'] is None and entry['all'] is not None and entry['ref'] == 0)
check('no queries or no candidates is an empty answer, not an error',
      bf.match_queries([], cands) == [] and bf.match_queries(queries[:1], []) == [{'colorway': {}, 'pair': {}}])

# ---- report-only options: near-copy removal (prop3 nd) and hidden colorway -----------------
# Reference: prop3's nd = photos holding a cutout with DINOv2 cosine > .95 to any cutout of the
# query photo, removed whole; hidden = candidates of the query's true colorway removed.
cd2 = cd.copy(); cd2[7] = qd[0] * .99 + cd[7] * .01; cd2[7] /= np.linalg.norm(cd2[7])   # near copy of query 0
c_res2 = c_res.copy(); c_res2[8] = c_res2[7]                                            # candidate 8 is on the same photo as 7
cands2 = [dict(c, dino=cd2[i], resource=int(c_res2[i])) for i, c in enumerate(cands)]
q0 = dict(queries[0], products={7, 8}, shoot='', hide_colorway=0)
plain = bf.match_queries([q0], cands2)[0]['colorway']
dropped = bf.match_queries([q0], cands2, near_copy=.95)[0]['colorway']
SD2 = qd[:1] @ cd2.T
nd = set(c_res2[(SD2 > .95).any(0)].tolist())
keep = np.array([r not in nd and r != q_res[0] and ck != q_chk[0] for r, ck in zip(c_res2, c_chk)]) & np.isin(c_prod, [7, 8])
SIM2 = .5*SD2 + .5*(qh[:1] @ ch.T)
want = {str(k): float(SIM2[0, np.where(keep & (c_color == k))[0]].max()) for k in set(c_color[keep])}
check('near_copy drops a candidate whose photo holds a >.95 DINOv2 near copy, whole photo',
      nd == {int(c_res2[7])} and {k: v['all'] for k, v in dropped.items()} == want
      and {k: v['all'] for k, v in plain.items()} != want)
hidden = bf.match_queries([dict(q0, hide_colorway=2, hide_product=7)], cands)[0]
check('hide_colorway removes that colorway from the colorway pool',
      '2' not in hidden['colorway'] and '2' in bf.match_queries([q0], cands)[0]['colorway'])
q_same = dict(q0, shoot='A')
full = bf.match_queries([q_same], cands)[0]['pair']
victim = sorted(full)[0]
vp, vc = (int(n) for n in victim.split('|'))
hp = bf.match_queries([dict(q_same, hide_colorway=vc, hide_product=vp)], cands)[0]['pair']
check('hide removes only that product|colorway key from the pair pool',
      len(full) > 1 and victim not in hp and set(hp) == set(full) - {victim})

print('\nFAILED %d' % fails if fails else '\nall checks passed')
sys.exit(1 if fails else 0)
