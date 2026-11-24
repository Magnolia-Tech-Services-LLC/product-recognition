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
 *   php product_recognition_cli.php --colorway-accuracy [--limit=N]    # default 500; N=0 all
 *   php product_recognition_cli.php --colorway-projection [--limit=N]  # untagged lifestyle
 *   php product_recognition_cli.php --colorway-crops [--limit=N]       # cutouts, cached regions only
 *   php product_recognition_cli.php --colorway-color                   # cutout color vs colorway profiles
 *   php product_recognition_cli.php --regions --resource=N [--resource=M ...]
 *   php product_recognition_cli.php --migrate-family [--dry-run]
 *   php product_recognition_cli.php --accept-certain [--dry-run]
 *   php product_recognition_cli.php --sheet [--dry-run]
 *   php product_recognition_cli.php --studio-seed [--dry-run]
 *   php product_recognition_cli.php --cutout-features [--limit=N] [--dry-run]
 *   php product_recognition_cli.php --import-bag-tags=FILE [--dry-run]   # JSON rows, see below
 *   php product_recognition_cli.php --bag-match [--dry-run]
 *   php product_recognition_cli.php --bag-match-report                   # read-only
 *   php product_recognition_cli.php --undo-bag-matches [--resource=N | --match=N] [--dry-run]
 *   php product_recognition_cli.php --all
 *
 * Bag tags (#34): FILE is a JSON array of {resource, region_index, file_checksum, bbox, name,
 * colorway, user}. A row is taken only if its file checksum and cutout box (overlap >= 0.9)
 * still match, both labels exist, and it has a product.
 */
include_once "/var/www/html/include/boot.php";
include_once "/var/www/html/plugins/product_recognition/include/product_recognition_functions.php";
command_line_only();

$modes = array_slice($argv, 1);
$dry_run = in_array('--dry-run', $modes, true);
$mode = $modes[0] ?? '--help';
# --import-bag-tags=FILE carries its argument in the mode itself.
$import_file = '';
if (str_starts_with($mode, '--import-bag-tags=')) {
    $import_file = substr($mode, strlen('--import-bag-tags='));
    $mode = '--import-bag-tags';
}

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

/**
 * @param array<string,mixed> $stats pr_bag_run_match() result
 */
