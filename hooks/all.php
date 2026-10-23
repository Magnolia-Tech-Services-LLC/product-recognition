<?php

# Plugin functions are not auto-loaded; the clip plugin includes its own the same way
# (plugins/clip/hooks/all.php:5).
include_once dirname(__FILE__, 2) . '/include/product_recognition_functions.php';

/**
 * Nightly: sheet import, studio seed, references, embedding slice, calibration,
 * classification - under one lock.
 */
function HookProduct_recognitionAllCron()
{
    # The night shift is product-recognition.timer (CLI --all), not this hook.
    # cron.php shares one process and one 30-minute cap with disk measurement.
    # A warning there exits before this hook, and the shell then waits 24 hours.
    logScript("product_recognition: nightly pass runs from product-recognition.timer, not cron.php");
}

/**
 * On upload: embed immediately, so a new shoot is recognisable before the next nightly run.
 *
 * Embedding only - classification stays on product-recognition.timer, because a suggestion is only as good as
 * the calibration behind it and calibration is a batch job. Mirrors the clip plugin's
 * HookClipAllAfterpreviewcreation.
 */
function HookProduct_recognitionAllAfterpreviewcreation(int $resource, int $alternative,
                                                        bool $generate_all = false): void
{
    global $lang;

    if ($alternative !== -1) {
        return;                       # alternatives are not the resource
    }
    if (!pr_is_configured()) {
        return;
    }
    $resource_data = get_resource_data($resource);
    if ($resource_data === false) {
        return;
    }
    $types = pr_resource_types();
    if ($types === [] || !in_array($resource_data["resource_type"], $types)) {
        return;
    }
    set_processing_message($lang["product_recognition_generating"] . " " . $resource);
    pr_embed_resource($resource);
    pr_ensure_region($resource);
}

/**
 * Deleting a resource takes its vector, its reference row and its suggestions with it.
 * A vector for a resource that no longer exists is a neighbour that can never be inspected.
 */
function HookProduct_recognitionAllBeforedeleteresourcefromdb($ref)
{
    ps_query('DELETE FROM resource_dinov2_vector WHERE resource = ?', ['i', $ref]);
    ps_query('DELETE FROM product_reference WHERE resource = ?', ['i', $ref]);
    ps_query('DELETE FROM product_suggestion WHERE resource = ?', ['i', $ref]);
    ps_query('DELETE FROM product_region WHERE resource = ?', ['i', $ref]);
    pr_purge_masks((int)$ref);
}

/**
 * System status: fail loudly if the product field is unset or the sidecar is down.
 *
 * @return array<string, array{status:string, info:string}>
 */
function HookProduct_recognitionAllExtra_checks(): array
{
    global $lang;

    $field_ok = pr_is_configured();
    $out = [
        "product_recognition_field" => [
            "status" => $field_ok ? "OK" : "FAIL",
            "info" => $field_ok
                ? $lang["product_recognition_check_field"]
                : $lang["product_recognition_setup_required"],
        ],
    ];
    if (pr_pending_tree_field() > 0) {
        $out["product_recognition_family_migrate"] = [
            "status" => "FAIL",
            "info" => $lang["product_recognition_check_migrate"],
        ];
    }
    if ($field_ok) {
        $out["product_recognition_service"] = [
            "status" => pr_service_health() ? "OK" : "FAIL",
            "info" => $lang["product_recognition_check_service"],
        ];
    }
    return $out;
}
