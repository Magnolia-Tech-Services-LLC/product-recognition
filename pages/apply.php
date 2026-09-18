<?php
/**
 * Catalog picker apply from the resource view panel. Add-only Model / Colorway.
 */
include "../../../include/boot.php";
include "../../../include/authenticate.php";
include_once dirname(__FILE__, 2) . "/include/product_recognition_functions.php";

if (!checkperm('product_recognition_accept')) {
    exit($lang["error-permissiondenied"]);
}

$resource = (int)getval("resource", 0);
if ($resource <= 0 || !get_edit_access($resource)) {
    exit($lang["error-permissiondenied"]);
}
$status = "none";
if (enforcePostRequest(false)) {
    $model_text = trim(getval("model_text", ""));
    $colorway_text = trim(getval("colorway_text", ""));
    $plan = pr_apply_catalog_pick(
        $resource,
        $model_text,
        $colorway_text,
        (int)$userref,
        pr_catalog_picker_rows()
    );
    if ($plan["add_model"]) {
        $status = $plan["add_colorway"] ? "applied" : "model";
    } elseif ($plan["add_colorway"]) {
        $status = "colorway";
    } elseif ($plan["sku"] === "") {
        $status = "none";
    } else {
        $status = "kept";
    }
}

redirect(generateURL($baseurl . "/pages/view.php", array("ref" => $resource, "pr_apply" => $status)));
