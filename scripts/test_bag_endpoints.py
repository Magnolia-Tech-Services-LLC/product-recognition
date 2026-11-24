"""Endpoint test for /cutout_features and /match_bags with a fake model and a fake database.

    python3 scripts/test_bag_endpoints.py

Needs torch, fastapi and httpx (the service's own imports plus the test client). It never
loads DINOv2 and never opens a database connection: the model is a stand-in module placed
where the service looks for it, and mysql.connector.connect is replaced. Skips with exit 0
and says so when those packages are missing.
"""

import base64
import io
import os
import sys

try:
    import numpy as np
    import torch
    import httpx  # noqa: F401
    from fastapi.testclient import TestClient
    from PIL import Image
except ImportError as e:
    print(f"skipped: {e}")
    sys.exit(0)

os.environ["CLIP_DB_USER"] = "unused"
os.environ["CLIP_DB_PASS"] = "unused"
os.environ["CLIP_DB_HOST"] = "unused"
sys.argv = ["dinov2_service.py"]
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import dinov2_service as svc  # noqa: E402
import bag_features as bf  # noqa: E402


class FakeModel(torch.nn.Module):
    """Returns the mean color of the batch spread over 768 dims: deterministic, input-dependent."""
    def forward(self, x):
        base = x.mean(dim=(2, 3))                       # (1, 3)
        return base.repeat(1, 256)[:, :768] + torch.arange(768).float() * 1e-3


svc._dinov2["model"] = FakeModel()
svc._dinov2["transform"] = None

fails = 0


def check(label, ok):
    global fails
    print(("ok   " if ok else "FAIL ") + label)
    if not ok:
        fails += 1


def png(w, h, rgb, alpha=255):
    arr = np.zeros((h, w, 4), np.uint8); arr[..., :3] = rgb; arr[..., 3] = alpha
    b = io.BytesIO(); Image.fromarray(arr, "RGBA").save(b, "PNG"); return b.getvalue()


client = TestClient(svc.app)

good = png(200, 200, (180, 60, 40))
r = client.post("/cutout_features", files={"image": ("c.png", good, "image/png")})
body = r.json()
vec = np.frombuffer(base64.b64decode(body["dinov2"]), dtype="<f4")
hist = np.frombuffer(base64.b64decode(body["hist"]), dtype="<f4")
check("cutout_features ok: 768 floats, unit length, 1152 hist floats",
      r.status_code == 200 and body["status"] == "ok" and vec.shape == (768,)
      and abs(float(np.linalg.norm(vec)) - 1.0) < 1e-5 and hist.shape == (1152,))
x, h = bf.prepare_cutout(good)
expect = bf.unit(FakeModel()(torch.tensor(bf.to_model_batch(x)).float())[0].numpy())
check("cutout_features vector is the model output on the reference preprocessing",
      np.allclose(vec, expect, atol=1e-6) and np.allclose(hist, h, atol=1e-6))
tiny = client.post("/cutout_features", files={"image": ("c.png", png(20, 20, (1, 2, 3)), "image/png")})
check("a cutout under 1500 opaque pixels is status unusable, HTTP 200",
      tiny.status_code == 200 and tiny.json() == {"status": "unusable"})
junk = client.post("/cutout_features", files={"image": ("c.png", b"nope", "image/png")})
check("a file that is not an image is unusable, not a 500",
      junk.status_code == 200 and junk.json() == {"status": "unusable"})


# ---- /match_bags against a fake product_cutout_feature ------------------------------------
rng = np.random.default_rng(7)


def rand_row(resource, region, checksum, status="ok"):
    d = rng.normal(size=768); d /= np.linalg.norm(d)
    hh = rng.random(1152) ** 4; hh = np.sqrt(hh / hh.sum())
    return (resource, region, checksum, d.astype("<f4").tobytes(), hh.astype("<f4").tobytes(), status)


ROWS = [rand_row(10, 0, "ck10"), rand_row(20, 0, "ck20"), rand_row(30, 0, "ck30"),
        rand_row(40, 0, "ck40"), rand_row(50, 0, "ck50", "unusable")]
SQL = []


