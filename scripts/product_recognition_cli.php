<?php
/**
 * Product recognition CLI. All logic lives in the plugin's include/ file so the cron hook and
 * this script cannot drift apart.
 *
 *   php product_recognition_cli.php --report
 *   php product_recognition_cli.php --references [--dry-run]
 *   php product_recognition_cli.php --catalog [--resource=N] [--dry-run]
 *   php product_recognition_cli.php --paths
 *   php product_recognition_cli.php --backfill [--limit=N] [--dry-run]
 *   php product_recognition_cli.php --calibrate [--dry-run]
 *   php product_recognition_cli.php --classify [--limit=N] [--dry-run]
 *   php product_recognition_cli.php --disagreement [--limit=N]  # default classify_batch; N=0 unlimited
 *   php product_recognition_cli.php --regions --resource=N [--resource=M ...]
 *   php product_recognition_cli.php --migrate-family [--dry-run]
 *   php product_recognition_cli.php --accept-certain [--dry-run]
 *   php product_recognition_cli.php --sheet [--dry-run]
 *   php product_recognition_cli.php --studio-seed [--dry-run]
 *   php product_recognition_cli.php --all
 */
include_once "/var/www/html/include/boot.php";
include_once "/var/www/html/plugins/product_recognition/include/product_recognition_functions.php";
command_line_only();

$modes = array_slice($argv, 1);
$dry_run = in_array('--dry-run', $modes, true);
$mode = $modes[0] ?? '--help';

if (!in_array($mode, ['--help', '-h', '--catalog', '--migrate-family'], true) && !pr_is_configured()) {
    fwrite(STDERR, "product_recognition: Name field is not available and no recognition field is bound\n");
    exit(1);
}

/**
 * Run a writing stage under the shared process lock, unless this is a dry-run.
 * Returns null when another run holds the lock (caller should exit 0).
 *
 * @param callable():mixed $fn
 * @return mixed|null
 */
$pr_cli_run = function (callable $fn) use ($dry_run) {
    if ($dry_run) {
        return $fn();
    }
    $out = pr_with_lock(function () use ($fn) {
        return ['result' => $fn()];
    });
    if (!empty($out['locked'])) {
        echo "Another run holds the lock - exiting without writing.\n";
        return null;
    }
    return $out['result'];
};

