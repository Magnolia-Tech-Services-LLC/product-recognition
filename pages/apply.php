<?php
/**
 * Panel apply. Replaces Name, colorway, and collaboration when she picks a different value.
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
$model_text = "";
$colorway_text = "";
if (enforcePostRequest(false)) {
    $model_text = trim(getval("model_text", ""));
    $colorway_text = trim(getval("colorway_text", ""));
    $collab_text = trim(getval("collab_text", ""));
    $plan = pr_apply_catalog_pick(
        $resource,
        $model_text,
        $colorway_text,
        (int)$userref,
        pr_catalog_picker_rows(),
        $collab_text,
        (int)getval("region", 0)
    );
    $model_changed = $plan["add_model"] || $plan["replace_model"];
    $color_changed = $plan["add_colorway"] || $plan["replace_colorway"];
    $collab_changed = $plan["add_collab"] || $plan["replace_collab"];
    if ($model_changed) {
        $status = ($color_changed || $collab_changed) ? "applied" : "model";
    } elseif ($color_changed && $collab_changed) {
        $status = "applied";
    } elseif ($color_changed) {
        $status = "colorway";
    } elseif ($collab_changed) {
        $status = "collab";
    } elseif ($model_text === "" && $colorway_text === "" && $collab_text === "") {
        $status = "none";
    } else {
        $status = "kept";
    }
}

# A photo with exactly one current cutout and both values set: that is a person's answer for
# the bag, so it is also saved as a seed (product_bag_tag) for the tag-one-bag matcher. The form
# carries the file and box the person was looking at; if that is no longer the cutout, nothing is saved.
$bag_status = "";
if ($model_text !== "" && $colorway_text !== "" && pr_bag_enabled() && pr_stored_region_count($resource) === 1) {
    $bag_status = pr_bag_save_tag(
        $resource,
        (int)getval("region", 0),
        $model_text,
        $colorway_text,
        (int)$userref,
        (string)getval("file_checksum", ""),
        (string)getval("bbox", "")
    );
}

$view_params = ["ref" => $resource, "pr_apply" => $status];
if ($bag_status !== "") {
    $view_params["pr_bag"] = $bag_status;
}
if (getval("modal", "") === "true") {
    $view_params["modal"] = "true";
}
redirect(generateURL($baseurl . "/pages/view.php", $view_params));
