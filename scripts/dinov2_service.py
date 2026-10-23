"""DINOv2 product-recognition service.

Answers "which product does this image show" by nearest-neighbour retrieval against a
reference set derived from an existing product field. Split out of the CLIP service,
which keeps /vector (text+image), /search, /duplicates and /tag for semantic search;
this process owns /identify, /calibrate, /segment and the dinov2 vector space
(resource_dinov2_vector).

DINOv2 measured 76.5% precision@10 against CLIP's 57.5% on one matched 2,903-resource
pool, because CLIP separates categories and DINOv2 separates instances.

The DB and Space plumbing below is adapted from the ResourceSpace 11 clip plugin's
reference service (plugins/clip/scripts/clip_service.py), which is BSD 3-Clause — see
NOTICE-third-party.txt in the repository root for the retained copyright notice and
disclaimer. The reference-set construction, the similarity vote, /identify and
/calibrate are original work by Magnolia Tech Services.

Security posture inherited from that service: HTTPException details never echo raw
exception text (full detail goes to stdout, i.e. `docker logs`), and an opt-in
shared-secret check is available via PRODUCT_RECOGNITION_API_KEY. The service publishes
no host port and is reachable only from the stack's own Docker network.
"""

import argparse
import base64
import getpass
import io
import os
import tempfile
import threading
import time
from typing import Optional

# ultralytics.utils reads these at import; set before any ultralytics import.
os.environ.setdefault("YOLO_OFFLINE", "true")
os.environ.setdefault("YOLO_AUTOINSTALL", "false")

import mysql.connector
import numpy as np
import torch
import uvicorn
from fastapi import FastAPI, File, UploadFile, Form, HTTPException, Header, Depends
from fastapi.responses import JSONResponse
from PIL import Image

API_KEY = os.getenv("PRODUCT_RECOGNITION_API_KEY", "")


def require_api_key(x_api_key: str = Header(default="")):
    if API_KEY and x_api_key != API_KEY:
        raise HTTPException(status_code=401, detail="Invalid or missing API key")
# Command-line arguments
parser = argparse.ArgumentParser(description="DINOv2 product-recognition service for ResourceSpace")
parser.add_argument("--dbuser", help="MySQL username")
parser.add_argument("--dbpass", help="MySQL password")
parser.add_argument("--host", default="0.0.0.0", help="Host to run on")
parser.add_argument("--port", type=int, default=8000, help="Port to run on")
args = parser.parse_args()

# Prefer environment variables if set
db_user = os.getenv("CLIP_DB_USER", args.dbuser)
db_pass = os.getenv("CLIP_DB_PASS", args.dbpass)

# Fall back to prompts if still not set
if not db_user:
    db_user = input("Enter MySQL username (or set CLIP_DB_USER / use --dbuser): ")

if not db_pass:
    db_pass = getpass.getpass("Enter MySQL password (or set CLIP_DB_PASS / use --dbpass): ")

# Global DB credentials (used later)
# ponytail: only line changed from the official RS clip plugin's reference
# clip_service.py (plugins/clip/scripts/clip_service.py in the RS 11.0 release) -
# host was hardcoded to "localhost", made configurable so this can run as its own
# compose service talking to our own "mysql" container instead of sharing a host.
DB_CONFIG = {
    "host": os.getenv("CLIP_DB_HOST", "localhost"),
    "user": db_user,
    "password": db_pass
}
app = FastAPI()
device = "cpu"
class Space:
    def __init__(self, name, dim, table, where, encode):
        self.name = name
        self.dim = dim
        self.table = table
        self.where = where            # SQL fragment; the clip table also holds text vectors
        self.blob_len = dim * 4       # float32
        self.encode = encode
# DINOv2 loads on first use and then stays resident. A process that is never asked to
# identify anything pays disk and nothing else.
_dinov2 = {}
# One lock for every torch forward. DINOv2's nn.Module lock is this same
# object; YOLO/SAM still has its own lock in segment_products. Sharing this
# lock is what stops /segment overlapping /vector under mem_limit 6g.
# /identify (resource-ref) and /calibrate are numpy and do not take this lock.
# /health never does.
_torch_lock = threading.Lock()
_dinov2_lock = _torch_lock
def _encode_dinov2(img):
    # Lock covers load AND forward: two threadpool workers must not share one nn.Module,
    # and must not run YOLO/SAM at the same time (6g cap).
    with _dinov2_lock:
        if "model" not in _dinov2:
            import torchvision.transforms as T
            print("Loading DINOv2 ViT-B/14...")
            m = torch.hub.load("facebookresearch/dinov2", "dinov2_vitb14")
            m.eval().to(device)
            _dinov2["transform"] = T.Compose([
                T.Resize(256, interpolation=T.InterpolationMode.BICUBIC),
                T.CenterCrop(224),
                T.ToTensor(),
                T.Normalize((0.485, 0.456, 0.406), (0.229, 0.224, 0.225)),
            ])
            _dinov2["model"] = m       # published last: this is the key the guard tests
            print("DINOv2 loaded.")
        with torch.no_grad():
            x = _dinov2["transform"](img).unsqueeze(0).to(device)
            return _dinov2["model"](x).cpu().numpy().flatten()