switch ($mode) {
    case '--migrate-family':
        $stats = $pr_cli_run(fn() => pr_migrate_to_family($dry_run));
        if ($stats === null) {
            exit(0);
        }
        if (!empty($stats['error'])) {
            fwrite(STDERR, "product_recognition: {$stats['error']}\n");
            exit(1);
        }
        printf("Name field %d\n", $stats['field']);
        printf("  remapped references %d\n", $stats['references']);
        printf("  name stamped        %d\n", $stats['stamped']);
        printf("  orphaned            %d\n", $stats['orphaned']);
        printf("  suggestions cleared %d\n", $stats['suggestions_cleared']);
        if ($stats['field'] <= 0) {
            fwrite(STDERR, "product_recognition: no field named modelkeyword\n");
            exit(1);
        }
        if ($dry_run) {
            echo "\nDRY RUN - nothing written.\n";
        }
        break;

    case '--paths':
        $rows = ps_query("SELECT ref FROM resource WHERE ref > 0 ORDER BY ref");
        foreach ($rows as $r) {
            $path = get_resource_path((int)$r['ref'], true, 'pre', false, 'jpg');
            if (file_exists($path)) {
                printf("%d\t%s\n", (int)$r['ref'], $path);
            }
        }
        break;

    case '--references':
        $stats = $pr_cli_run(fn() => pr_sync_references($dry_run));
        if ($stats === null) {
            exit(0);
        }
        printf("Derived references: %d\n", $stats['derived']);
        printf("  added   %d\n  updated %d\n  removed %d\n", $stats['added'], $stats['updated'], $stats['removed']);
        printf("  pinned  %d (kept)\n  excluded %d (kept)\n", $stats['pinned'], $stats['excluded']);
        # Wider than the cron hook's equivalent guard on purpose: $stats['orphaned'] is a
        # standing backlog, not damage from this run, and an operator asking directly should see
        # it. The cron speaks only when something changed. Do not "fix" the asymmetry.
        $rec = $stats['reconciled'];
        if (pr_reconcile_total($rec) > 0 || $stats['orphaned'] > 0 || $stats['revived'] !== []) {
            printf("  %s by deleted product nodes: %d threshold, %d pinned reference, %d suggestion\n",
                   $dry_run ? "WOULD ORPHAN" : "ORPHANED",
                   $rec['thresholds'], $rec['references'], $rec['suggestions']);
            # No dry-run adjustment needed: pr_sync_references() overlays the reconciled rows onto
            # its snapshot, so this counts the same backlog either way, minus anything revived.
            printf("  %d reference(s) awaiting a product tag before they can be revived\n",
                   $stats['orphaned']);
            foreach ($stats['revived'] as $r) {
                echo "  " . pr_format_revived($r, $dry_run) . "\n";
            }
        }
        foreach ($stats['conflicts'] as $c) {
            printf("  CONFLICT resource %d pinned as node %d but now tagged node %d\n",
                   $c['resource'], $c['pinned_as'], $c['tagged_as']);
        }
        if (pr_pending_tree_field() > 0) {
            fwrite(STDERR, "product_recognition: leftover Model-tree or Family references. "
                . "Run --migrate-family --dry-run, then --migrate-family. "
                . "Do not set the setup Product field to Name or Family first. "
                . "Derived deletes are fenced until then.\n");
        }
        if ($dry_run) {
            echo "\nDRY RUN - nothing written.\n";
        }
        break;

    case '--catalog':
        # --resource= overrides sheet discovery, for reading a sheet whose title does not
        # match or for checking one candidate against another.
        $resource = null;
        foreach ($modes as $arg) {
            if (preg_match('/^--resource=(\d+)$/', $arg, $m)) {
                $resource = (int)$m[1];
            }
        }
        $stats = $pr_cli_run(fn() => pr_sync_catalog($dry_run, $resource));
        if ($stats === null) {
            exit(0);
        }
        if ($stats['error'] !== null) {
            fwrite(STDERR, "Catalogue ingest failed: {$stats['error']}\n");
            exit(1);
        }
        printf("Read SKU sheet from resource %d\n", $stats['source']);
        printf("Parsed %d SKUs across %d sections\n", $stats['parsed'], count($stats['sections']));
        printf("  added     %d\n  updated   %d\n  unchanged %d\n",
               $stats['added'], $stats['updated'], $stats['unchanged']);
        foreach ($stats['sections'] as $section => $n) {
            printf("  %-40s %3d\n", $section, $n);
        }
        if (isset($stats['nodes_model_created'])) {
            printf(
                "Nodes  model +%d/=%d  colorway +%d/=%d skip %d  collab +%d/=%d\n",
                (int)$stats['nodes_model_created'],
                (int)$stats['nodes_model_exists'],
                (int)$stats['nodes_colorway_created'],
                (int)$stats['nodes_colorway_exists'],
                (int)$stats['nodes_colorway_skipped'],
                (int)$stats['nodes_collab_created'],
                (int)$stats['nodes_collab_exists']
            );
        }
        if ($dry_run) {
            echo "\nDRY RUN - nothing written.\n";
        }
        break;

    case '--report':
        $counts = pr_reference_counts();
        $leaves = pr_leaf_models();
        $target = $GLOBALS['product_recognition_target_precision'];
        $floor = pr_min_references($target);
        printf("Leaf models in the field: %d\n", count($leaves));
        printf("Models with references:   %d\n", count($counts));
        printf("Reference images total:   %d\n", array_sum($counts));
        printf("Auto-apply floor at target %.2f: %d references\n\n", $target, $floor);
        $bands = ['< floor' => [0, 0], 'floor-72' => [0, 0], '>= 73' => [0, 0]];
        foreach ($counts as $node => $n) {
            $band = $n < $floor ? '< floor' : ($n < 73 ? 'floor-72' : '>= 73');
            $bands[$band][0]++;
            $bands[$band][1] += $n;
        }
        printf("  %-10s %6s %8s\n", 'band', 'models', 'images');
        foreach ($bands as $band => $agg) {
            printf("  %-10s %6d %8d\n", $band, $agg[0], $agg[1]);
        }
        arsort($counts);
        echo "\n  largest reference sets:\n";
        foreach (array_slice($counts, 0, 12, true) as $node => $n) {
            printf("    %-32s %d\n", $leaves[$node] ?? "node $node", $n);
        }
        break;

    case '--backfill':
        $limit = (int)($GLOBALS['product_recognition_batch']);
        foreach ($modes as $m) {
            if (strpos($m, '--limit=') === 0) {
                $limit = (int)substr($m, 8);
            }
        }
        $stats = $pr_cli_run(fn() => pr_backfill($limit, $dry_run));
        if ($stats === null) {
            exit(0);
        }
        echo 'backfill     ' . pr_format_backfill_summary($stats) . "\n";
        if (!$dry_run) {
            pr_report_backfill_slice($stats);
        }
        if ($dry_run) {
            echo "\nDRY RUN - nothing written.\n";
        }
        break;

    case '--calibrate':
        $results = $pr_cli_run(fn() => pr_calibrate($dry_run));
        if ($results === null) {
            exit(0);
        }
        $target = (float)$GLOBALS['product_recognition_target_precision'];
        printf("Target precision %.2f, reference floor %d\n\n", $target, pr_min_references($target));
        printf("  %-30s %5s %6s %9s %8s %7s  %s\n",
               'model', 'refs', 'n', 'threshold', 'prec', 'wilson', 'status');
        uasort($results, fn($a, $b) => $b['reference_count'] <=> $a['reference_count']);
        foreach ($results as $row) {
            $status = $row['status'];
            if (!empty($row['never_auto_apply'])) {
                $status .= ' never-auto';
            }
            printf("  %-30s %5d %6d %9s %8s %7s  %s\n",
                   substr($row['name'], 0, 30), $row['reference_count'], $row['sample_size'],
                   $row['min_confidence'] === null ? '-' : sprintf('%.3f', $row['min_confidence']),
                   $row['measured_precision'] === null ? '-' : sprintf('%.1f%%', 100 * $row['measured_precision']),
                   $row['wilson_lower'] === null ? '-' : sprintf('%.1f%%', 100 * $row['wilson_lower']),
                   $status);
        }
        $lost = array_filter($results, fn($r) => $r['status'] === 'references_lost_to_children');
        if ($lost !== []) {
            echo "\n  ** MODELS THAT LOST THEIR REFERENCES TO NEW CHILDREN **\n";
            echo "  These are not 'no references yet'. Each gained child nodes, so every\n";
            echo "  resource tagged only with the parent is now under-specified and dropped\n";
            echo "  out of the reference set. Re-tag those resources to the new leaves.\n";
            foreach ($lost as $row) {
                printf("    %-30s had %d reference(s)%s\n", $row['name'],
                       $row['previous_count'] ?? 0,
                       ($row['pinned_remaining'] ?? 0) > 0
                           ? sprintf("  ** %d PINNED reference(s) still point here and need re-pointing by hand **",
                                     $row['pinned_remaining'])
                           : '');
            }
        }
        $unmatched = pr_never_auto_apply_unmatched(pr_leaf_models());
        if ($unmatched !== []) {
            echo "\n  never-auto-apply names with no unique leaf match:\n";
            foreach ($unmatched as $name) {
                printf("    %s\n", $name);
            }
        }
        if ($dry_run) {
            echo "\nDRY RUN - nothing written.\n";
        }
        break;

    case '--classify':
        $limit = (int)$GLOBALS['product_recognition_classify_batch'];
        foreach ($modes as $m) {
            if (strpos($m, '--limit=') === 0) {
                $limit = (int)substr($m, 8);
            }
        }
        $stats = $pr_cli_run(fn() => pr_classify($limit, $dry_run));
        if ($stats === null) {
            exit(0);
        }
        printf("Considered              %d\n", $stats['considered']);
        printf("  suggested             %d\n", $stats['suggested']);
        printf("  auto-applied          %d%s\n", $stats['auto_applied'],
               $GLOBALS['product_recognition_auto_apply'] ? '' : '  (auto-apply is OFF)');
        printf("  certain accepted     %d\n", $stats['certain_accepted']);
        printf("  certain left queued  %d\n", $stats['certain_skipped_never']);
        printf("  nothing similar       %d\n", $stats['skipped_low_similarity']);
        printf("  no candidates         %d\n", $stats['no_candidates']);
        printf("  candidate re-rejected %d\n", $stats['skipped_rejected']);
        if ($dry_run) {
            echo "\nDRY RUN - nothing written.\n";
        }
        break;

    case '--disagreement':
        # Default is classify_batch, not the whole library: this hammers /identify per row.
        # --limit=0 is unlimited. Digits only so --limit=foo cannot become a full scan.
        # Read-only, so no process lock (classify may hold it).
        $limit = (int)$GLOBALS['product_recognition_classify_batch'];
        $limit_explicit = false;
        foreach ($modes as $m) {
            if (strpos($m, '--limit=') === 0) {
                if (!preg_match('/^--limit=(\d+)$/', $m, $lm)) {
                    fwrite(STDERR, "Invalid --limit: use --limit=N (digits), --limit=0 for unlimited\n");
                    exit(1);
                }
                $limit = (int)$lm[1];
                $limit_explicit = true;
            }
        }
        if (!$limit_explicit && $limit <= 0) {
            fwrite(STDERR, "product_recognition_classify_batch must be > 0, or pass --limit=N\n");
            exit(1);
        }
        $header = [
            'resource', 'tagged_node', 'tagged_name', 'recognised_node', 'recognised_name',
            'confidence', 'wilson_lower', 'top_similarity', 'top_reference',
        ];
        fputcsv(STDOUT, $header, "\t");
        $stats = pr_disagreement_report($limit, function (array $row) {
            fputcsv(STDOUT, pr_disagreement_tsv_fields($row), "\t");
        });
        fwrite(STDERR, sprintf("Limit                   %s\n", $limit === 0 ? 'unlimited' : (string)$limit));
        fwrite(STDERR, sprintf("Considered              %d\n", $stats['considered']));
        fwrite(STDERR, sprintf("  disagreed             %d\n", $stats['disagreed']));
        fwrite(STDERR, sprintf("  agreed                %d\n", $stats['agreed']));
        fwrite(STDERR, sprintf("  nothing similar       %d\n", $stats['skipped_low_similarity']));
        fwrite(STDERR, sprintf("  no candidates         %d\n", $stats['no_candidates']));
        fwrite(STDERR, sprintf("  no comparable leaf    %d\n", $stats['service_empty']));
        fwrite(STDERR, "Nothing written (read-only; auto-apply was not used).\n");
        if ($dry_run) {
            fwrite(STDERR, "Ignoring --dry-run: disagreement report never writes.\n");
        }
        break;

    case '--regions':
        $refs = [];
        foreach ($modes as $arg) {
            if (preg_match('/^--resource=(\d+)$/', $arg, $m)) {
                $refs[] = (int)$m[1];
            }
        }
        if ($refs === []) {
            fwrite(STDERR, "Usage: --regions --resource=N [--resource=M ...]\n");
            exit(1);
        }
        foreach ($refs as $ref) {
            ps_query('DELETE FROM product_region WHERE resource = ?', ['i', $ref]);
            pr_purge_masks($ref);
            $boxes = pr_ensure_regions($ref);
            printf("%d\tregions=%d\t%s\n", $ref, count($boxes), $boxes === [] ? '(none)' : json_encode($boxes));
        }
        break;

    case '--all':
        $result = pr_run_all();
        if (!empty($result['locked'])) {
            echo "Another run holds the lock - exiting without writing.\n";
            exit(0);
        }
        $sheet = $result['sheet'] ?? [];
        printf("sheet        imported %d, added %d, updated %d, removed %d%s\n",
               (int)($sheet['imported'] ?? 0), (int)($sheet['added'] ?? 0),
               (int)($sheet['updated'] ?? 0), (int)($sheet['removed'] ?? 0),
               empty($sheet['error']) ? '' : ' (' . $sheet['error'] . ')');
        $studio = $result['studio'] ?? [];
        printf("studio       exact %d, family-only %d, tagged %d, referenced %d, held %d%s\n",
               (int)($studio['exact'] ?? 0), (int)($studio['family_only'] ?? 0),
               (int)($studio['tagged'] ?? 0), (int)($studio['referenced'] ?? 0),
               (int)($studio['references_held'] ?? 0),
               empty($studio['error']) ? '' : ' (' . $studio['error'] . ')');
        echo "studio       tagged fills empty fields before any reference insert.\n";
        printf("references   derived %d, added %d, removed %d\n",
               $result['references']['derived'], $result['references']['added'],
               $result['references']['removed']);
        echo 'backfill     ' . pr_format_backfill_summary($result['backfill']) . "\n";
        printf("calibration  %d model(s)\n", count($result['calibration']));
        printf("classify     suggested %d, auto-applied %d, certain %d\n",
               $result['classification']['suggested'], $result['classification']['auto_applied'],
               $result['classification']['certain_accepted']);
        if (pr_pending_tree_field() > 0) {
            fwrite(STDERR, "product_recognition: leftover Model-tree or Family references. "
                . "--all does not migrate. Run --migrate-family --dry-run, then --migrate-family.\n");
        }
        break;

    case '--help':
    case '-h':
        fwrite(STDOUT, "product_recognition_cli.php --report|--references|--catalog|--paths|--backfill|--calibrate|--classify|--disagreement|--regions|--migrate-family|--all|--accept-certain|--sheet|--studio-seed [--dry-run]\n");
        break;

    case '--sheet':
        $stats = $pr_cli_run(fn() => pr_import_master_sheet($dry_run));
        if ($stats === null) {
            exit(0);
        }
        if (!empty($stats['error'])) {
            fwrite(STDERR, "product_recognition: {$stats['error']}\n");
            exit(1);
        }
        printf("Sellable rows %d, SMU rows %d\n", $stats['sellable'], $stats['smu']);
        printf("  imported  %d\n  added     %d\n  updated   %d\n  removed   %d\n  unchanged %d\n  skipped   %d\n",
               $stats['imported'], $stats['added'], $stats['updated'],
               $stats['removed'], $stats['unchanged'], $stats['skipped']);
        if ($dry_run) {
            echo "\nDRY RUN - nothing written.\n";
        }
        break;

    case '--studio-seed':
        $stats = $pr_cli_run(fn() => pr_studio_seed($dry_run));
        if ($stats === null) {
            exit(0);
        }
        if (!empty($stats['error'])) {
            fwrite(STDERR, "product_recognition: {$stats['error']}\n");
            exit(1);
        }
        printf("exact %d\nfamily-only %d\ntagged %d\nreferenced %d\nheld %d\nName disagrees %d\nskipped %d\n",
               $stats['exact'], $stats['family_only'], $stats['tagged'],
               $stats['referenced'], $stats['references_held'], $stats['references_conflict'],
               $stats['skipped']);
        echo "tagged fills empty fields before any reference insert. This command does not classify.\n";
        if ($dry_run) {
            echo "\nDRY RUN - nothing written.\n";
        }
        break;

    case '--accept-certain':
        $stats = $pr_cli_run(fn() => pr_accept_certain_open_suggestions($dry_run));
        if ($stats === null) {
            exit(0);
        }
        printf("certain accepted        %d\n", $stats['accepted']);
        printf("  tagged without pin    %d\n", $stats['tagged']);
        printf("  already tagged        %d\n", $stats['kept']);
        printf("  left queued           %d\n", $stats['skipped_never']);
        printf("  denied                %d\n", $stats['denied']);
        printf("  other                 %d\n", $stats['other']);
        if ($dry_run) {
            echo "\nDRY RUN - nothing written.\n";
        }
        break;

    default:
        fwrite(STDERR, "Unknown mode: $mode\n");
        fwrite(STDERR, "product_recognition_cli.php --report|--references|--catalog|--paths|--backfill|--calibrate|--classify|--disagreement|--regions|--migrate-family|--all|--accept-certain|--sheet|--studio-seed [--dry-run]\n");
        exit(1);
}
