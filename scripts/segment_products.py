"""Lifestyle product instances: YOLO-World proposes a cluster, SAM2 refines, wide
masks are split so two bags on a cart become two regions.

Used by POST /segment. Lazy-loads weights on first call.
"""
from __future__ import annotations

import io
import os
import threading

import numpy as np
from PIL import Image

_lock = threading.Lock()
_models = {}
# Load failure (typically missing CLIP). Later /segment calls raise immediately
# so ultralytics does not git-AutoUpdate on every request.
_unavailable: str | None = None


class SegmenterUnavailable(Exception):
    """YOLO-World extras missing or failed to import. Cached per process."""


def _class_list(raw: str | None = None) -> list[str]:
    source = (raw or "").strip() or os.getenv("SEGMENT_CLASSES", "product")
    names = [c.strip() for c in source.split(",") if c.strip()]
    return names or ["product"]


def _weight(name: str, env: str) -> str:
    """Local path only. A bare name makes ultralytics download into /app."""
    for path in (os.getenv(env), f"/app/weights/{name}"):
        if path and os.path.isfile(path):
            return path
    raise FileNotFoundError(
        f"{name} not found (set {env} or place it in /app/weights)"
    )


def _models_loaded(classes: list[str]):
    global _unavailable
    os.environ.setdefault("YOLO_OFFLINE", "true")
    os.environ.setdefault("YOLO_AUTOINSTALL", "false")
    if _unavailable is not None:
        raise SegmenterUnavailable(_unavailable)
    try:
        # YOLO-World set_classes does `import clip`. Fail here so a miss does
        # not materialise YOLO+SAM (~2–3 GiB) on top of DINOv2.
        import clip
        if "yolo" not in _models:
            yolo_path = _weight("yolov8s-worldv2.pt", "YOLO_WEIGHTS")
            sam_path = _weight("sam2_t.pt", "SAM_WEIGHTS")
            from ultralytics import SAM, YOLOWorld

            print("Loading YOLO-World + SAM2-tiny for lifestyle segmentation...")
            _models["yolo"] = YOLOWorld(yolo_path)
            _models["sam"] = SAM(sam_path)
            print("Lifestyle segmenter loaded.")
    except Exception as e:
        _models.clear()
        _unavailable = str(e)
        print(f"Segmenter unavailable: {e}")
        raise SegmenterUnavailable(_unavailable) from e
    _models["yolo"].set_classes(classes)


def _sam_box(image_path: str, bb: list[float], w: int, h: int) -> np.ndarray | None:
    r = _models["sam"].predict(image_path, bboxes=[list(map(float, bb))], verbose=False)[0]
    if r.masks is None or len(r.masks.data) == 0:
        return None
    mask = r.masks.data[0].cpu().numpy()
    if mask.shape[0] != h or mask.shape[1] != w:
        mask = np.array(
            Image.fromarray((mask * 255).astype(np.uint8)).resize((w, h), Image.NEAREST)
        ) > 127
    else:
        mask = mask > 0.5
    if float(mask.mean()) < 0.01:
        return None
    return mask


def _geom(mask: np.ndarray) -> dict:
    ys, xs = np.where(mask)
    return {
        "xs": xs,
        "ys": ys,
        "x1": int(xs.min()),
        "y1": int(ys.min()),
        "x2": int(xs.max()),
        "y2": int(ys.max()),
        "w": int(xs.max() - xs.min()),
        "h": int(ys.max() - ys.min()),
        "area": float(mask.mean()),
    }


def _is_cartish(g: dict, width: int, height: int) -> bool:
    # Large object sitting on the ground — cart body / wheels, not a bag.
    return (g["y2"] / height > 0.82 and g["area"] > 0.10) or (
        g["x1"] / width < 0.05 and g["area"] > 0.10
    )


def _split_if_wide(image_path: str, mask: np.ndarray, width: int, height: int, depth: int = 0) -> list:
    g = _geom(mask)
    wide = g["w"] / width > 0.22 and g["w"] / max(g["h"], 1) > 0.42
    if not wide or depth >= 2:
        return [] if _is_cartish(g, width, height) else [mask]
    mid = float(np.median(g["xs"]))
    left = [g["x1"], g["y1"], mid + 0.08 * g["w"], g["y2"]]
    right = [mid - 0.08 * g["w"], g["y1"], g["x2"], g["y2"]]
    out = []
    for bb in (left, right):
        m2 = _sam_box(image_path, bb, width, height)
        if m2 is None:
            continue
        out.extend(_split_if_wide(image_path, m2, width, height, depth + 1))
    return out


def _cutout_png(image: Image.Image, mask: np.ndarray) -> bytes:
    arr = np.array(image.convert("RGBA"))
    out = np.zeros_like(arr)
    out[mask] = arr[mask]
    ys, xs = np.where(mask)
    pad = 4
    xa, xb = max(0, int(xs.min()) - pad), min(arr.shape[1], int(xs.max()) + pad)
    ya, yb = max(0, int(ys.min()) - pad), min(arr.shape[0], int(ys.max()) + pad)
    crop = Image.fromarray(out[ya:yb, xa:xb])
    buf = io.BytesIO()
    crop.save(buf, format="PNG")
    return buf.getvalue()


def segment_products(image_path: str, classes: str | None = None) -> list[dict]:
    """Return [{bbox: [x1,y1,x2,y2] fractions, png: bytes}, ...]."""
    with _lock:
        _models_loaded(_class_list(classes))
        image = Image.open(image_path).convert("RGB")
        width, height = image.size
        det = _models["yolo"].predict(image_path, conf=0.08, verbose=False)[0]
        if det.boxes is None or len(det.boxes) == 0:
            return []

        regions: list[np.ndarray] = []
        for b in det.boxes:
            bb = [float(x) for x in b.xyxy[0].tolist()]
            mask = _sam_box(image_path, bb, width, height)
            if mask is None:
                continue
            regions.extend(_split_if_wide(image_path, mask, width, height))

        out = []
        for mask in regions:
            g = _geom(mask)
            bbox = [
                round(g["x1"] / width, 4),
                round(g["y1"] / height, 4),
                round(g["x2"] / width, 4),
                round(g["y2"] / height, 4),
            ]
            out.append({"bbox": bbox, "png": _cutout_png(image, mask)})
        return out
