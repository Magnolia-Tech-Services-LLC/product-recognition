# DINOv2 product-recognition service

Answers "which product does this image show" by nearest-neighbour retrieval against a
reference set derived from an existing product (category-tree) field. Owns `/vector`
(image-only, dinov2 space), `/identify`, `/calibrate`, `/health`, and `/segment`
(lifestyle instances).

The ResourceSpace plugin lives alongside this service in `plugins/product_recognition/`.
Point the plugin setup page at this URL. The plugin does not create the product field.

## Why DINOv2 and not CLIP

76.5% precision@10 against CLIP's 57.5% on one matched 2,903-resource pool. CLIP
separates categories; DINOv2 separates instances, which is the actual question here.

## Deploying alongside a ResourceSpace stack

The service reads the RS database directly (`resource_dinov2_vector`,
`product_reference`) and needs no filestore access for `/identify` — the plugin sends a
resource ref and the vector is read from the DB.

`POST /segment` localises product instances on a lifestyle frame (YOLO-World cluster,
SAM2 refine, split a wide mask). Class names come from the request `classes` field, or
`SEGMENT_CLASSES` (comma-separated, default `product`). The plugin sends the preview JPEG
and stores each region's bbox plus the returned PNG cutout. Ultralytics and CLIP are
imported on first `/segment`; `/identify` still starts without them. A missing CLIP
package returns 503 and is not retried in-process (no git AutoUpdate on every request).

```yaml
  product-recognition:
    build: ./product-recognition/service     # or a prebuilt image
    restart: unless-stopped
    depends_on:
      mysql:
        condition: service_healthy
    environment:
      CLIP_DB_USER: ${MYSQL_USER}
      CLIP_DB_PASS: ${MYSQL_PASSWORD}
      CLIP_DB_HOST: mysql
      YOLO_WEIGHTS: /app/weights/yolov8s-worldv2.pt
      SAM_WEIGHTS: /app/weights/sam2_t.pt
      SEGMENT_CLASSES: product
    volumes:
      - dinov2_cache:/root/.cache          # torch.hub pulls ViT-B/14 at runtime
    expose:
      - "8001"
    healthcheck:
      # /health is async liveness (no DINOv2) so the probe never waits on the
      # inference threadpool. Do not probe /docs (urlopen with no timeout leaks CLOSE_WAIT FDs).
      test: ["CMD", "python", "-c", "import urllib.request; urllib.request.urlopen('http://localhost:8001/health', timeout=2)"]
      interval: 30s
      timeout: 10s
      retries: 5
      start_period: 60s
    mem_limit: 6g                           # SEE BELOW — YOLO+SAM on CPU on top of DINOv2
    cpus: 8                                 # persist the cap; docker update --cpus=8 is lost on recreate
```

YOLO, SAM, and CLIP ViT-B/32 are baked at `/app/weights` (ultralytics' default
WEIGHTS_DIR). First `/segment` after `--force-recreate` must not hit GitHub or
Azure. Cap the service at 8 CPUs (`cpus: 8` in compose). `docker update --cpus=8`
is lost on recreate. Start only this service (not `clip`) with:

```
docker compose --profile ai up -d --build product-recognition
```

Then point the plugin at it: `$product_recognition_service_url = "http://product-recognition:8001"`.

`PRODUCT_RECOGNITION_API_KEY` enables a shared-secret check; leave unset when the
service has no published host port and is reachable only from the stack's own network.

## Memory limit is an estimate, not a measurement

The 3 GiB limit on the combined CLIP+DINOv2 container was measured; **this split has
not been**, and `/segment` now also loads YOLO-World + SAM2-tiny on CPU.

| | |
|---|---|
| DINOv2 resident overhead, measured on top of the CLIP baseline | 0.28 GiB |
| torch runtime baseline this process now carries alone (was shared with CLIP) | ~0.9 GiB |
| reference matrix at the full library, 115,584 × 768 × 4 | 339 MiB |
| rebuild transiently holds old and new matrices | +339 MiB |
| YOLO-World v2s + SAM2-tiny weights and ultralytics runtime (CPU, unmeasured) | ~2–3 GiB |
| **estimated total with /segment loaded** | **~5 GiB** |

6g is that plus margin. `/identify` still scores with a numpy matmul (no FAISS copy).
Do not start a CLIP semantic-search container at the same moment unless the host has
headroom. **Measure RSS after the first /segment rather than after the first OOM.**

`/segment` and DINOv2 (`/vector`, and `/identify` with an image) share one
`threading.Lock` so they cannot overlap. `/identify` by resource-ref and
`/calibrate` are numpy and stay unlocked. `/health` is async and never takes
the lock. Drop the lock only after measured `memory.peak` on a sequential
`/segment` is under ~4.0 GiB with ≥1.5 GiB headroom.
