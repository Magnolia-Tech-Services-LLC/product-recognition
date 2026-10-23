<?php
/**
 * Find other images of the picked product. Family-only does not bind the family
 * string as a Name. The search ANDs every product field the pick sets.
 */
include "../../../include/boot.php";
include "../../../include/authenticate.php";
include_once dirname(__FILE__, 2) . "/include/product_recognition_functions.php";

if (!checkperm('product_recognition_accept')) {
    exit($lang["error-permissiondenied"]);
}

$resource = (int)getval("resource", 0);
# Picker fields. Family-only leaves sub-class empty so the family string is not a Name.
$family = trim(getval("family", ""));
$subclass = trim(getval("subclass", ""));
$subclass2 = trim(getval("subclass2", ""));
$colorway = trim(getval("colorway_text", ""));
$model_text = trim(getval("model_text", ""));

if ($resource <= 0 || get_resource_access($resource) > 1) {
    exit($lang["error-permissiondenied"]);
}

$plan = pr_find_field_plan($family, $subclass, $subclass2, $colorway, $model_text);
$token = pr_product_field_search($plan);
if ($token === "") {
    $empty_params = ["ref" => $resource, "pr_find" => "empty"];
    if (getval("modal", "") === "true") {
        $empty_params["modal"] = "true";
    }
    redirect(generateURL($baseurl . "/pages/view.php", $empty_params));
}
redirect(generateURL($baseurl . "/pages/search.php", array("search" => $token)));
