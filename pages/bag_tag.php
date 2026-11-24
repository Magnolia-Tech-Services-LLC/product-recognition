<?php
/**
 * Save the Product and Colorway a person set on one cutout as a seed bag (product_bag_tag).
 * Writes only that row: the photo's own tags are the panel Apply button's job.
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

$status = "failed";
if (enforcePostRequest(false)) {
    $status = pr_bag_save_tag(
        $resource,
        (int)getval("region", 0),
        trim((string)getval("model_text", "")),
        trim((string)getval("colorway_text", "")),
        (int)$userref
    );
}

$view_params = ["ref" => $resource, "pr_bag" => $status];
if (getval("modal", "") === "true") {
    $view_params["modal"] = "true";
}
redirect(generateURL($baseurl . "/pages/view.php", $view_params));
