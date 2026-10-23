<?php
include "../../../include/boot.php";
include "../../../include/authenticate.php";
if (!checkperm("a")) {
    exit($lang["error-permissiondenied"]);
}
include_once dirname(__FILE__, 2) . "/include/product_recognition_functions.php";

$plugin_name = "product_recognition";
$page_heading = $lang["product_recognition_setup"];
$page_intro = "<p>" . escape($lang["product_recognition_setup_intro"]) . "</p>"
    . "<p>" . escape($lang["product_recognition_setup_intro_defaults"]) . "</p>";
$page_def = [];

$page_def[] = config_add_section_header(
    $lang["product_recognition_setup_fields"],
    $lang["product_recognition_setup_fields_desc"]
);
$product_field_types = [FIELD_TYPE_CATEGORY_TREE];
if (defined('FIELD_TYPE_DYNAMIC_KEYWORDS_LIST')) {
    $product_field_types[] = FIELD_TYPE_DYNAMIC_KEYWORDS_LIST;
}
$page_def[] = config_add_single_ftype_select(
    "product_recognition_field",
    $lang["product_recognition_setup_product_field"],
    300,
    false,
    $product_field_types
);
# Colorway is a node field, not necessarily a tree (dynamic keywords is type 9).
$page_def[] = config_add_single_ftype_select(
    "product_recognition_colorway_field",
    $lang["product_recognition_setup_colorway_field"],
    300,
    false,
    $FIXED_LIST_FIELD_TYPES
);
$page_def[] = config_add_single_ftype_select(
    "product_recognition_collab_field",
    $lang["product_recognition_setup_collab_field"],
    300,
    false,
    $FIXED_LIST_FIELD_TYPES
);
$page_def[] = config_add_multi_rtype_select(
    "product_recognition_resource_types",
    $lang["product_recognition_setup_resource_types"]
);

$page_def[] = config_add_section_header(
    $lang["product_recognition_setup_service"],
    $lang["product_recognition_setup_service_desc"]
);
$page_def[] = config_add_text_input(
    "product_recognition_service_url",
    $lang["product_recognition_setup_service_url"],
    false,
    420,
    false,
    $lang["product_recognition_setup_service_url_help"]
);
$page_def[] = config_add_text_input(
    "product_recognition_api_key",
    $lang["product_recognition_setup_api_key"],
    true,
    420,
    false,
    $lang["product_recognition_setup_api_key_help"]
);
$page_def[] = config_add_text_input(
    "product_recognition_segment_classes",
    $lang["product_recognition_setup_segment_classes"],
    false,
    420,
    false,
    $lang["product_recognition_setup_segment_classes_help"]
);

$page_def[] = config_add_section_header(
    $lang["product_recognition_setup_pipeline"],
    $lang["product_recognition_setup_pipeline_desc"]
);
$page_def[] = config_add_boolean_select(
    "product_recognition_auto_apply",
    $lang["product_recognition_setup_auto_apply"]
);
$page_def[] = config_add_html(
    "<div class=\"Question\"><p>" . escape($lang["product_recognition_setup_auto_apply_help"])
    . "</p><div class=\"clearerleft\"></div></div>"
);
$page_def[] = config_add_text_input(
    "product_recognition_k",
    $lang["product_recognition_setup_k"],
    false,
    420,
    false,
    $lang["product_recognition_setup_k_help"]
);
$page_def[] = config_add_text_input(
    "product_recognition_min_similarity",
    $lang["product_recognition_setup_min_similarity"],
    false,
    420,
    false,
    $lang["product_recognition_setup_min_similarity_help"]
);
$page_def[] = config_add_text_input(
    "product_recognition_target_precision",
    $lang["product_recognition_setup_target_precision"],
    false,
    420,
    false,
    $lang["product_recognition_setup_target_precision_help"]
);
$page_def[] = config_add_text_input(
    "product_recognition_never_auto_apply",
    $lang["product_recognition_setup_never_auto_apply"],
    false,
    420,
    false,
    $lang["product_recognition_setup_never_auto_apply_help"]
);
$page_def[] = config_add_text_input(
    "product_recognition_batch",
    $lang["product_recognition_setup_batch"],
    false,
    420,
    false,
    $lang["product_recognition_setup_batch_help"]
);
$page_def[] = config_add_text_input(
    "product_recognition_classify_batch",
    $lang["product_recognition_setup_classify_batch"],
    false,
    420,
    false,
    $lang["product_recognition_setup_classify_batch_help"]
);
$page_def[] = config_add_text_input(
    "product_recognition_catalog_title_match",
    $lang["product_recognition_setup_catalog_title_match"],
    false,
    420,
    false,
    $lang["product_recognition_setup_catalog_title_match_help"]
);

$review_url = generateURL($baseurl . "/plugins/product_recognition/pages/review.php");
$catalog_url = generateURL($baseurl . "/plugins/product_recognition/pages/catalog.php");
$page_def[] = config_add_html(
    "<div class=\"Question\"><label>" . escape($lang["product_recognition_setup_pages"]) . "</label>"
    . "<p><a href=\"" . escape($review_url) . "\">" . escape($lang["product_recognition_review"]) . "</a>"
    . " · <a href=\"" . escape($catalog_url) . "\">" . escape($lang["product_recognition_catalog"]) . "</a></p>"
    . "<div class=\"clearerleft\"></div></div>"
);

config_gen_setup_post($page_def, $plugin_name);
if (!pr_is_configured()) {
    array_unshift($page_def, config_add_html(
        "<div class=\"PageInfoMessage\"><p><strong>"
        . escape($lang["product_recognition_setup_required"])
        . "</strong></p></div>"
    ));
}
include "../../../include/header.php";
config_gen_setup_html($page_def, $plugin_name, null, $page_heading, $page_intro);
include "../../../include/footer.php";
