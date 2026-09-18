# syntax=docker/dockerfile:1
FROM python:3.11-slim

# DINOv2 product-recognition service. Leaner than the CLIP image it came from: no
# faiss-cpu — /identify scores with a plain numpy matmul against the reference matrix.
RUN apt-get update && apt-get install -y --no-install-recommends git libgl1 libglib2.0-0 libgomp1 \
    && rm -rf /var/lib/apt/lists/*

# CPU-only torch wheels; no --gpus at this scale. Versions match what the combined
# service was measured on (2026-08-07 `pip list` against the live container) so a
# rebuild reproduces the deployment the precision numbers came from.
# Separate RUNs so CLIP or ultralytics bumps do not bust the torch layer.
RUN pip install --no-cache-dir torch==2.13.0+cpu torchvision==0.28.0+cpu --index-url https://download.pytorch.org/whl/cpu
RUN pip install --no-cache-dir \
        fastapi==0.141.1 uvicorn==0.52.1 \
        mysql-connector-python==26.7.0 \
        python-multipart==0.0.32 \
        ultralytics==8.4.124 opencv-python-headless
# YOLO-World set_classes does `import clip`. openai-clip provides that module so
# ultralytics does not git-AutoUpdate CLIP at runtime.
RUN pip install --no-cache-dir openai-clip==1.0.1

# Bake all segmenter weights into ultralytics' default WEIGHTS_DIR (cwd
# /app/weights). YOLO_OFFLINE does not skip GitHub/Azure downloads; a miss
# writes into the writable layer. Do not commit the .pt files.
ADD --checksum=sha256:9b2c17ab6124a913e9b3a5c170617920d91b0f01111a8479da69f00e2cf27792 \
    https://github.com/ultralytics/assets/releases/download/v8.4.0/yolov8s-worldv2.pt \
    /app/weights/yolov8s-worldv2.pt
ADD --checksum=sha256:94375f988270836169320bd901960c67b5770e8bef3867d70102f01a8b5ca501 \
    https://github.com/ultralytics/assets/releases/download/v8.4.0/sam2_t.pt \
    /app/weights/sam2_t.pt
ADD --checksum=sha256:40d365715913c9da98579312b702a82c18be219cc2a73407c4526f58eba950af \
    https://openaipublic.azureedge.net/clip/models/40d365715913c9da98579312b702a82c18be219cc2a73407c4526f58eba950af/ViT-B-32.pt \
    /app/weights/clip/ViT-B-32.pt

COPY dinov2_service.py /app/dinov2_service.py
COPY segment_products.py /app/segment_products.py

# Evidence, not runtime: reproduces the precision@10 numbers in the design doc.
COPY product_recognition_benchmark.py /app/product_recognition_benchmark.py
COPY product_recognition_check.py /app/product_recognition_check.py
WORKDIR /app
# First /segment must not pip-install extras or download weights into /app.
ENV YOLO_OFFLINE=true YOLO_AUTOINSTALL=false \
    YOLO_WEIGHTS=/app/weights/yolov8s-worldv2.pt \
    SAM_WEIGHTS=/app/weights/sam2_t.pt

EXPOSE 8001
CMD ["python", "dinov2_service.py", "--port", "8001"]
