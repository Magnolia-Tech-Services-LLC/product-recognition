<?php
/**
 * Read-only spreadsheet view of product_catalog. Inspection, not a PIM.
 */
include "../../../include/boot.php";
include "../../../include/authenticate.php";

if (!checkperm('product_recognition_catalog') && !checkperm('product_recognition_accept')) {
    exit($lang["error-permissiondenied"]);
}

$catalog_url = $baseurl . "/plugins/product_recognition/pages/catalog.php";
$csv_columns = array(
    "sku" => $lang["product_recognition_catalog_sku"],
    "section" => $lang["product_recognition_catalog_section"],
    "product" => $lang["product_recognition_catalog_product"],
    "model_text" => $lang["product_recognition_catalog_sheet_model"],
    "colorway_text" => $lang["product_recognition_catalog_sheet_colorway"],
    "upc" => $lang["product_recognition_catalog_upc"],
    "description" => $lang["product_recognition_catalog_description"],
    "source_resource" => $lang["product_recognition_catalog_source"],
    "updated" => $lang["product_recognition_catalog_updated"],
);
$grid_columns = $csv_columns;
unset($grid_columns["description"]);

$section = trim(getval("section", ""));
$q = trim(getval("q", ""));
$sort = getval("sort", "");
$order = (strtolower(getval("order", "")) === "desc") ? "desc" : "asc";
$export_csv = (getval("export", "") === "csv");

if (!isset($grid_columns[$sort])) {
    $sort = "section";
    $order = "asc";
}
$dir = ($order === "desc") ? "DESC" : "ASC";
$order_sql = $sort . " " . $dir;
if ($sort !== "sku") {
    $order_sql .= ", sku ASC";
}

$where = array();
$params = array();
if ($section !== "") {
    $where[] = "section = ?";
    $params[] = "s";
    $params[] = $section;
}
if ($q !== "") {
    $search_cols = array("sku", "product", "upc", "model_text", "colorway_text");
    $likes = array();
    $like = "%" . $q . "%";
    foreach ($search_cols as $col) {
        $likes[] = $col . " LIKE ?";
        $params[] = "s";
        $params[] = $like;
    }
    $where[] = "(" . implode(" OR ", $likes) . ")";
}
$sql = "SELECT " . implode(", ", array_keys($csv_columns)) . "
          FROM product_catalog";
if ($where !== array()) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY " . $order_sql;

$rows = ps_query($sql, $params);

