"""
Measures product-recognition retrieval quality on this library's own images.

    docker compose exec clip python /app/product_recognition_benchmark.py --model dinov2_vitb14
    docker compose exec clip python /app/product_recognition_benchmark.py --model clip
    docker compose exec clip python /app/product_recognition_benchmark.py --model clip --queries 40

Metric: of the top K nearest neighbours of a query image, how many share the query's product.
Both models are measured over the SAME pool and the SAME queries, because an earlier
comparison did not and the pools differed by ~4% of top-10 slots.

The pool is the "leaf reference set": every resource whose most specific product node is a
leaf of the field. That includes parent+leaf resources that an "exactly one node" filter
silently drops.
"""
import argparse
import os
import sys
import time

import mysql.connector
import numpy as np
import torch
from PIL import Image

_raw_field = os.getenv("PR_FIELD")
if not _raw_field:
    sys.exit("product_recognition_benchmark: set PR_FIELD to the product category-tree field ref")
MODEL_FIELD = int(_raw_field)

DB_CONFIG = {
    "host": os.getenv("CLIP_DB_HOST", "mysql"),
    "user": os.getenv("CLIP_DB_USER"),
    "password": os.getenv("CLIP_DB_PASS"),
    "database": os.getenv("CLIP_DB_NAME", "resourcespace"),
}

# The leaf reference rule, in one query. Two conditions, in the spec's own order:
#   1. the node is the resource's MOST SPECIFIC product tag - no other node on this same
#      resource sits below it. This is what recovers the parent+leaf resources.
#   2. that node is a LEAF OF THE FIELD - it has no children at all. A resource whose most
#      specific tag is still a branch names a category, not a product.
# Resources left with anything other than exactly one such node are not references.
REFERENCE_SQL = """
WITH RECURSIVE closure AS (
    SELECT ref AS ancestor, ref AS descendant FROM node WHERE resource_type_field = %s
    UNION ALL
    SELECT c.ancestor, n.ref FROM closure c JOIN node n ON n.parent = c.descendant
),
-- MySQL 9.7 reserves the word SPECIFIC (SQL:2016), so this CTE cannot be named that -
-- a bare "WITH ... specific AS (" is a syntax error on this server, not a typo elsewhere.
candidate AS (
    SELECT rn.resource, rn.node
    FROM resource_node rn
    JOIN node nd ON nd.ref = rn.node AND nd.resource_type_field = %s
    WHERE NOT EXISTS (
        SELECT 1 FROM resource_node rn2
        JOIN closure c ON c.ancestor = rn.node AND c.descendant = rn2.node
        WHERE rn2.resource = rn.resource AND rn2.node <> rn.node
    )
    AND NOT EXISTS (
        SELECT 1 FROM node ch WHERE ch.parent = rn.node
    )
)
SELECT resource, MIN(node) AS node
FROM candidate
GROUP BY resource
HAVING COUNT(*) = 1
ORDER BY resource
"""


def references(cursor):
    """resource -> leaf model node, for every resource that qualifies as a reference."""
    cursor.execute(REFERENCE_SQL, (MODEL_FIELD, MODEL_FIELD))
    return {int(r): int(n) for r, n in cursor.fetchall()}


def load_paths(path_file):
    """
    resource -> absolute preview path, produced by product_recognition_cli.php --paths.

    ResourceSpace resolves its own filestore layout (get_resource_path); guessing it here
    would break the moment $filestore_evenspread or the scramble key changed.
    """
    paths = {}
    with open(path_file) as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            ref, path = line.split("\t", 1)
            paths[int(ref)] = path
    return paths


def build_clip():
    import clip

    model, preprocess = clip.load("ViT-B/32", device="cpu")
    model.eval()

    def encode(img):
        with torch.no_grad():
            v = model.encode_image(preprocess(img).unsqueeze(0))
        return v.cpu().numpy().flatten()

    return encode


def build_dinov2(name):
    import torchvision.transforms as T

    model = torch.hub.load("facebookresearch/dinov2", name)
    model.eval()
    transform = T.Compose([
        T.Resize(256, interpolation=T.InterpolationMode.BICUBIC),
        T.CenterCrop(224),
        T.ToTensor(),
        T.Normalize((0.485, 0.456, 0.406), (0.229, 0.224, 0.225)),
    ])

    def encode(img):
        with torch.no_grad():
            v = model(transform(img).unsqueeze(0))
        return v.cpu().numpy().flatten()

    return encode


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--model", default="dinov2_vitb14",
                    help="clip | dinov2_vits14 | dinov2_vitb14 | dinov2_vitl14")
    ap.add_argument("--paths-from", default="/tmp/pr_paths.tsv",
                    help="TSV of resource<TAB>preview path, from the CLI's --paths mode")
    ap.add_argument("--queries", type=int, default=40)
    ap.add_argument("--k", type=int, default=10)
    args = ap.parse_args()

    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor()
    refs = references(cursor)
    conn.close()

    paths = load_paths(args.paths_from)
    pool = [r for r in sorted(refs) if r in paths and os.path.exists(paths[r])]
    print(f"Reference pool: {len(pool)} resources, "
          f"{len(set(refs[r] for r in pool))} distinct leaf models")
    missing = len(refs) - len(pool)
    if missing:
        print(f"  ({missing} references skipped: no preview on disk)")

    encode = build_clip() if args.model == "clip" else build_dinov2(args.model)

    start = time.time()
    vectors, kept = [], []
    for i, resource in enumerate(pool):
        try:
            img = Image.open(paths[resource]).convert("RGB")
        except Exception as exc:                       # one bad file must not abort a run
            print(f"  skipped {resource}: {exc}")
            continue
        v = encode(img)
        n = np.linalg.norm(v)
        if n == 0 or np.isnan(n):
            print(f"  skipped {resource}: degenerate vector")
            continue
        vectors.append(v / n)
        kept.append(resource)
        if (i + 1) % 200 == 0:
            print(f"  embedded {i + 1}/{len(pool)}")
    elapsed = time.time() - start
    print(f"Embedded {len(kept)} images in {elapsed / 60:.1f} min "
          f"({elapsed / max(len(kept), 1):.2f}s each)")

    matrix = np.stack(vectors).astype(np.float32)
    labels = np.array([refs[r] for r in kept])

    # Deterministic stride, so a re-run measures the same queries.
    stride = max(1, len(kept) // args.queries)
    query_idx = list(range(0, len(kept), stride))[:args.queries]

    hits = total = 0
    per_model = {}
    for qi in query_idx:
        sims = matrix @ matrix[qi]
        sims[qi] = -np.inf                              # never let a query retrieve itself
        top = np.argsort(-sims)[:args.k]
        correct = int((labels[top] == labels[qi]).sum())
        hits += correct
        total += len(top)
        m = int(labels[qi])
        agg = per_model.setdefault(m, [0, 0])
        agg[0] += correct
        agg[1] += len(top)

    print(f"\n{args.model}: precision@{args.k} = "
          f"{100.0 * hits / max(total, 1):.1f}% ({hits}/{total}) over {len(query_idx)} queries")
    print("\nper model (node ref, precision, n):")
    for node, (c, t) in sorted(per_model.items(), key=lambda kv: -kv[1][1]):
        print(f"  {node:6d}  {100.0 * c / t:5.1f}%  {t}")


if __name__ == "__main__":
    main()