function pr_bag_print_match_summary(array $stats, string $indent = ''): void
{
    if (!empty($stats['disabled'])) {
        printf("%sbag match: %s, skipped\n", $indent, pr_bag_disabled_text((string)($stats['disabled_reason'] ?? '')));
        return;
    }
    $ignored = (array)($stats['ignored_seeds'] ?? []);
    printf("%sseeds %d (ignored %d), competitors %d, cutouts scored %d\n", $indent, (int)($stats['seeds'] ?? 0),
           count($ignored), (int)($stats['competitors'] ?? 0), (int)($stats['considered'] ?? 0));
    printf("%s  opened %d, updated %d, closed %d, auto-applied %d, blocked %d (auto-apply is OFF unless a bag flag is set)\n",
           $indent, (int)($stats['opened'] ?? 0), (int)($stats['updated'] ?? 0), (int)($stats['closed'] ?? 0),
           (int)($stats['auto_applied'] ?? 0), (int)($stats['blocked'] ?? 0));
    foreach ($ignored as $i) {
        printf("%s  ignored seed %d (resource %d): %s\n", $indent, $i['ref'], $i['resource'], $i['reason']);
    }
    if ((int)($stats['superseded'] ?? 0) > 0) {
        printf("%s  superseded %d open suggestions (cutout gone or re-cut, or no usable seed)\n", $indent, (int)$stats['superseded']);
    }
    foreach ((array)($stats['stale_applied'] ?? []) as $a) {
        # Only what the pass wrote unattended can be undone; a person's accepted match is theirs.
        printf("%s  applied match %d (resource %d) came from seed %d, which has since been re-answered; %s\n",
               $indent, $a['match'], $a['resource'], $a['seed_ref'],
               $a['unattended'] ? sprintf('not undone (see --undo-bag-matches --match=%d)', $a['match']) : 'accepted by a person, so it stays');
    }
    if (!empty($stats['service_down'])) {
        printf("%s  the service did not answer; the pass stopped early\n", $indent);
    }
}

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

    case '--colorway-color':
        # Read-only: cutout color against same-product colorway profiles from other shoots.
        fputcsv(STDOUT, pr_color_header(), "\t");
        $stats = pr_colorway_color_report(function (array $row) {
            fputcsv(STDOUT, pr_color_fields($row), "\t");
        });
        foreach (pr_color_summary($stats) as $line) {
            fwrite(STDERR, $line . "\n");
        }
        fwrite(STDERR, "Nothing written (read-only).\n");
        break;

    case '--colorway-accuracy':
    case '--colorway-projection':
    case '--colorway-crops':
        # Read-only, so no process lock (classify may hold it). One /identify per photo
        # (per cutout for --colorway-crops), so the default is a bounded sample; --limit=0
        # is every photo.
        $limit = 500;
        $k = (int)$GLOBALS['product_recognition_k'];
        foreach ($modes as $m) {
            if (strpos($m, '--limit=') === 0) {
                if (!preg_match('/^--limit=(\d+)$/', $m, $lm)) {
                    fwrite(STDERR, "Invalid --limit: use --limit=N (digits), --limit=0 for all\n");
                    exit(1);
                }
                $limit = (int)$lm[1];
            }
        }
        if ($k <= 0) {
            fwrite(STDERR, "product_recognition_k must be > 0\n");
            exit(1);
        }
        if ($mode === '--colorway-crops') {
            fputcsv(STDOUT, pr_colorway_crop_header(), "\t");
            $stats = pr_colorway_crop_report($limit, $k, max(2000, 5 * $k), function (array $row) {
                fputcsv(STDOUT, pr_colorway_crop_fields($row), "\t");
            });
            fwrite(STDERR, sprintf("Limit %s, k %d\n", $limit === 0 ? 'all' : (string)$limit, $k));
            foreach (pr_colorway_crop_summary($stats) as $line) {
                fwrite(STDERR, $line . "\n");
            }
        } elseif ($mode === '--colorway-accuracy') {
            fputcsv(STDOUT, pr_colorway_report_header(), "\t");
            # Wide enough that k neighbours survive dropping a whole shoot: live, a test photo
            # shares its shoot with 821 references on average and up to 1,835.
            $stats = pr_colorway_accuracy_report($limit, $k, max(2000, 5 * $k), function (array $row) {
                fputcsv(STDOUT, pr_colorway_report_fields($row), "\t");
            });
            fwrite(STDERR, sprintf("Limit %s, k %d\n", $limit === 0 ? 'all' : (string)$limit, $k));
            foreach (pr_colorway_report_summary($stats) as $line) {
                fwrite(STDERR, $line . "\n");
            }
            fwrite(STDERR, "Test set is lifestyle photos already tagged, mostly from folder and file names;\n"
                . "read precision as an upper bound for untagged lifestyle photos.\n");
        } else {
            fputcsv(STDOUT, pr_colorway_projection_header(), "\t");
            # Wide, so k colorway-tagged neighbours can be found past untagged ones.
            $stats = pr_colorway_projection($limit, $k, max(2000, 5 * $k), function (array $fields) {
                fputcsv(STDOUT, $fields, "\t");
            });
            fwrite(STDERR, sprintf("Limit %s, k %d\n", $limit === 0 ? 'all' : (string)$limit, $k));
            fwrite(STDERR, sprintf("Untagged lifestyle photos  %d (no vector %d)\n",
                $stats['considered'], $stats['no_vector']));
            foreach (['' => 'k nearest (today)', '_tagged' => 'k nearest tagged'] as $suffix => $title) {
                fwrite(STDERR, sprintf("%s: would get a colorway %d, blank %d\n",
                    $title, $stats['voted' . $suffix], $stats['blank' . $suffix]));
                foreach (array_slice($stats['by_colorway' . $suffix], 0, 40, true) as $name => $n) {
                    fwrite(STDERR, sprintf("  %-28s %d\n", $name, $n));
                }
            }
        }
        fwrite(STDERR, "Nothing written (read-only).\n");
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

    case '--cutout-features':
        $limit = (int)pr_bag_config()['batch'];
        foreach ($modes as $m) {
            if (strpos($m, '--limit=') === 0) {
                if (!preg_match('/^--limit=(\d+)$/', $m, $lm)) {
                    fwrite(STDERR, "Invalid --limit: use --limit=N (digits)\n");
                    exit(1);
                }
                $limit = (int)$lm[1];
            }
        }
        $stats = $pr_cli_run(fn() => pr_bag_backfill_features($limit, $dry_run));
        if ($stats === null) {
            exit(0);
        }
        if (!empty($stats['disabled_reason'])) {
            fwrite(STDERR, 'product_recognition: ' . pr_bag_disabled_text($stats['disabled_reason']) . "; nothing to do.\n");
            exit(1);
        }
        printf("Cutout features%s\n", $dry_run ? ' (would compute)' : '');
        printf("  computed         %d\n  unusable         %d\n  failed           %d\n  given up (%d tries) %d\n  mask rewritten   %d\n  no mask yet      %d\n  resources scanned %d\n",
               $stats['computed'], $stats['unusable'], $stats['failed'], PR_BAG_MAX_ATTEMPTS, $stats['given_up'], $stats['stale'],
               $stats['skipped_no_mask'], $stats['scanned']);
        if ($stats['service_down']) {
            fwrite(STDERR, "product_recognition: the service did not answer, or kept failing; stopped.\n");
            exit(1);
        }
        if ($dry_run) {
            echo "\nDRY RUN - nothing written.\n";
        }
        break;

    case '--import-bag-tags':
        $json = $import_file !== '' && is_file($import_file) ? json_decode((string)file_get_contents($import_file), true) : null;
        if (!is_array($json)) {
            fwrite(STDERR, "Usage: --import-bag-tags=FILE (a JSON array of rows) [--dry-run]\n");
            exit(1);
        }
        $stats = $pr_cli_run(fn() => pr_bag_import_tags(array_values($json), $dry_run));
        if ($stats === null) {
            exit(0);
        }
        if (!empty($stats['disabled_reason'])) {
            fwrite(STDERR, 'product_recognition: ' . pr_bag_disabled_text($stats['disabled_reason']) . "; nothing imported.\n");
            exit(1);
        }
        printf("Bag tags: %d accepted, %d rejected%s\n", $stats['accepted'], count($stats['rejected']),
               $dry_run ? ' (dry run)' : '');
        foreach ($stats['rejected'] as $r) {
            printf("  row %d resource %d: %s\n", $r['row'], $r['resource'], $r['reason']);
        }
        if ($dry_run) {
            echo "\nDRY RUN - nothing written.\n";
        }
        break;

    case '--bag-match':
        $stats = $pr_cli_run(fn() => pr_bag_run_match($dry_run));
        if ($stats === null) {
            exit(0);
        }
        if (!empty($stats['disabled'])) {
            fwrite(STDERR, 'product_recognition: ' . pr_bag_disabled_text((string)($stats['disabled_reason'] ?? '')) . "; nothing to do.\n");
            exit(1);
        }
        pr_bag_print_match_summary($stats);
        if (!empty($stats['service_down'])) {
            fwrite(STDERR, "product_recognition: the service did not answer; stopped.\n");
            exit(1);
        }
        if ($dry_run) {
            echo "\nDRY RUN - nothing written.\n";
        }
        break;

    case '--bag-match-report':
        # Read-only (a lock is for writers). One row per seed, gate and mode; the summary is the
        # held-out half only.
        fputcsv(STDOUT, ['resource', 'region', 'held_out', 'mode', 'gate', 'outcome', 'key', 'similarity', 'margin'], "\t");
        $report = pr_bag_report(function (array $row) {
            fputcsv(STDOUT, [$row['resource'], $row['region'], $row['held_out'] ? 1 : 0, $row['mode'], $row['gate'],
                $row['outcome'], $row['key'], $row['similarity'] === null ? '' : sprintf('%.4f', $row['similarity']),
                $row['margin'] === null ? '' : sprintf('%.4f', $row['margin'])], "\t");
        });
        fwrite(STDERR, sprintf("Usable seeds %d, ignored %d\n", $report['seeds'], count($report['ignored'])));
        foreach ($report['ignored'] as $i) {
            fwrite(STDERR, sprintf("  ignored seed %d (resource %d): %s\n", $i['ref'], $i['resource'], $i['reason']));
        }
        if ($report['error'] !== null) {
            fwrite(STDERR, "Report stopped: {$report['error']}\n");
        }
        fwrite(STDERR, "Held-out half (leave-one-out, then true colorway hidden):\n");
        foreach ($report['tally'] as $name => $t) {
            fwrite(STDERR, sprintf("  %-16s right %d, wrong %d, not tagged %d, no margin %d\n", $name, $t['right'], $t['wrong'], $t['none'], $t['unmeasured'] ?? 0));
        }
        fwrite(STDERR, $report['verdict']['ok']
            ? "Enable condition MET on the held-out half.\n"
            : "Enable condition NOT met:\n");
        foreach ($report['verdict']['reasons'] as $why) {
            fwrite(STDERR, "  $why\n");
        }
        fwrite(STDERR, "Nothing written (read-only).\n");
        exit($report['error'] === null ? 0 : 1);

    case '--undo-bag-matches':
        # No --resource means the whole library, so a mistyped argument must stop, not widen the undo.
        $only = null;
        $only_match = null;
        foreach (array_slice($modes, 1) as $arg) {
            if ($arg === '--dry-run') {
                continue;
            }
            if ($only !== null || $only_match !== null || !preg_match('/^--(resource|match)=([1-9]\d*)$/', $arg, $m)) {
                fwrite(STDERR, "Usage: --undo-bag-matches [--resource=N | --match=N] [--dry-run]\n");
                exit(1);
            }
            if ($m[1] === 'match') {
                $only_match = (int)$m[2];
            } else {
                $only = (int)$m[2];
            }
        }
        $stats = $pr_cli_run(fn() => pr_bag_undo_matches($dry_run, $only, $only_match));
        if ($stats === null) {
            exit(0);
        }
        if (!empty($stats['disabled_reason'])) {
            fwrite(STDERR, 'product_recognition: ' . pr_bag_disabled_text($stats['disabled_reason']) . "; nothing to undo.\n");
            exit(1);
        }
        printf("Bag matches written by the nightly pass, %s: %d\n  nodes removed   %d\n  already gone    %d\n  kept (shared)   %d\n",
               $dry_run ? 'to undo' : 'undone', $stats['matches'], $stats['nodes_removed'],
               $stats['already_gone'], $stats['protected']);
        if ($dry_run) {
            echo "\nDRY RUN - nothing written.\n";
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
        $bag_features = $result['bag_features'] ?? [];
        $bag_match = $result['bag_match'] ?? [];
        printf("bag features computed %d, unusable %d, failed %d%s\n", (int)($bag_features['computed'] ?? 0),
               (int)($bag_features['unusable'] ?? 0), (int)($bag_features['failed'] ?? 0),
               empty($bag_features['error']) ? (empty($bag_features['service_down']) ? '' : ' (service did not answer)') : ' (' . $bag_features['error'] . ')');
        if (empty($bag_match['error'])) {
            pr_bag_print_match_summary($bag_match, 'bag match    ');
        } else {
            printf("bag match    %s\n", $bag_match['error']);
        }
        if (pr_pending_tree_field() > 0) {
            fwrite(STDERR, "product_recognition: leftover Model-tree or Family references. "
                . "--all does not migrate. Run --migrate-family --dry-run, then --migrate-family.\n");
        }
        break;

    case '--help':
    case '-h':
        fwrite(STDOUT, "product_recognition_cli.php --report|--references|--catalog|--paths|--backfill|--calibrate|--classify|--disagreement|--colorway-accuracy|--colorway-projection|--colorway-crops|--colorway-color|--regions|--migrate-family|--all|--accept-certain|--sheet|--studio-seed|--cutout-features|--import-bag-tags=FILE|--bag-match|--bag-match-report|--undo-bag-matches [--dry-run]\n");
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
        fwrite(STDERR, "product_recognition_cli.php --report|--references|--catalog|--paths|--backfill|--calibrate|--classify|--disagreement|--colorway-accuracy|--colorway-projection|--colorway-crops|--colorway-color|--regions|--migrate-family|--all|--accept-certain|--sheet|--studio-seed|--cutout-features|--import-bag-tags=FILE|--bag-match|--bag-match-report|--undo-bag-matches [--dry-run]\n");
        exit(1);
}
