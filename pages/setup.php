<?php
include "../../../include/boot.php";
include "../../../include/authenticate.php";
if (!checkperm("a")) {
    exit($lang["error-permissiondenied"]);
}
include_once dirname(__FILE__, 2) . "/include/product_recognition_functions.php";
include_once dirname(__FILE__, 2) . "/include/product_recognition_setup.php";

$plugin_name = "product_recognition";
$page_heading = $lang["product_recognition_setup"];
$page_intro = "<p>" . escape($lang["product_recognition_setup_intro"]) . "</p>"
    . "<p>" . escape($lang["product_recognition_setup_intro_defaults"]) . "</p>";
$built = pr_setup_page_def();
$page_def = $built['page_def'];
$never_list_at = $built['never_list_at'];

$review_url = generateURL($baseurl . "/plugins/product_recognition/pages/review.php");
$catalog_url = generateURL($baseurl . "/plugins/product_recognition/pages/catalog.php");
$page_def[] = config_add_html(
    "<div class=\"Question\"><label>" . escape($lang["product_recognition_setup_pages"]) . "</label>"
    . "<p><a href=\"" . escape($review_url) . "\">" . escape($lang["product_recognition_review"]) . "</a>"
    . " · <a href=\"" . escape($catalog_url) . "\">" . escape($lang["product_recognition_catalog"]) . "</a></p>"
    . "<div class=\"clearerleft\"></div></div>"
);

config_gen_setup_post($page_def, $plugin_name);
$never_seen = [];
$never_labels = [];
$never_raw = pr_never_auto_apply_default() . ', '
    . (string)($GLOBALS['product_recognition_never_auto_apply'] ?? '');
foreach (preg_split('/[,;\r\n]+/', $never_raw) ?: [] as $never_part) {
    $never_part = trim((string)$never_part);
    $never_fold = pr_fold_label($never_part);
    if ($never_fold === '' || isset($never_seen[$never_fold])) {
        continue;
    }
    $never_seen[$never_fold] = true;
    $never_labels[] = $never_part;
}
$page_def[$never_list_at] = config_add_html(
    "<div class=\"Question\"><label>" . escape($lang["product_recognition_setup_never_list_label"]) . "</label>"
    . "<p>" . escape(implode(', ', $never_labels)) . "</p>"
    . "<p>" . escape($lang["product_recognition_setup_never_list_fixed"]) . "</p>"
    . "<div class=\"clearerleft\"></div></div>"
);
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
