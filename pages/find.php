<?php
/**
 * Find other images of the picked catalog product. Lands in RS search as !list refs
 * from that model's reference set (not a live /identify of the crop).
 */
include "../../../include/boot.php";
include "../../../include/authenticate.php";
include_once dirname(__FILE__, 2) . "/include/product_recognition_functions.php";

if (!checkperm('product_recognition_accept')) {
    exit($lang["error-permissiondenied"]);
}

$resource = (int)getval("resource", 0);
# Visible Model select (GET from the picker form). Do not read a hidden SKU or identify node.
$model_text = trim(getval("model_text", ""));

if ($resource <= 0 || get_resource_access($resource) > 1) {
    exit($lang["error-permissiondenied"]);
}

$model_node = $model_text === ""
    ? 0
    : (pr_bind_catalog_model($model_text, pr_leaf_models()) ?? 0);

$refs = pr_other_match_refs($model_node, $resource);
$token = pr_other_match_search_token($refs);
if ($token === "") {
    $empty_params = ["ref" => $resource, "pr_find" => "empty"];
    if (getval("modal", "") === "true") {
        $empty_params["modal"] = "true";
    }
    redirect(generateURL($baseurl . "/pages/view.php", $empty_params));
}
redirect(generateURL($baseurl . "/pages/search.php", array("search" => $token)));
