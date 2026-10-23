<?php
/**
 * Grammar and path fixtures. No ResourceSpace boot, no network, no writes.
 *
 *   php plugins/product_recognition/scripts/product_recognition_sheet_check.php
 */
if (PHP_SAPI !== 'cli') {
    return;
}
require dirname(__DIR__) . '/include/product_recognition_functions.php';
$fail = pr_sheet_fixture_failure();
if ($fail !== '') {
    fwrite(STDERR, $fail . "\n");
    exit(1);
}
echo "ok\n";
