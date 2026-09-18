<?php

# Plugin functions are not auto-loaded; the clip plugin includes its own the same way
# (plugins/clip/hooks/all.php:5).
include_once dirname(__FILE__, 2) . '/include/product_recognition_functions.php';

/**
 * Nightly: references, embedding slice, calibration, classification - under one lock.
 */
function HookProduct_recognitionAllCron()
{
    if (!pr_is_configured()) {
        logScript("product_recognition: product field not configured; skip");
        return;
    }

    try {
        $result = pr_run_all();
    } catch (Throwable $e) {
        # Caught deliberately, and NOT rethrown. batch/cron.php has no try/catch of its own, and
        # hook() dispatches every plugin's cron hook in one bare loop - so an exception escaping
        # here kills the run before it clears ResourceSpace's own "cron" process lock, before
        # last_cron advances, and before any plugin ordered after this one gets its nightly run.
        # Our stages throw on ordinary operational conditions (the vector service being down is
        # one), and costing the whole install its cron night for that is the wrong trade.
        # Failing loudly in the log is visible; taking RS's cron down with us is not.
        logScript("product_recognition: nightly run FAILED: " . $e->getMessage());
        return;
    }

    if (!empty($result['locked'])) {
        return;
    }
    logScript(sprintf(
        "product_recognition: %d references, %d embedded (%d outstanding), %d suggested, %d auto-applied",
        $result['references']['derived'], $result['backfill']['embedded'],
        $result['backfill']['remaining'], $result['classification']['suggested'],
        $result['classification']['auto_applied']
    ));

    # Vocabulary damage gets its own line, and only when there is some. The cron is the ONLY
    # unattended path, so anything not logged here is invisible until a person runs the CLI -
    # which is how 26 orphaned suggestions sat unnoticed on 2026-08-07.
    #
    # This guard is deliberately narrower than the CLI's, which also reports the standing backlog
    # of orphaned rows for an operator asking directly. The cron speaks only when something
    # changed this run. Do not "fix" the asymmetry.
    $refs = $result['references'];
    $rec = $refs['reconciled'];
    if (pr_reconcile_total($rec) > 0 || $refs['revived'] !== []) {
        logScript(sprintf(
            "product_recognition: deleted product nodes orphaned %d threshold, %d pinned reference, "
            . "%d suggestion; revived %d reference(s) now re-tagged",
            $rec['thresholds'], $rec['references'], $rec['suggestions'], count($refs['revived'])
        ));
        foreach ($refs['revived'] as $r) {
            logScript("product_recognition: " . pr_format_revived($r));
        }
    }
}

/**
 * On upload: embed immediately, so a new shoot is recognisable before the next nightly run.
 *
 * Embedding only - classification stays on the cron, because a suggestion is only as good as
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
    if ($field_ok) {
        $out["product_recognition_service"] = [
            "status" => pr_service_health() ? "OK" : "FAIL",
            "info" => $lang["product_recognition_check_service"],
        ];
    }
    return $out;
}