if ($export_csv) {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"product_catalog_" . date("Y-m-d") . ".csv\"");
    $out = fopen("php://output", "w");
    fputcsv($out, array_values($csv_columns));
    foreach ($rows as $row) {
        $line = array();
        foreach (array_keys($csv_columns) as $col) {
            $value = (string)($row[$col] ?? "");
            if ($col === "upc") {
                $value = trim($value);
                if ($value !== "") {
                    $value = "\t" . $value;
                }
            }
            $line[] = $value;
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

$totals = ps_query("SELECT COUNT(*) AS sku_count,
                           COUNT(DISTINCT section) AS section_count,
                           COUNT(DISTINCT IF(source_resource > 0, source_resource, NULL)) AS source_count,
                           MIN(IF(source_resource > 0, source_resource, NULL)) AS source_min,
                           MAX(updated) AS last_change
                      FROM product_catalog");
$sku_count = (int)$totals[0]["sku_count"];
$section_count = (int)$totals[0]["section_count"];
$source_count = (int)$totals[0]["source_count"];
$source_ref = (int)$totals[0]["source_min"];
$last_change = (string)($totals[0]["last_change"] ?? "");

$sections = ps_array("SELECT DISTINCT section AS value FROM product_catalog ORDER BY section ASC", array());

$filter_params = array();
if ($section !== "") {
    $filter_params["section"] = $section;
}
if ($q !== "") {
    $filter_params["q"] = $q;
}
$keep_params = array_merge($filter_params, array("sort" => $sort, "order" => $order));

include "../../../include/header.php";
?>
<div class="BasicsBox">
<h1><?php echo escape($lang["product_recognition_catalog"]); ?></h1>
<p><?php echo escape($lang["product_recognition_catalog_intro"]); ?></p>
<?php if (checkperm("product_recognition_accept")) { ?>
<p><a href="<?php echo escape(generateURL($baseurl . "/plugins/product_recognition/pages/review.php")); ?>"><?php echo escape($lang["product_recognition_review"]); ?></a></p>
<?php }
if ($source_count === 1 && $last_change !== "") {
    $stats = sprintf($lang["product_recognition_catalog_stats"], $sku_count, $section_count, $source_ref, $last_change);
} elseif ($source_count > 1 && $last_change !== "") {
    $stats = sprintf($lang["product_recognition_catalog_stats_mixed"], $sku_count, $section_count, $source_count, $last_change);
} else {
    $stats = sprintf($lang["product_recognition_catalog_stats_nosource"], $sku_count, $section_count);
}
echo "<p>" . escape($stats) . "</p>";
?>
<form method="get" action="<?php echo escape(generateURL($catalog_url)); ?>">
<input type="hidden" name="sort" value="<?php echo escape($sort); ?>" />
<input type="hidden" name="order" value="<?php echo escape($order); ?>" />
<select name="section">
<option value=""><?php echo escape($lang["product_recognition_catalog_section_all"]); ?></option>
<?php foreach ($sections as $section_name) { ?>
<option value="<?php echo escape($section_name); ?>"<?php if ($section_name === $section) { echo " selected"; } ?>><?php echo escape($section_name); ?></option>
<?php } ?>
</select>
<input type="text" name="q" value="<?php echo escape($q); ?>" />
<input type="submit" value="<?php echo escape($lang["product_recognition_catalog_search"]); ?>" />
</form>
<p><a href="<?php echo escape(generateURL($catalog_url, array_merge($keep_params, array("export" => "csv")))); ?>"><?php echo escape($lang["product_recognition_catalog_export"]); ?></a></p>
<?php if ($sku_count === 0) { ?>
<p><?php echo escape($lang["product_recognition_catalog_empty"]); ?></p>
<?php } elseif ($rows === array()) { ?>
<p><?php echo escape($lang["product_recognition_catalog_none_match"]); ?></p>
<?php } else { ?>
<div class="Listview">
<table class="ListviewStyle">
<tr class="ListviewTitleStyle">
<?php
foreach ($grid_columns as $col => $label) {
    $next_order = ($sort === $col && $order === "asc") ? "desc" : "asc";
    $sort_url = generateURL($catalog_url, array_merge($filter_params, array("sort" => $col, "order" => $next_order)));
    echo "<th><a href=\"" . escape($sort_url) . "\">" . escape($label) . "</a></th>";
}
?>
</tr>
<?php
$source_labels = array();
foreach ($rows as $row) {
    $upc = trim((string)($row["upc"] ?? ""));
    $src = (int)$row["source_resource"];
    ?>
<tr>
<td><?php echo escape($row["sku"]); ?></td>
<td><?php echo escape($row["section"]); ?></td>
<td><?php echo escape($row["product"]); ?></td>
<td><?php echo escape($row["model_text"]); ?></td>
<td><?php echo escape($row["colorway_text"]); ?></td>
<td><?php echo ($upc === "") ? "—" : escape($upc); ?></td>
<td><?php
    if ($src > 0) {
        if (!isset($source_labels[$src])) {
            $title = trim(i18n_get_translated((string)get_data_by_field($src, (int)($view_title_field ?? 8))));
            $source_labels[$src] = ($title !== "") ? $title : ("#" . $src);
        }
        echo "<a href=\"" . escape(generateURL($baseurl . "/pages/view.php", array("ref" => $src))) . "\" target=\"_blank\">" . escape($source_labels[$src]) . "</a>";
    }
?></td>
<td><?php echo escape((string)($row["updated"] ?? "")); ?></td>
</tr>
<?php
}
?>
</table>
</div>
<?php } ?>
</div>
<?php
include "../../../include/footer.php";