class FakeCursor:
    def execute(self, sql, params=None):
        SQL.append((sql, params))
        ids = set(params)
        self._rows = [(r[0], r[1], r[2], r[3], r[4]) for r in ROWS if r[0] in ids and r[5] == "ok"]

    def fetchall(self):
        return self._rows


class FakeConn:
    def cursor(self): return FakeCursor()
    def close(self): pass


svc.mysql.connector.connect = lambda **kw: FakeConn()

req = {
    "db": "rs",
    "queries": [
        {"resource": 10, "region_index": 0, "checksum": "ck10", "shoot": "S", "products": [7]},
        {"resource": 50, "region_index": 0, "checksum": "ck50", "shoot": "S", "products": [7]},     # unusable
        {"resource": 40, "region_index": 0, "checksum": "STALE", "shoot": "S", "products": [7]},     # file changed
    ],
    "candidates": [
        {"resource": 20, "region_index": 0, "checksum": "ck20", "kind": "seed", "product": 7, "colorway": 1, "shoot": "S", "ref": 5},
        {"resource": 30, "region_index": 0, "checksum": "ck30", "kind": "competitor", "product": 7, "colorway": 2, "shoot": "S", "ref": 0},
        {"resource": 99, "region_index": 0, "checksum": "ck99", "kind": "seed", "product": 7, "colorway": 3, "shoot": "S", "ref": 6},   # no row
    ],
}
r = client.post("/match_bags", json=req)
body = r.json()
res = body["results"]
check("match_bags: results line up with queries; unusable and stale rows are null",
      r.status_code == 200 and len(res) == 3 and res[0] is not None and res[1] is None and res[2] is None)
check("match_bags: missing counts reported", body["missing_queries"] == 2 and body["missing_candidates"] == 1)
check("match_bags: seed key has a seed score and ref; competitor key has none",
      res[0]["colorway"]["1"]["ref"] == 5 and res[0]["colorway"]["1"]["seed"] is not None
      and res[0]["colorway"]["2"]["seed"] is None and res[0]["colorway"]["2"]["ref"] == 0)
d = lambda i: np.frombuffer(ROWS[i][3], "<f4").astype(float); hs = lambda i: np.frombuffer(ROWS[i][4], "<f4").astype(float)
want = .5 * (d(0) @ d(1)) + .5 * (hs(0) @ hs(1))
check("match_bags: score is 0.5 cos + 0.5 hist dot of the stored vectors",
      abs(res[0]["colorway"]["1"]["seed"] - want) < 1e-5)      # float32 vectors, float32 matmul
# One candidate list serves a whole run: the first request carries it with a key, later ones carry the key only.
keyed = dict(req, candidates_key="run1")
r1 = client.post("/match_bags", json=keyed).json()
later = {k: v for k, v in keyed.items() if k != "candidates"}
r2 = client.post("/match_bags", json=later).json()
check("match_bags: a later page sends the candidate key only and gets the same scores",
      r2.get("results") == r1["results"] and r2["missing_candidates"] == r1["missing_candidates"])
r3 = client.post("/match_bags", json=dict(later, candidates_key="other")).json()
check("match_bags: an unknown key is answered so the caller resends the list",
      r3 == {"unknown_candidates": True})
check("match_bags: runs under the torch lock (it must not overlap /segment or /vector)",
      "with _torch_lock" in open(svc.__file__).read().split("def match_bags")[1].split("def _match_bags_locked")[0])
check("match_bags: the pair pool is keyed product|colorway on the query's shoot",
      set(res[0]["pair"]) == {"7|1", "7|2"})
check("match_bags: only product_cutout_feature rows with status ok are read",
      all("product_cutout_feature" in s and "status = 'ok'" in s for s, _ in SQL))
bad = client.post("/match_bags", json={"db": "rs", "queries": [{"resource": "x"}], "candidates": []})
check("match_bags: a malformed row is a 400", bad.status_code == 400)
check("match_bags: missing fields are a 400", client.post("/match_bags", json={"db": "rs"}).status_code == 400)

print("\nFAILED %d" % fails if fails else "\nall checks passed")
sys.exit(1 if fails else 0)
