<?php
/**
 * Explicit Pin / Exclude from the resource view panel.
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
    $action = trim((string)getval("action", ""));
    $region = (int)getval("region", 0);
    if ($action === "exclude") {
        $status = pr_operator_exclude($resource, (int)$userref);
    } elseif ($action === "pin") {
        $model_text = trim((string)getval("model_text", ""));
        $leaves = pr_leaf_models();
        $model_node = pr_bind_catalog_model($model_text, $leaves);
        if ($model_node === null) {
            $tagged = pr_resource_model_leaves($resource);
            $model_node = count($tagged) === 1 ? $tagged[0] : 0;
        }
        $status = pr_operator_pin($resource, (int)$model_node, (int)$userref, $region);
    }
}

$view_params = ["ref" => $resource, "pr_ref" => $status];
if (getval("modal", "") === "true") {
    $view_params["modal"] = "true";
}
redirect(generateURL($baseurl . "/pages/view.php", $view_params));