SPACES = {
    "dinov2": Space("dinov2", 768, "resource_dinov2_vector", "1", _encode_dinov2),
}


def get_space(name):
    space = SPACES.get(name or "dinov2")
    if space is None:
        raise HTTPException(status_code=400, detail="Unknown vector space")
    return space


@app.get("/health")
async def health():
    """Liveness only. Do not load DINOv2. async so the probe never waits on the threadpool."""
    return {"ok": True}


def _image_for_dinov2(contents: bytes) -> Image.Image:
    """RGB, with transparent cutouts composited onto white to match studio references."""
    img = Image.open(io.BytesIO(contents))
    if img.mode in ("RGBA", "LA") or (img.mode == "P" and "transparency" in img.info):
        rgba = img.convert("RGBA")
        bg = Image.new("RGB", rgba.size, (255, 255, 255))
        bg.paste(rgba, mask=rgba.split()[-1])
        return bg
    return img.convert("RGB")


reference_cache = {}      # { (db_name, space): (matrix, resources, labels) }
reference_state = {}      # { (db_name, space): (ref_rows, ref_max, ref_digest, vec_rows, vec_max) }


def load_references(db_name, space):
    """
    Reference vectors and their model labels, straight from product_reference.

    The leaf-node rule that decides WHICH resources are references lives in PHP
    (product_recognition_functions.php: pr_reference_rows) and materialises here as rows.
    This service does not know what a node tree is and must not learn.
    """
    key = (db_name, space.name)
    try:
        conn = mysql.connector.connect(**DB_CONFIG, database=db_name)
        cursor = conn.cursor()
        # row count and MAX(ref) catch inserts, deletes and status flips, but a relabel that
        # only corrects model_node in place moves neither - pr_sync_references (Task 6),
        # pr_accept_suggestion (Task 10) and the held-out test's restore (Task 12) all do
        # exactly that UPDATE. Without the digest, a corrected reference would keep voting
        # for the wrong product forever, with the correction appearing to do nothing - the
        # same stale-data trap load_vectors_for_space's docstring warns about, through a
        # different door. BIT_XOR over a per-row CRC32 (not SUM) because two labels changing
        # in opposite directions can cancel out a sum; XOR of independent checksums cannot.
        cursor.execute(
            "SELECT COUNT(*), COALESCE(MAX(ref),0), "
            "       COALESCE(BIT_XOR(CRC32(CONCAT_WS(':', resource, model_node, status))),0) "
            "FROM product_reference WHERE status IN ('derived','pinned')"
        )
        ref_rows, ref_max, ref_digest = cursor.fetchone()
        cursor.execute(
            f"SELECT COUNT(*), COALESCE(MAX(ref),0) FROM {space.table} WHERE {space.where}"
        )
        vec_rows, vec_max = cursor.fetchone()
        state = (ref_rows, ref_max, ref_digest, vec_rows, vec_max)

        if key in reference_cache and reference_state.get(key) == state:
            conn.close()
            return reference_cache[key]

        # Drop references whose model_node has children. product_reference can still hold a
        # pinned row for a model that later gained children (sync keeps pins; only derived
        # rows are deleted). Voting those in teaches a category label, not a product - the
        # same grain the PHP leaf rule refuses for derived refs. Checked here rather than
        # reimplemented as a full leaf-selection rule: PHP still owns which resources become
        # references; this only refuses labels that are no longer leaves.
        cursor.execute(
            f"SELECT pr.resource, pr.model_node, v.vector_blob "
            f"FROM product_reference pr "
            f"JOIN {space.table} v ON v.resource = pr.resource AND {space.where} "
            f"WHERE pr.status IN ('derived','pinned') "
            f"  AND NOT EXISTS (SELECT 1 FROM node ch WHERE ch.parent = pr.model_node) "
            f"ORDER BY pr.resource"
        )
        rows = cursor.fetchall()
        conn.close()
    except Exception as e:
        print(f"Reference load error: {e}")
        raise HTTPException(status_code=500, detail="Reference load error")

    vectors, resources, labels = [], [], []
    for resource, model_node, blob in rows:
        if blob is None or len(blob) != space.blob_len:
            continue
        vector = np.frombuffer(blob, dtype=np.float32).copy()
        norm = np.linalg.norm(vector)
        if norm == 0 or np.isnan(norm):
            continue
        vectors.append(vector / norm)
        resources.append(int(resource))
        labels.append(int(model_node))

    matrix = (np.stack(vectors) if vectors
              else np.empty((0, space.dim), dtype=np.float32)).astype(np.float32)
    reference_cache[key] = (matrix, resources, labels)
    reference_state[key] = state
    print(f"Cached {len(resources)} {space.name} references "
          f"({len(set(labels))} models) for DB {db_name}")
    return reference_cache[key]

