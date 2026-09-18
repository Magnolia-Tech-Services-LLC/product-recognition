<?php

# Companion FastAPI process (plugins/product_recognition/scripts/). Override per install
# on the plugin setup page or here. Docker Compose service name is a convention, not a
# requirement.
$product_recognition_service_url = "http://product-recognition:8001";
$product_recognition_api_key = "";
$product_recognition_space = "dinov2";

# Category-tree field whose leaf nodes are the products to recognise. 0 = not configured;
# the plugin is inert until an admin picks a field on the setup page. Recognition writes
# only through accept (or auto-apply); it never overwrites a value the field already has.
$product_recognition_field = 0;

# Optional second field shown on the resource view next to the product (color, variant).
# Any fixed-list type (category tree, dynamic keywords, checkbox, dropdown, radio).
# 0 = unused.
$product_recognition_colorway_field = 0;

# Resource types to embed and classify. 1 = Photo in a default RS install.
$product_recognition_resource_types = array(1, 3);

# Nearest neighbours per vote.
$product_recognition_k = 10;

# A neighbour this dissimilar means "nothing in the reference set looks like this".
$product_recognition_min_similarity = 0.5;

# Auto-apply target precision. A product needs ceil(1/(1-target)) references before the
# target is observable, so 0.95 implies 20 and 0.99 implies 100.
$product_recognition_target_precision = 0.95;

# Resources embedded per cron run. The position is stored in sysvars so a killed run
# resumes rather than restarting.
$product_recognition_batch = 5900;

# Suggestions to raise per cron run.
$product_recognition_classify_batch = 2000;

# With this false the pipeline still embeds, calibrates and suggests; nothing is written
# to a resource without a person clicking accept.
$product_recognition_auto_apply = false;

# Size/trim variants the pixels cannot see. Missing names are a no-op.
$product_recognition_never_auto_apply = "";

# Comma-separated YOLO-World class names for POST /segment (lifestyle localisation).
# Sent with each /segment request; the service also reads SEGMENT_CLASSES as a default.
$product_recognition_segment_classes = "product";

# Optional substring match against the title field to find a PDF product sheet for
# --catalog. Empty disables discovery (catalog ingest is then --resource=N only).
$product_recognition_catalog_title_match = "";
