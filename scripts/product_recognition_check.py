"""
Runnable check for the DINOv2 worker. No framework: this repo has none.

    docker compose exec product-recognition python /app/product_recognition_check.py

Asserts the space registry, vote maths, and that resource_dinov2_vector enforces one
vector per resource (the UNIQUE key the PHP embed path relies on).
"""
import asyncio
import builtins
import os
import struct
import types
from contextlib import contextmanager

import mysql.connector
import numpy as np

import dinov2_service as ds

DB = os.getenv("CLIP_DB_NAME", "resourcespace")
SENTINEL = -424242


def connect():
    return mysql.connector.connect(**ds.DB_CONFIG, database=DB)


def blob(dim, seed):
    rng = np.random.default_rng(seed)
    v = rng.standard_normal(dim).astype(np.float32)
    return struct.pack(f"{dim}f", *(v / np.linalg.norm(v)))


def test_health_does_not_load_model():
    assert "model" not in ds._dinov2
    assert "/health" in {r.path for r in ds.app.routes}
    assert asyncio.iscoroutinefunction(ds.health)
    assert asyncio.run(ds.health()) == {"ok": True}
    assert "model" not in ds._dinov2
    print("ok  health liveness does not load DINOv2")


def test_cpu_endpoints_are_sync():
    """Inference/DB handlers must be sync def so Starlette runs them off the event loop."""
    wanted = {"/segment", "/vector", "/identify", "/calibrate"}
    found = {r.path: r.endpoint for r in ds.app.routes if r.path in wanted}
    assert set(found) == wanted
    for path, endpoint in found.items():
        assert not asyncio.iscoroutinefunction(endpoint), path
    print("ok  CPU/DB endpoints are not coroutine functions")


def test_torch_lock_is_shared():
    """/segment and DINOv2 must share one lock so they cannot overlap under 6g."""
    assert ds._torch_lock is ds._dinov2_lock
    print("ok  /segment and DINOv2 share one torch lock")


def test_spaces_registered():
    assert set(ds.SPACES) == {"dinov2"}
    assert ds.SPACES["dinov2"].dim == 768
    assert ds.SPACES["dinov2"].blob_len == 3072
    assert ds.SPACES["dinov2"].table == "resource_dinov2_vector"
    print("ok  spaces registered")


def test_vote_maths():
    resources = [10, 11, 12, 13]
    labels = [100, 100, 200, 200]
    sims = np.array([0.9, 0.8, 0.4, 0.2], dtype=np.float32)

    candidates, top_sim, top_ref = ds.vote(sims, resources, labels, k=4)
    assert candidates[0]["model_node"] == 100
    assert abs(candidates[0]["confidence"] - 1.7 / 2.3) < 1e-6, candidates
    assert abs(top_sim - 0.9) < 1e-6 and top_ref == 10

    candidates, top_sim, top_ref = ds.vote(sims, resources, labels, k=4, exclude_resource=10)
    assert abs(candidates[0]["confidence"] - 0.8 / 1.4) < 1e-6, candidates
    assert top_ref == 11

    candidates, _, _ = ds.vote(sims, resources, labels, k=2)
    assert candidates[0]["model_node"] == 100 and abs(candidates[0]["confidence"] - 1.0) < 1e-6

    candidates, _, _ = ds.vote(np.array([0.5, -0.9, 0.0, 0.0], dtype=np.float32),
                               resources, labels, k=4)
    assert candidates[0]["model_node"] == 100 and abs(candidates[0]["confidence"] - 1.0) < 1e-6
    print("ok  vote maths")


def test_one_vector_per_resource():
    table = ds.SPACES["dinov2"].table
    conn = connect()
    cur = conn.cursor()
    cur.execute(f"DELETE FROM {table} WHERE resource = %s", (SENTINEL,))
    conn.commit()
    cur.execute(
        f"INSERT INTO {table} (resource, vector_blob, checksum) VALUES (%s,%s,%s)",
        (SENTINEL, blob(768, 1), "aaa"),
    )
    conn.commit()
    try:
        cur.execute(
            f"INSERT INTO {table} (resource, vector_blob, checksum) VALUES (%s,%s,%s)",
            (SENTINEL, blob(768, 2), "bbb"),
        )
        conn.commit()
        raise AssertionError("UNIQUE on resource was not enforced")
    except mysql.connector.IntegrityError:
        conn.rollback()

    cur.execute(f"DELETE FROM {table} WHERE resource = %s", (SENTINEL,))
    conn.commit()
    conn.close()
    print("ok  one vector per resource")