def vote(sims, resources, labels, k, exclude_resource=None):
    """
    Similarity-weighted k-NN vote.

    confidence = winning model's summed similarity / total summed similarity over the k
    neighbours. Negative similarities are clamped to zero so an opposite-facing vector cannot
    subtract from a rival's score.
    """
    sims = sims.copy()
    if exclude_resource is not None:
        for i, r in enumerate(resources):
            if r == exclude_resource:
                sims[i] = -np.inf

    order = [i for i in np.argsort(-sims)[:k] if np.isfinite(sims[i])]
    if not order:
        return [], 0.0, None

    # best[model] is that model's OWN nearest neighbour, not the query's. The caller records it
    # per suggestion so "why was this suggested" points at a photo of the model being suggested;
    # using the global top reference would make every rank-2 and rank-3 row cite the rank-1
    # model's photo, which is a traceability claim that does not hold.
    weights = {}
    best = {}
    for i in order:
        w = max(float(sims[i]), 0.0)
        label = labels[i]
        weights[label] = weights.get(label, 0.0) + w
        if label not in best:          # order is descending, so the first seen is the nearest
            best[label] = int(resources[i])

    total = sum(weights.values())
    if total <= 0:
        return [], float(sims[order[0]]), resources[order[0]]

    ranked = sorted(weights.items(), key=lambda kv: -kv[1])
    candidates = [{"model_node": int(m), "confidence": float(w / total),
                   "top_reference": best[m]} for m, w in ranked]
    return candidates, float(sims[order[0]]), int(resources[order[0]])
@app.post("/vector", dependencies=[Depends(require_api_key)])
def generate_vector(
    image: UploadFile = File(...),
    space: str = Form("dinov2"),
):
    # Image-only: DINOv2 has no text tower and no joint space to project into. The CLIP
    # service keeps the text branch.
    vspace = get_space(space)
    try:
        img = _image_for_dinov2(image.file.read())
        vector_np = vspace.encode(img)
        norm = np.linalg.norm(vector_np)
        if norm == 0 or np.isnan(norm):
            raise HTTPException(status_code=500, detail="Degenerate vector")
        return JSONResponse(content=(vector_np / norm).tolist())
    except HTTPException:
        raise
    except Exception as e:
        print(f"Vector generation error: {e}")
        raise HTTPException(status_code=500, detail="Vector generation error")

@app.post("/identify", dependencies=[Depends(require_api_key)])
def identify(
    db: str = Form(...),
    space: str = Form("dinov2"),
    resource: Optional[int] = Form(None),
    image: Optional[UploadFile] = File(None),
    k: int = Form(10),
    top_n: int = Form(3),
):
    """Rank models for one resource by a similarity-weighted vote over its k nearest references."""
    vspace = get_space(space)
    matrix, resources, labels = load_references(db, vspace)
    if len(resources) == 0:
        return JSONResponse(content={"candidates": [], "top_similarity": 0.0,
                                     "top_reference": None, "reference_count": 0})

    if image is not None:
        img = _image_for_dinov2(image.file.read())
        query = vspace.encode(img)
    elif resource is not None:
        try:
            conn = mysql.connector.connect(**DB_CONFIG, database=db)
            cursor = conn.cursor()
            cursor.execute(
                f"SELECT vector_blob FROM {vspace.table} WHERE resource = %s AND {vspace.where}",
                (resource,),
            )
            row = cursor.fetchone()
            conn.close()
        except Exception as e:
            print(f"Identify DB error: {e}")
            raise HTTPException(status_code=500, detail="DB error")
        if not row or not row[0] or len(row[0]) != vspace.blob_len:
            raise HTTPException(status_code=404, detail="No vector for that resource")
        query = np.frombuffer(row[0], dtype=np.float32).copy()
    else:
        raise HTTPException(status_code=400, detail="Provide either 'resource' or 'image'")

    norm = np.linalg.norm(query)
    if norm == 0 or np.isnan(norm):
        raise HTTPException(status_code=400, detail="Invalid vector norm")

    sims = matrix @ (query / norm).astype(np.float32)
    candidates, top_sim, top_ref = vote(sims, resources, labels, k, exclude_resource=resource)
    # The top-3 candidate list is already summed by model. Family and sub-class
    # are a second sum over these neighbors, so the cut has to travel with them.
    order = [i for i in np.argsort(-sims)[:k] if np.isfinite(sims[i])]
    if resource is not None:
        order = [i for i in order if int(resources[i]) != int(resource)]
    neighbors = [{
        "resource": int(resources[i]),
        "model_node": int(labels[i]),
        "similarity": float(max(sims[i], 0.0)),
    } for i in order]
    return JSONResponse(content={
        "candidates": candidates[:top_n],
        "neighbors": neighbors,
        "top_similarity": top_sim,
        "top_reference": top_ref,
        "reference_count": len(resources),
    })

