<?php
/**
 * Serves the transparent product cutout for the view-page panel.
 * Filestore originals are deny-default; this is the authenticated path.
 */
include '../../../include/boot.php';
include_once dirname(__FILE__, 2) . '/include/product_recognition_functions.php';

$ref = (int)getval('ref', 0);
$k = getval('k', '');
if ($ref <= 0 || get_resource_data($ref) === false) {
    http_response_code(404);
    exit;
}
if ($k !== '') {
    if (!check_access_key($ref, $k)) {
        http_response_code(403);
        exit;
    }
} else {
    include '../../../include/authenticate.php';
    if (get_resource_access($ref) > 1) {
        http_response_code(403);
        exit;
    }
}

$i = (int)getval('i', 0);
$boxes = pr_ensure_regions($ref);
$resource = get_resource_data($ref);
$mask = pr_mask_file($ref, (string)$resource['file_checksum'], $i);
if (!isset($boxes[$i]) || !file_exists($mask)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . (string)filesize($mask));
readfile($mask);
exit;