@contextmanager
def _isolated_segmenter():
    import segment_products as sp

    previous = (sp._unavailable, dict(sp._models), builtins.__import__, sp._weight)
    sp._models.clear()
    sp._unavailable = None
    try:
        yield sp
    finally:
        sp._unavailable, models, builtins.__import__, sp._weight = previous
        sp._models.clear()
        sp._models.update(models)


def _assert_cached_unavailable(sp, needle, load_classes):
    try:
        sp._models_loaded(load_classes)
    except sp.SegmenterUnavailable as e:
        assert needle in str(e)
    else:
        raise AssertionError(f"expected SegmenterUnavailable containing {needle!r}")
    assert sp._unavailable is not None
    try:
        sp._models_loaded(load_classes)
    except sp.SegmenterUnavailable:
        pass
    else:
        raise AssertionError("cached miss should raise without importing")


def test_segmenter_unavailable_is_cached():
    real_import = builtins.__import__

    def fake_import(name, *args, **kwargs):
        root = name.split(".", 1)[0]
        if root == "clip":
            raise ImportError("No module named 'clip'")
        if root == "ultralytics":
            raise AssertionError("CLIP miss must not import ultralytics")
        return real_import(name, *args, **kwargs)

    with _isolated_segmenter() as sp:
        builtins.__import__ = fake_import
        _assert_cached_unavailable(sp, "clip", ["backpack"])
    print("ok  missing CLIP is cached SegmenterUnavailable without loading YOLO")


def test_weight_refuses_download():
    import tempfile

    import segment_products as sp

    saved = os.environ.get("YOLO_WEIGHTS")
    missing = "no-such-yolo-weights-454.pt"
    try:
        os.environ.pop("YOLO_WEIGHTS", None)
        try:
            sp._weight(missing, "YOLO_WEIGHTS")
        except FileNotFoundError as e:
            assert missing in str(e) and "YOLO_WEIGHTS" in str(e)
        else:
            raise AssertionError("missing weights must raise FileNotFoundError")
        fd, path = tempfile.mkstemp(suffix=".pt")
        os.close(fd)
        try:
            os.environ["YOLO_WEIGHTS"] = path
            assert sp._weight(missing, "YOLO_WEIGHTS") == path
        finally:
            os.unlink(path)
    finally:
        if saved is None:
            os.environ.pop("YOLO_WEIGHTS", None)
        else:
            os.environ["YOLO_WEIGHTS"] = saved
    print("ok  missing weights raise; existing override is used")


def test_missing_weights_do_not_import_ultralytics():
    real_import = builtins.__import__

    def fake_import(name, *args, **kwargs):
        root = name.split(".", 1)[0]
        if root == "clip":
            return types.ModuleType("clip")
        if root == "ultralytics":
            raise AssertionError("missing weights must not import ultralytics")
        return real_import(name, *args, **kwargs)

    def fake_weight(name, env):
        raise FileNotFoundError(f"{name} not found (set {env})")

    with _isolated_segmenter() as sp:
        sp._weight = fake_weight
        builtins.__import__ = fake_import
        _assert_cached_unavailable(sp, "not found", ["product"])
    print("ok  missing weights are cached SegmenterUnavailable without loading YOLO")


def test_baked_weights_present_in_sidecar():
    if not os.path.isdir("/app/weights"):
        print("skip  baked /app/weights (not in sidecar image)")
        return
    missing = [
        p
        for p in (
            "/app/weights/yolov8s-worldv2.pt",
            "/app/weights/sam2_t.pt",
            "/app/weights/clip/ViT-B-32.pt",
        )
        if not os.path.isfile(p)
    ]
    if missing:
        raise AssertionError("sidecar image missing " + ", ".join(missing))
    print("ok  baked /app/weights files present")


if __name__ == "__main__":
    test_health_does_not_load_model()
    test_cpu_endpoints_are_sync()
    test_torch_lock_is_shared()
    test_spaces_registered()
    test_vote_maths()
    test_segmenter_unavailable_is_cached()
    test_weight_refuses_download()
    test_missing_weights_do_not_import_ultralytics()
    test_baked_weights_present_in_sidecar()
    test_one_vector_per_resource()
    print("\nall checks passed")