@app.post("/calibrate", dependencies=[Depends(require_api_key)])
def calibrate(
    db: str = Form(...),
    space: str = Form("dinov2"),
    k: int = Form(10),
    max_queries_per_model: int = Form(500),
):
    """
    Leave-one-out over the reference set: classify every reference against all the others.

    One dense matmul over the reference matrix - NOT a FAISS index search, despite the index
    this service keeps for /search. Returns raw observations; the thresholds themselves are
    PHP's job, because the target precision and the Wilson bound are policy rather than maths
    this service should own.

    max_queries_per_model bounds the number of QUERIES, not the total cost. Cost is O(Q x N):
    the cap is the Q side, while N - the reference set every query is compared against - is
    uncapped and grows with the library. Today Q and N are both ~2,900, which makes the cap
    look like it bounds everything; that is an artefact of a small reference set, not a
    property of the design. Re-measure after any large import rather than assuming the cap
    holds the cost down.
    """
    vspace = get_space(space)
    matrix, resources, labels = load_references(db, vspace)
    if len(resources) == 0:
        return JSONResponse(content={"observations": [], "reference_count": 0,
                                     "elapsed_seconds": 0.0})

    by_model = {}
    for i, label in enumerate(labels):
        by_model.setdefault(label, []).append(i)

    query_idx = []
    for label, idxs in by_model.items():
        if len(idxs) <= max_queries_per_model:
            query_idx.extend(idxs)
        else:
            stride = len(idxs) / max_queries_per_model
            query_idx.extend(idxs[int(j * stride)] for j in range(max_queries_per_model))
    query_idx.sort()

    start = time.time()
    sims_all = matrix[query_idx] @ matrix.T          # O(Q x N): Q capped, N is not
    observations = []
    for row, qi in enumerate(query_idx):
        candidates, top_sim, top_ref = vote(
            sims_all[row], resources, labels, k, exclude_resource=resources[qi]
        )
        if not candidates:
            continue
        observations.append({
            "resource": resources[qi],
            "true_model": labels[qi],
            "predicted_model": candidates[0]["model_node"],
            "confidence": candidates[0]["confidence"],
            "top_similarity": top_sim,
            "top_reference": top_ref,
        })
    elapsed = time.time() - start
    print(f"Calibrated {len(observations)} observations in {elapsed:.1f}s")
    return JSONResponse(content={"observations": observations,
                                 "reference_count": len(resources),
                                 "elapsed_seconds": elapsed})


@app.post("/segment", dependencies=[Depends(require_api_key)])
def segment(image: UploadFile = File(...), classes: str = Form(default="")):
    """Lifestyle instances: YOLO-World cluster, SAM2 refine, split wide masks.

    Returns {regions: [{bbox: [x1,y1,x2,y2] fractions, png_base64: ...}, ...]}.
    Ultralytics is imported here so a container without it still serves /identify.
    Missing CLIP / ultralytics extras return 503 and are cached in-process.
    `classes` is a comma-separated YOLO-World vocabulary; empty uses SEGMENT_CLASSES.
    """
    try:
        from segment_products import SegmenterUnavailable, segment_products
    except ImportError as e:
        print(f"Segmenter unavailable: {e}")
        raise HTTPException(status_code=503, detail="Segmenter unavailable") from e

    contents = image.file.read()
    suffix = os.path.splitext(image.filename or "image.jpg")[1] or ".jpg"
    tmp_path = None
    try:
        with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
            tmp.write(contents)
            tmp_path = tmp.name
        with _torch_lock:
            regions = segment_products(tmp_path, classes)
    except HTTPException:
        raise
    except SegmenterUnavailable as e:
        print(f"Segmenter unavailable: {e}")
        raise HTTPException(status_code=503, detail="Segmenter unavailable") from e
    except Exception as e:
        print(f"Segment error: {e}")
        raise HTTPException(status_code=500, detail="Segment error")
    finally:
        if tmp_path is not None:
            try:
                os.unlink(tmp_path)
            except OSError:
                pass

    return JSONResponse(content={
        "regions": [
            {
                "bbox": r["bbox"],
                "png_base64": base64.b64encode(r["png"]).decode("ascii"),
            }
            for r in regions
        ]
    })


if __name__ == "__main__":
    uvicorn.run(app, host=args.host, port=args.port, log_level="debug")
