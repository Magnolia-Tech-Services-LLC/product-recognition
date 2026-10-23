<?php
/**
 * Pin campaign: promote clean single-cutout heroes into product_reference as pinned,
 * re-embedding from the mask PNG.
 *
 *   php pr_pin_campaign.php --dry-run
 *   php pr_pin_campaign.php --per-model=5 --models=20
 *   php pr_pin_campaign.php --calibrate   # after a live pin run
 */
include_once '/var/www/html/include/boot.php';
include_once '/var/www/html/plugins/product_recognition/include/product_recognition_functions.php';
command_line_only();

if (!pr_is_configured()) {
    fwrite(STDERR, "product_recognition: not configured\n");
    exit(1);
}

$dry_run = in_array('--dry-run', $argv, true);
$do_calibrate = in_array('--calibrate', $argv, true);
$per_model = 5;
$max_models = 20;
$user = 1;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--per-model=(\d+)$/', $arg, $m)) {
        $per_model = max(1, (int)$m[1]);
    }
    if (preg_match('/^--models=(\d+)$/', $arg, $m)) {
        $max_models = max(1, (int)$m[1]);
    }
    if (preg_match('/^--user=(\d+)$/', $arg, $m)) {
        $user = max(1, (int)$m[1]);
    }
}

$field = (int)$GLOBALS['product_recognition_field'];
$leaves = pr_leaf_models();

# Models with the most derived refs and fewest pins get priority (skip archive junk).
$models = ps_query(
    "SELECT pr.model_node,
            SUM(pr.status = 'derived') AS derived_n,
            SUM(pr.status = 'pinned') AS pinned_n
       FROM product_reference pr
       JOIN node n ON n.ref = pr.model_node
      WHERE pr.status IN ('derived','pinned')
        AND pr.model_node IN (" . ps_param_insert(count(array_keys($leaves))) . ")
        AND n.name NOT LIKE '\\_%'
        AND n.name NOT LIKE '%Archive%'
      GROUP BY pr.model_node
      HAVING pinned_n < ?
      ORDER BY derived_n DESC, pinned_n ASC
      LIMIT ?",
    array_merge(
        ps_param_fill(array_keys($leaves), 'i'),
        ['i', $per_model, 'i', $max_models]
    )
);

printf(
    "%s pin campaign: up to %d models × %d pins (user %d)\n",
    $dry_run ? 'DRY-RUN' : 'LIVE',
    $max_models,
    $per_model,
    $user
);

$stats = ['models' => 0, 'candidates' => 0, 'pinned' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($models as $mrow) {
    $model_node = (int)$mrow['model_node'];
    $name = $leaves[$model_node] ?? ('node ' . $model_node);
    $need = $per_model - (int)$mrow['pinned_n'];
    if ($need <= 0) {
        continue;
    }
    $stats['models']++;

    # Single-region for this file (bare or stamped checksum), one leaf = this model, has vector.
    $candidates = ps_query(
        "SELECT r.ref
           FROM resource r
           JOIN product_region preg ON preg.resource = r.ref
           JOIN resource_dinov2_vector v ON v.resource = r.ref
           JOIN product_reference pref ON pref.resource = r.ref
                AND pref.model_node = ? AND pref.status = 'derived'
          WHERE r.archive >= 0
            AND (preg.checksum = r.file_checksum
                 OR preg.checksum LIKE CONCAT(r.file_checksum, ':%'))
            AND preg.bbox LIKE '[[%'
            AND preg.bbox NOT LIKE '%],[%'
            AND NOT EXISTS (
                  SELECT 1 FROM product_reference px
                   WHERE px.resource = r.ref AND px.status = 'pinned')
            AND (
                  SELECT COUNT(*) FROM resource_node rn
                    JOIN node n ON n.ref = rn.node AND n.resource_type_field = ?
                   WHERE rn.resource = r.ref
                     AND NOT EXISTS (
                           SELECT 1 FROM node c WHERE c.parent = n.ref)
                ) = 1
            AND EXISTS (
                  SELECT 1 FROM resource_node rn
                   WHERE rn.resource = r.ref AND rn.node = ?)
          ORDER BY r.ref DESC
          LIMIT ?",
        ['i', $model_node, 'i', $field, 'i', $model_node, 'i', $need * 6]
    );

    $got = 0;
    foreach ($candidates as $crow) {
        if ($got >= $need) {
            break;
        }
        $ref = (int)$crow['ref'];
        $stats['candidates']++;

        # Ensure mask exists for region 0.
        $boxes = pr_ensure_regions($ref);
        if (count($boxes) !== 1) {
            $stats['skipped']++;
            printf("  skip %d %s (regions=%d)\n", $ref, $name, count($boxes));
            continue;
        }
        $resource = get_resource_data($ref);
        if ($resource === false) {
            $stats['failed']++;
            continue;
        }
        $mask = pr_mask_file($ref, (string)$resource['file_checksum'], 0);
        if (!is_file($mask)) {
            $stats['skipped']++;
            printf("  skip %d %s (no mask)\n", $ref, $name);
            continue;
        }

        if ($dry_run) {
            printf("  WOULD pin %d → %s\n", $ref, $name);
            $got++;
            $stats['pinned']++;
            continue;
        }

        if (pr_pin_and_embed($ref, $model_node, $user)) {
            printf("  pinned %d → %s\n", $ref, $name);
            $got++;
            $stats['pinned']++;
        } else {
            $stats['failed']++;
            printf("  FAIL pin %d → %s\n", $ref, $name);
        }
    }
}

printf(
    "\nmodels=%d candidates=%d pinned=%d skipped=%d failed=%d\n",
    $stats['models'],
    $stats['candidates'],
    $stats['pinned'],
    $stats['skipped'],
    $stats['failed']
);

if ($do_calibrate && !$dry_run && $stats['pinned'] > 0) {
    echo "\nRunning --calibrate...\n";
    $out = pr_with_lock(function () {
        return ['result' => pr_calibrate(false)];
    });
    if (!empty($out['locked'])) {
        echo "Calibrate skipped: another run holds the lock.\n";
        exit(0);
    }
    printf("Calibrated %d model(s).\n", count($out['result'] ?? []));
}
