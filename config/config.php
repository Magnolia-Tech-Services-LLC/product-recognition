<?php

# Companion FastAPI process (plugins/product_recognition/scripts/). Override per install
# on the plugin setup page or here. Docker Compose service name is a convention, not a
# requirement.
$product_recognition_service_url = "http://product-recognition:8001";
$product_recognition_api_key = "";
$product_recognition_space = "dinov2";

# Recognition bind. The setup page does not set this. pr_product_field() uses
# modelkeyword (Name) once leftover Model-tree and Family product_reference rows
# are gone. Until then it follows those rows. --migrate-family remaps them.
# Products come from the Master Product List, not from this value.
# The refresh reads that sheet with a service-account JSON at
# /opt/rs-defaults/google-sheets.json, or PRODUCT_RECOGNITION_SHEET_JSON.
# A missing file skips the refresh. Do not put the JSON in the web root.
# 0 = not configured. Writes never overwrite a value the field already has.
$product_recognition_field = 0;

# Optional second field shown on the resource view next to the product (color, variant).
# Any fixed-list type (category tree, dynamic keywords, checkbox, dropdown, radio).
# 0 = unused.
$product_recognition_colorway_field = 0;

# Optional Collaboration / Partner field (fixed list). 0 = discover by field name
# "collaboration" when present.
$product_recognition_collab_field = 0;

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
# Grow this list; never shorten it. Runtime also unions pr_never_auto_apply_default().
$product_recognition_never_auto_apply = "Loma XL, Big Rig S-Class, Ryder 14, Loma Lite, El Camino Superlite, Ryder Lefty, Storm Ryder, Loma S-Class, Loma XL S-Class, El Camino S-Class, Ryder S-Class, Ryder 14 S-Class";

# Comma-separated YOLO-World class names for POST /segment (lifestyle localisation).
# Sent with each /segment request; the service also reads SEGMENT_CLASSES as a default.
$product_recognition_segment_classes = "product";

# Optional substring match against the title field to find a PDF product sheet for
# --catalog. Empty disables discovery (catalog ingest is then --resource=N only).
$product_recognition_catalog_title_match = "";
