<?php
/**
 * Colorway accuracy report (issue 18, step 1). Read-only: no tags, suggestions,
 * references or sysvars are written, and no process lock is taken.
 *
 * Test set: Lifestyle photos that carry exactly one colorway leaf and a stored vector.
 * Each is sent to /identify by stored vector, its own colorway hidden by the service's
 * self-exclusion, and the vote is compared with the stored colorway.
 *
 * Two neighbour sets per photo, because self-exclusion alone overstated model accuracy
 * before (97% leave-one-out against 43% agreement on lifestyle shots):
 *   self    the k nearest references other than the photo itself (what production sees)
 *   folder  the k nearest references that do not sit under one of the photo's folder keys
 *           (its deepest Folder Path folder or that folder's parent, see
 *           pr_colorway_report_folder_keys), so a sibling from the same shoot (Navy/Raw next
 *           to Navy/Selects, or deeper in Navy/Selects/Final), tagged from the same folder
 *           name, cannot vote. It errs toward dropping too much. A shoot can hold hundreds of references, so
 *           the CLI asks /identify for a wide list; a photo left with fewer than k folder
 *           neighbours, or with no folder key, is counted apart and kept out of the folder
 *           figures and bands
 *
 *   tagged  the k nearest references, same shoot and near-copies (>= 0.95) excluded, that
 *           carry a countable colorway. Untagged lifestyle references otherwise fill the k
 *           and sit in the vote's denominator, so this is the vote that lets studio and
 *           ecomm photos of a colorway teach it. Counted under the same rule as folder.
 *
 * Two vote paths, because they would write different photos:
 *   a  Name known: pr_voted_colorway_for_names with the photo's stored product leaf
 *   b  End to end: pr_aggregate_service_neighbors (family, then name, then colorway)
 *
 * The test set is biased: a lifestyle photo has a colorway mostly because its folder or
 * filename named one. Read the result as an upper bound for untagged lifestyle photos.
 */

const PR_COLORWAY_REPORT_SHARE_BANDS = [0.6, 0.7, 0.8, 0.9, 1.01];
const PR_COLORWAY_REPORT_SIMILARITY_BANDS = [0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 0.95, 1.01];
# A folder-set nearest neighbour at or above this is likely a copy of the same file filed
# elsewhere, carrying the same filename-derived colorway. Reported with and without.
const PR_COLORWAY_REPORT_NEAR_DUPLICATE = 0.95;

/**
 * correct, wrong or blank. A stored label is compared folded, as the vote folds.
 */
function pr_colorway_report_outcome(string $truth, string $voted): string
{
    if (trim($voted) === '') {
        return 'blank';
    }
    return pr_fold_label($truth) === pr_fold_label($voted) ? 'correct' : 'wrong';
}

/**
 * Upper edge of the band a value falls in, as a label ("<0.6"). Values at or above the
 * last edge land in the last band.
 *
 * @param list<float> $edges ascending
 */
function pr_colorway_report_band(float $value, array $edges): string
{
    foreach ($edges as $edge) {
        if ($value < $edge) {
            return $edge > 1.0 ? '<=1.0' : '<' . $edge;
        }
    }
    return '<=1.0';
}

/**
 * Support band: how many neighbours carried the winning colorway.
 */
function pr_colorway_report_support_band(int $support): string
{
    if ($support >= 5) {
        return '5+';
    }
    return $support >= 3 ? '3-4' : (string)$support;
}

/**
 * Folder keys for one photo from its Folder Path nodes. A photo carries its whole path
 * (each ancestor folder too), so the photo's own folder is the deepest one: a node that is
 * not the parent of another node on the same photo. Keys are those folders and their
 * parents. No nodes, no keys.
 *
 * @param list<array{ref:int, parent:int}> $nodes
 * @return array<int,true>
 */
function pr_colorway_report_folder_keys(array $nodes): array
{
    $parents = [];
    foreach ($nodes as $node) {
        $parents[(int)$node['parent']] = true;
    }
    $keys = [];
    foreach ($nodes as $node) {
        $ref = (int)$node['ref'];
        if ($ref <= 0 || isset($parents[$ref])) {
            continue;
        }
        $keys[$ref] = true;
        if ((int)$node['parent'] > 0) {
            $keys[(int)$node['parent']] = true;
        }
    }
    return $keys;
}

/**
 * Split /identify neighbours (already self-excluded, nearest first) into the two sets.
 * A neighbour is dropped from the folder set when any folder on its path (it carries every
 * ancestor) is one of the query photo's keys (pr_colorway_report_folder_keys), so a
 * neighbour deeper in the same shoot is dropped too. A query with no key drops nothing;
 * the caller keeps such a photo out of the folder figures. 'dropped' is the same-shoot
 * neighbours passed over before the folder set filled, not all of them.
 *
 * @param list<array{resource?:int}> $neighbors
 * @param array<int,true> $query_folders folder key node => true
 * @param array<int,array<int,true>> $folders_by_resource every Folder Path node per resource
 * @return array{self:list<array>, folder:list<array>, tagged:list<array>, dropped:int}
 */
function pr_colorway_report_split_neighbors(array $neighbors, array $query_folders,
                                            array $folders_by_resource, int $k): array
{
    $self = [];
    $folder = [];
    $tagged = [];
    $dropped = 0;
    foreach ($neighbors as $neighbor) {
        if (!is_array($neighbor)) {
            continue;
        }
        if (count($self) < $k) {
            $self[] = $neighbor;
        }
        $resource = (int)($neighbor['resource'] ?? 0);
        $shared = $query_folders !== []
            && array_intersect_key($folders_by_resource[$resource] ?? [], $query_folders) !== [];
        if ($shared) {
            # Counted only while the folder set fills, so the figure does not depend on how
            # far the tagged set had to look.
            if (count($folder) < $k) {
                $dropped++;
            }
            continue;
        }
        if (count($folder) < $k) {
            $folder[] = $neighbor;
        }
        if (count($tagged) < $k
            && (float)($neighbor['similarity'] ?? 0) < PR_COLORWAY_REPORT_NEAR_DUPLICATE
            && pr_colorway_report_is_tagged($neighbor)) {
            $tagged[] = $neighbor;
        }
        if (count($self) >= $k && count($folder) >= $k && count($tagged) >= $k) {
            break;
        }
    }
    return ['self' => $self, 'folder' => $folder, 'tagged' => $tagged, 'dropped' => $dropped];
}

/**
 * True when the neighbour's batch-resolved colorway is one the vote counts.
 */
function pr_colorway_report_is_tagged(array $neighbor): bool
{
    return pr_colorway_label_counts((string)($neighbor['colorway'] ?? ''));
}

/**
 * The k nearest neighbours that carry a countable colorway, in order.
 *
 * @param list<array> $neighbors
 * @return list<array>
 */
function pr_colorway_report_tagged_neighbors(array $neighbors, int $k): array
{
    $out = [];
    foreach ($neighbors as $neighbor) {
        if (is_array($neighbor) && pr_colorway_report_is_tagged($neighbor)) {
            $out[] = $neighbor;
            if (count($out) >= $k) {
                break;
            }
        }
    }
    return $out;
}

/**
 * The two vote paths over one neighbour set. Neighbours must already carry 'colorway'.
 *
 * @param list<array> $neighbors
 * @param array<int,string> $leaf_models
 * @param list<string> $names stored product leaf names on the photo
 * @return array{a:?string, a_share:float, a_support:int, b:string, b_name:string, b_share:float}
 */
function pr_colorway_report_votes(array $neighbors, array $leaf_models, array $names): array
{
    $a = null;
    $a_share = 0.0;
    $a_support = 0;
    if ($names !== []) {
        $detail = pr_voted_colorway_detail_for_names($neighbors, $leaf_models, $names);
        $a = $detail['colorway'];
        $a_share = $detail['share'];
        $a_support = $detail['support'];
    }
    # pr_aggregate_neighbors leaves name and colorway blank unless a Name won.
    $agg = pr_aggregate_service_neighbors($neighbors, $leaf_models);
    return [
        'a' => $a,
        'a_share' => $a_share,
        'a_support' => $a_support,
        'b' => (string)$agg['colorway'],
        'b_name' => (string)$agg['name'],
        # Name share is what gates path b: its colorway exists only after a Name won.
        'b_share' => (string)$agg['name'] !== '' ? (float)$agg['name_share'] : 0.0,
    ];
}

/**
 * Empty tallies for pr_colorway_report_tally.
 */
function pr_colorway_report_new_stats(): array
{
    $cell = ['correct' => 0, 'wrong' => 0, 'blank' => 0];
    return [
        'considered' => 0,
        'truth_not_a_color' => 0,
        'no_vector' => 0,
        'no_model' => 0,
        'no_folder_key' => 0,
        'folder_underfilled' => 0,
        'tagged_underfilled' => 0,
        'dropped_same_folder' => 0,
        'paths' => [
            'a_self' => $cell, 'a_folder' => $cell, 'a_folder_nodup' => $cell, 'a_tagged' => $cell,
            'b_self' => $cell, 'b_folder' => $cell, 'b_folder_nodup' => $cell, 'b_tagged' => $cell,
        ],
        'bands' => [
            'a_share' => [], 'a_support' => [], 'a_similarity' => [], 'b_share' => [],
            'at_share' => [], 'at_support' => [], 'bt_share' => [],
        ],
        'by_color' => [],
        'by_color_tagged' => [],
        'confusion' => [],
        'confusion_tagged' => [],
    ];
}

/**
 * Count one photo. $row carries truth and, per path and set, the voted colorway (null for
 * path a when the photo has no stored product). Folder figures, bands, per-colour and
 * confusion count only rows with a folder key and a full folder set ('folder_counted').
 *
 * @param array{truth:string, a_self:?string, a_folder:?string, b_self:string, b_folder:string,
 *              a_folder_share:float, a_folder_support:int, b_folder_share:float,
 *              folder_top_similarity:float, dropped:int, has_folder_key:bool,
 *              folder_counted:bool} $row
 */
function pr_colorway_report_tally(array &$stats, array $row): void
{
    $stats['considered']++;
    $stats['dropped_same_folder'] += (int)$row['dropped'];
    if ($row['a_self'] === null) {
        $stats['no_model']++;
    }
    if (!$row['has_folder_key']) {
        $stats['no_folder_key']++;
    } else {
        if (!$row['folder_counted']) {
            $stats['folder_underfilled']++;
        }
        if (!($row['tagged_counted'] ?? false)) {
            $stats['tagged_underfilled']++;
        }
    }
    $near_duplicate = (float)$row['folder_top_similarity'] >= PR_COLORWAY_REPORT_NEAR_DUPLICATE;
    $add = static function (string $key, string $outcome) use (&$stats): void {
        $stats['paths'][$key][$outcome]++;
    };
    $band = static function (string $table, string $label, string $outcome) use (&$stats): void {
        $stats['bands'][$table][$label][$outcome] = ($stats['bands'][$table][$label][$outcome] ?? 0) + 1;
    };
    $color = static function (string $table, string $truth, string $outcome) use (&$stats): void {
        # Folded, as outcomes are: "Navy" and "navy" are one row, shown by the first spelling.
        $fold = pr_fold_label($truth);
        $stats[$table][$fold]['label'] ??= $truth;
        $stats[$table][$fold][$outcome] = ($stats[$table][$fold][$outcome] ?? 0) + 1;
    };
    $sim = static fn(float $v): string => pr_colorway_report_band($v, PR_COLORWAY_REPORT_SIMILARITY_BANDS);
    $share = static fn(float $v): string => pr_colorway_report_band($v, PR_COLORWAY_REPORT_SHARE_BANDS);
    foreach (['a', 'b'] as $path) {
        $self = $row[$path . '_self'];
        if ($self !== null) {
            $add($path . '_self', pr_colorway_report_outcome($row['truth'], (string)$self));
        }
        foreach (['folder', 'tagged'] as $set) {
            $voted = $row[$path . '_' . $set] ?? null;
            if ($voted === null || !($row[$set . '_counted'] ?? false)) {
                continue;
            }
            $outcome = pr_colorway_report_outcome($row['truth'], (string)$voted);
            $add($path . '_' . $set, $outcome);
            if ($set === 'folder' && !$near_duplicate) {
                $add($path . '_folder_nodup', $outcome);
            }
            $suffix = $set === 'tagged' ? '_tagged' : '';
            if ($outcome === 'wrong') {
                $pair = $path . "\t" . $row['truth'] . "\t" . $voted;
                $stats['confusion' . $suffix][$pair] = ($stats['confusion' . $suffix][$pair] ?? 0) + 1;
            }
            if ($path === 'a') {
                $color('by_color' . $suffix, $row['truth'], $outcome);
            }
            if ($outcome === 'blank') {
                continue;
            }
            if ($set === 'folder' && $path === 'a') {
                $band('a_share', $share((float)$row['a_folder_share']), $outcome);
                $band('a_support', pr_colorway_report_support_band((int)$row['a_folder_support']), $outcome);
                $band('a_similarity', $sim((float)$row['folder_top_similarity']), $outcome);
            } elseif ($set === 'folder') {
                $band('b_share', $share((float)$row['b_folder_share']), $outcome);
            } elseif ($path === 'a') {
                $band('at_share', $share((float)$row['a_tagged_share']), $outcome);
                $band('at_support', pr_colorway_report_support_band((int)$row['a_tagged_support']), $outcome);
            } else {
                $band('bt_share', $share((float)$row['b_tagged_share']), $outcome);
            }
        }
    }
}

/**
 * Summary lines for stderr.
 *
 * @return list<string>
 */
function pr_colorway_report_summary(array $stats): array
{
    $pct = static fn(int $n, int $d): string => $d > 0 ? sprintf('%5.1f%%', 100 * $n / $d) : '    -';
    $lines = [];
    $lines[] = sprintf('Considered              %d', $stats['considered']);
    $lines[] = sprintf('  stored label not a color (skipped)    %d', $stats['truth_not_a_color']);
    $lines[] = sprintf('  no vector at the service (skipped)    %d', $stats['no_vector']);
    $lines[] = sprintf('  no stored product (path a n/a)        %d', $stats['no_model']);
    $lines[] = sprintf('  no folder key (self figures only)     %d', $stats['no_folder_key']);
    $lines[] = sprintf('  fewer than k folder neighbours (same) %d', $stats['folder_underfilled']);
    $lines[] = sprintf('  fewer than k tagged neighbours (same) %d', $stats['tagged_underfilled']);
    $lines[] = sprintf('  same-shoot neighbours passed over     %d', $stats['dropped_same_folder']);
    $lines[] = '';
    $lines[] = 'path     set         voted  correct  wrong  blank  precision  coverage';
    $sets = [
        'self' => 'self', 'folder' => 'folder',
        'folder_nodup' => 'folder<' . PR_COLORWAY_REPORT_NEAR_DUPLICATE, 'tagged' => 'tagged',
    ];
    foreach ($stats['paths'] as $key => $cell) {
        $voted = $cell['correct'] + $cell['wrong'];
        $all = $voted + $cell['blank'];
        $path = $key[0];
        $set = $sets[substr($key, 2)] ?? substr($key, 2);
        $lines[] = sprintf(
            '%-8s %-11s %5d  %7d  %5d  %5d  %9s  %8s',
            $path === 'a' ? 'a name' : 'b e2e', $set, $voted,
            $cell['correct'], $cell['wrong'], $cell['blank'],
            $pct($cell['correct'], $voted), $pct($voted, $all)
        );
    }
    $titles = [
        'a_share' => 'path a, folder set, by colorway vote share',
        'a_support' => 'path a, folder set, by neighbours carrying the winner',
        'a_similarity' => 'path a, folder set, by top similarity',
        'b_share' => 'path b, folder set, by Name share',
        'at_share' => 'path a, tagged set, by colorway vote share',
        'at_support' => 'path a, tagged set, by neighbours carrying the winner',
        'bt_share' => 'path b, tagged set, by Name share',
    ];
    foreach ($titles as $key => $title) {
        $lines[] = '';
        $lines[] = "$title: band  correct  wrong  precision";
        $bands = $stats['bands'][$key];
        ksort($bands);
        foreach ($bands as $label => $cell) {
            $c = (int)($cell['correct'] ?? 0);
            $w = (int)($cell['wrong'] ?? 0);
            $lines[] = sprintf('  %-6s %7d  %5d  %9s', $label, $c, $w, $pct($c, $c + $w));
        }
    }
    $total = static fn(array $cell): int
        => (int)($cell['correct'] ?? 0) + (int)($cell['wrong'] ?? 0) + (int)($cell['blank'] ?? 0);
    foreach (['folder' => '', 'tagged' => '_tagged'] as $set => $suffix) {
        $lines[] = '';
        $lines[] = "Path a, $set set, by stored colorway (top 15): correct  wrong  blank  precision";
        $by_color = $stats['by_color' . $suffix];
        uasort($by_color, static fn($x, $y) => $total($y) <=> $total($x));
        foreach (array_slice($by_color, 0, 15, true) as $cell) {
            $c = (int)($cell['correct'] ?? 0);
            $w = (int)($cell['wrong'] ?? 0);
            $lines[] = sprintf('  %-22s %7d  %5d  %5d  %9s', $cell['label'], $c, $w, (int)($cell['blank'] ?? 0), $pct($c, $c + $w));
        }
        $lines[] = '';
        $lines[] = "Most common wrong votes, $set set (path, stored, voted, count):";
        $confusion = $stats['confusion' . $suffix];
        arsort($confusion);
        foreach (array_slice($confusion, 0, 15, true) as $pair => $n) {
            $lines[] = '  ' . $pair . "\t" . $n;
        }
    }
    return $lines;
}

/**
 * Lifestyle photos for the report. Deterministic spread: ordered by CRC32 of the ref, so a
 * bounded run samples the whole library instead of the oldest shoot.
 *
 * @return list<array{resource:int, colorway:string}> colorway is '' when $tagged is false
 */
function pr_colorway_report_photos(bool $tagged, int $limit): array
{
    $photocat = pr_field_ref_by_name('photocategory');
    $colorway = pr_sheet_field('colorway');
    if ($photocat <= 0 || $colorway <= 0) {
        throw new RuntimeException('colorway report: Photo Category or Colorway field not found');
    }
    $leaf = "NOT EXISTS (SELECT 1 FROM node ch WHERE ch.parent = cn.ref)";
    if ($tagged) {
        $sql = "SELECT r.ref AS resource, MIN(cn.name) AS colorway
                  FROM resource r
                  JOIN resource_node pc ON pc.resource = r.ref
                  JOIN node pcn ON pcn.ref = pc.node AND pcn.resource_type_field = ? AND pcn.name = 'Lifestyle'
                  JOIN resource_dinov2_vector v ON v.resource = r.ref
                  JOIN resource_node c ON c.resource = r.ref
                  JOIN node cn ON cn.ref = c.node AND cn.resource_type_field = ? AND $leaf
                 WHERE r.ref > 0 AND r.archive >= 0
                 GROUP BY r.ref
                HAVING COUNT(DISTINCT cn.ref) = 1
                 ORDER BY CRC32(r.ref), r.ref";
    } else {
        $sql = "SELECT r.ref AS resource, '' AS colorway
                  FROM resource r
                  JOIN resource_node pc ON pc.resource = r.ref
                  JOIN node pcn ON pcn.ref = pc.node AND pcn.resource_type_field = ? AND pcn.name = 'Lifestyle'
                  JOIN resource_dinov2_vector v ON v.resource = r.ref
                 WHERE r.ref > 0 AND r.archive >= 0
                   AND NOT EXISTS (SELECT 1 FROM resource_node c
                                     JOIN node cn ON cn.ref = c.node AND cn.resource_type_field = ?
                                    WHERE c.resource = r.ref)
                 ORDER BY CRC32(r.ref), r.ref";
    }
    $params = ['i', $photocat, 'i', $colorway];
    if ($limit > 0) {
        $sql .= ' LIMIT ?';
        $params[] = 'i';
        $params[] = $limit;
    }
    $out = [];
    foreach (ps_query($sql, $params) as $row) {
        $out[] = ['resource' => (int)$row['resource'], 'colorway' => (string)$row['colorway']];
    }
    return $out;
}

/**
 * Single colorway leaf (blank for none or several), folder keys
 * (pr_colorway_report_folder_keys) and every Folder Path node, for a batch of resources in
 * two queries.
 *
 * @param list<int> $resources
 * @return array{colorway:array<int,string>, folders:array<int,array<int,true>>, paths:array<int,array<int,true>>}
 */
function pr_colorway_report_facts(array $resources): array
{
    $resources = array_values(array_unique(array_filter(array_map('intval', $resources))));
    $out = ['colorway' => [], 'folders' => [], 'paths' => []];
    if ($resources === []) {
        return $out;
    }
    $in = ps_param_insert(count($resources));
    $out['colorway'] = pr_colorway_report_colorways($resources);
    $folderpath = pr_field_ref_by_name('folderpath');
    if ($folderpath > 0) {
        $nodes = [];
        foreach (ps_query(
            "SELECT rn.resource, n.ref, n.parent FROM resource_node rn
               JOIN node n ON n.ref = rn.node AND n.resource_type_field = ?
              WHERE rn.resource IN ($in)",
            array_merge(['i', $folderpath], ps_param_fill($resources, 'i'))
        ) as $row) {
            $nodes[(int)$row['resource']][] = ['ref' => (int)$row['ref'], 'parent' => (int)$row['parent']];
            $out['paths'][(int)$row['resource']][(int)$row['ref']] = true;
        }
        foreach ($nodes as $resource => $list) {
            $keys = pr_colorway_report_folder_keys($list);
            if ($keys !== []) {
                $out['folders'][$resource] = $keys;
            }
        }
    }
    return $out;
}

/**
 * Single colorway leaf per resource (blank for none or several), in one query.
 *
 * @param list<int> $resources
 * @return array<int,string>
 */
function pr_colorway_report_colorways(array $resources): array
{
    $resources = array_values(array_unique(array_filter(array_map('intval', $resources))));
    if ($resources === []) {
        return [];
    }
    $leaf = "NOT EXISTS (SELECT 1 FROM node ch WHERE ch.parent = n.ref)";
    $names = [];
    foreach (ps_query(
        "SELECT rn.resource, n.name FROM resource_node rn
           JOIN node n ON n.ref = rn.node AND n.resource_type_field = ? AND $leaf
          WHERE rn.resource IN (" . ps_param_insert(count($resources)) . ")",
        array_merge(['i', pr_sheet_field('colorway')], ps_param_fill($resources, 'i'))
    ) as $row) {
        $names[(int)$row['resource']][] = (string)$row['name'];
    }
    $out = [];
    foreach ($resources as $resource) {
        $list = $names[$resource] ?? [];
        $out[$resource] = count($list) === 1 ? $list[0] : '';
    }
    return $out;
}

/**
 * /identify by stored vector. Null on 404 (no vector); throws when the service is down.
 */
function pr_colorway_report_identify(int $resource, int $wide): ?array
{
    global $product_recognition_space;
    $http_status = 0;
    $result = pr_service_post('/identify', [
        'db' => $GLOBALS['mysql_db'],
        'space' => $product_recognition_space,
        'resource' => $resource,
        'k' => $wide,
        'top_n' => 3,
    ], null, 600, 10, $http_status);
    if ($result === null) {
        if ($http_status === 404) {
            return null;
        }
        throw new RuntimeException('colorway report: vector service did not answer');
    }
    return $result;
}

/**
 * Attach the batch-looked-up colorway to each neighbour.
 *
 * @param list<array> $neighbors
 * @param array<int,string> $colorways
 * @return list<array>
 */
function pr_colorway_report_with_colorways(array $neighbors, array $colorways): array
{
    $out = [];
    foreach ($neighbors as $neighbor) {
        if (!is_array($neighbor)) {
            continue;
        }
        $neighbor['colorway'] = $colorways[(int)($neighbor['resource'] ?? 0)] ?? '';
        $neighbor['colorway_resolved'] = true;
        $out[] = $neighbor;
    }
    return $out;
}

/**
 * Held-out accuracy over tagged lifestyle photos. $on_row receives one TSV-ready row per
 * photo as it is measured, so a later throw does not lose the rows already printed.
 *
 * @param callable(array):void $on_row
 */
function pr_colorway_accuracy_report(int $limit, int $k, int $wide, callable $on_row): array
{
    $leaf_models = pr_leaf_models();
    $product_field = pr_product_field();
    $stats = pr_colorway_report_new_stats();
    # Facts per resource, kept across photos: each photo pulls up to $wide neighbours from
    # the same reference set, so most lookups repeat. colorway has an entry per looked-up
    # resource; folders only for a resource with a key.
    $facts = ['colorway' => [], 'folders' => [], 'paths' => []];

    foreach (pr_colorway_report_photos(true, $limit) as $photo) {
        $resource = $photo['resource'];
        $truth = $photo['colorway'];
        if (!pr_colorway_label_counts($truth)) {
            $stats['truth_not_a_color']++;
            continue;
        }
        $result = pr_colorway_report_identify($resource, $wide);
        if ($result === null) {
            $stats['no_vector']++;
            continue;
        }
        $neighbors = is_array($result['neighbors'] ?? null) ? $result['neighbors'] : [];
        $refs = array_map(static fn($n) => (int)($n['resource'] ?? 0), $neighbors);
        $refs[] = $resource;
        $missing = array_filter($refs, static fn(int $r): bool => !isset($facts['colorway'][$r]));
        if ($missing !== []) {
            $new = pr_colorway_report_facts($missing);
            $facts['colorway'] += $new['colorway'];
            $facts['folders'] += $new['folders'];
            $facts['paths'] += $new['paths'];
        }
        $neighbors = pr_colorway_report_with_colorways($neighbors, $facts['colorway']);
        $query_keys = $facts['folders'][$resource] ?? [];
        $sets = pr_colorway_report_split_neighbors($neighbors, $query_keys, $facts['paths'], $k);
        $names = pr_field_leaf_names($resource, $product_field);
        $self = pr_colorway_report_votes($sets['self'], $leaf_models, $names);
        $folder = pr_colorway_report_votes($sets['folder'], $leaf_models, $names);
        $tagged = pr_colorway_report_votes($sets['tagged'], $leaf_models, $names);
        $row = [
            'resource' => $resource,
            'truth' => $truth,
            'model' => implode('|', $names),
            'a_self' => $self['a'],
            'a_folder' => $folder['a'],
            'a_folder_share' => $folder['a_share'],
            'a_folder_support' => $folder['a_support'],
            'b_self' => $self['b'],
            'b_folder' => $folder['b'],
            'b_folder_name' => $folder['b_name'],
            'b_folder_share' => $folder['b_share'],
            'folder_top_similarity' => (float)($sets['folder'][0]['similarity'] ?? 0.0),
            'dropped' => $sets['dropped'],
            'folder_size' => count($sets['folder']),
            'has_folder_key' => $query_keys !== [],
            'folder_counted' => $query_keys !== [] && count($sets['folder']) >= $k,
            'a_tagged' => $tagged['a'],
            'a_tagged_share' => $tagged['a_share'],
            'a_tagged_support' => $tagged['a_support'],
            'b_tagged' => $tagged['b'],
            'b_tagged_share' => $tagged['b_share'],
            'tagged_size' => count($sets['tagged']),
            'tagged_counted' => $query_keys !== [] && count($sets['tagged']) >= $k,
        ];
        pr_colorway_report_tally($stats, $row);
        $on_row($row);
    }
    return $stats;
}

/**
 * TSV fields for one accuracy row, in pr_colorway_report_header() order.
 *
 * @return list<string>
 */
function pr_colorway_report_fields(array $row): array
{
    $cell = static fn($v): string => $v === null ? 'n/a' : (string)$v;
    return [
        (string)$row['resource'],
        $row['truth'],
        $row['model'],
        $cell($row['a_self']),
        $cell($row['a_folder']),
        sprintf('%.3f', $row['a_folder_share']),
        (string)$row['a_folder_support'],
        $row['b_self'],
        $row['b_folder'],
        $row['b_folder_name'],
        sprintf('%.3f', $row['b_folder_share']),
        sprintf('%.3f', $row['folder_top_similarity']),
        (string)$row['dropped'],
        (string)$row['folder_size'],
        $row['folder_counted'] ? '1' : '0',
        $cell($row['a_tagged']),
        sprintf('%.3f', $row['a_tagged_share']),
        (string)$row['a_tagged_support'],
        $row['b_tagged'],
        (string)$row['tagged_size'],
        $row['tagged_counted'] ? '1' : '0',
    ];
}

/** @return list<string> */
function pr_colorway_report_header(): array
{
    return [
        'resource', 'stored_colorway', 'stored_product', 'a_self', 'a_folder', 'a_folder_share',
        'a_folder_support', 'b_self', 'b_folder', 'b_folder_name', 'b_folder_share',
        'folder_top_similarity', 'same_folder_dropped', 'folder_size', 'folder_counted',
        'a_tagged', 'a_tagged_share', 'a_tagged_support', 'b_tagged', 'tagged_size', 'tagged_counted',
    ];
}

/**
 * What the neighbour vote alone would give untagged lifestyle photos (path a when the photo
 * has a product, else path b), two ways: over the k nearest references (as production
 * votes today) and over the k nearest references that carry a countable colorway (the
 * tagged set, without the shoot rule: an untagged photo's tagged shoot-mates and copies
 * are real evidence). Nothing is written. It does not model the view's fallbacks: a blank
 * vote there falls through to the one-colour catalog (pr_identity_colorway), and path a
 * there needs the stored Name among the top_n candidates.
 *
 * @param callable(array):void $on_row
 * @return array{considered:int, no_vector:int, voted:int, blank:int, voted_tagged:int,
 *               blank_tagged:int, by_colorway:array<string,int>, by_colorway_tagged:array<string,int>}
 */
function pr_colorway_projection(int $limit, int $k, int $wide, callable $on_row): array
{
    $leaf_models = pr_leaf_models();
    $product_field = pr_product_field();
    $stats = [
        'considered' => 0, 'no_vector' => 0, 'voted' => 0, 'blank' => 0,
        'voted_tagged' => 0, 'blank_tagged' => 0, 'by_colorway' => [], 'by_colorway_tagged' => [],
    ];
    $colorways = [];
    $labels = [];

    foreach (pr_colorway_report_photos(false, $limit) as $photo) {
        $resource = $photo['resource'];
        $result = pr_colorway_report_identify($resource, $wide);
        if ($result === null) {
            $stats['no_vector']++;
            continue;
        }
        $stats['considered']++;
        $neighbors = is_array($result['neighbors'] ?? null) ? $result['neighbors'] : [];
        $refs = array_map(static fn($n) => (int)($n['resource'] ?? 0), $neighbors);
        $missing = array_filter($refs, static fn(int $r): bool => !isset($colorways[$r]));
        if ($missing !== []) {
            $colorways += pr_colorway_report_colorways($missing);
        }
        $neighbors = pr_colorway_report_with_colorways($neighbors, $colorways);
        $names = pr_field_leaf_names($resource, $product_field);
        $path = $names !== [] ? 'a' : 'b';
        $out = [(string)$resource, $path];
        foreach (['' => array_slice($neighbors, 0, $k),
                  '_tagged' => pr_colorway_report_tagged_neighbors($neighbors, $k)] as $suffix => $set) {
            $votes = pr_colorway_report_votes($set, $leaf_models, $names);
            $voted = $path === 'a' ? (string)$votes['a'] : $votes['b'];
            if ($voted === '') {
                $stats['blank' . $suffix]++;
            } else {
                $stats['voted' . $suffix]++;
                # Folded, as the accuracy tables are; shown by the first spelling seen.
                $fold = pr_fold_label($voted);
                $labels[$fold] ??= $voted;
                $key = $labels[$fold];
                $stats['by_colorway' . $suffix][$key] = ($stats['by_colorway' . $suffix][$key] ?? 0) + 1;
            }
            # Path b's Name is won per set, so each vote carries the product it was won under.
            array_push(
                $out,
                $names !== [] ? implode('|', $names) : $votes['b_name'],
                $voted,
                sprintf('%.3f', $path === 'a' ? $votes['a_share'] : $votes['b_share']),
                $path === 'a' ? (string)$votes['a_support'] : '',
                sprintf('%.3f', (float)($set[0]['similarity'] ?? 0.0))
            );
        }
        $on_row($out);
    }
    arsort($stats['by_colorway']);
    arsort($stats['by_colorway_tagged']);
    return $stats;
}

/** @return list<string> */
function pr_colorway_projection_header(): array
{
    return [
        'resource', 'path',
        'product', 'voted', 'share', 'support', 'top_similarity',
        'product_tagged', 'voted_tagged', 'share_tagged', 'support_tagged', 'top_similarity_tagged',
    ];
}

/*
 * Cutout (crop) accuracy: colorway from the product in the frame, not the whole image.
 *
 * Test set: tagged lifestyle photos (one colorway leaf) with a stored Name and cached
 * product regions. Each region's cutout is scored two ways:
 *   match  /identify on the cutout image; vote over the tagged set (k nearest references
 *          with a countable colorway, same shoot and near-copies excluded), restricted to
 *          the photo's stored Name (pr_voted_colorway_detail_for_names, path a). A photo
 *          with no folder key cannot have its shoot excluded, so it is skipped and counted
 *   pixel  median cutout colour against the primary Name's catalog swatches
 *          (pr_primary_tagged_model, then pr_colorway_from_mask, as the view does)
 * A photo's vote per method is the colorway its regions agree on; regions that disagree
 * leave it blank (a resource has one Colorway field). No stored Name, no vote: the
 * colorway is never chosen without the product.
 */

/**
 * One photo-level vote from per-region votes: the shared non-blank colorway, or blank
 * when none voted or the regions disagree.
 *
 * @param list<string> $votes
 * @return array{colorway:string, conflict:bool}
 */
function pr_colorway_crop_photo_vote(array $votes): array
{
    $seen = [];
    foreach ($votes as $vote) {
        $vote = (string)$vote;
        if (trim($vote) === '') {
            continue;
        }
        $seen[pr_fold_label($vote)] ??= $vote;
    }
    if (count($seen) === 1) {
        return ['colorway' => (string)array_values($seen)[0], 'conflict' => false];
    }
    return ['colorway' => '', 'conflict' => count($seen) > 1];
}

/**
 * True when any region voted the stored colorway (a multi-bag photo may show it on one bag).
 *
 * @param list<string> $votes
 */
function pr_colorway_crop_any_match(string $truth, array $votes): bool
{
    foreach ($votes as $vote) {
        if (pr_colorway_report_outcome($truth, (string)$vote) === 'correct') {
            return true;
        }
    }
    return false;
}

function pr_colorway_crop_new_stats(): array
{
    $cell = ['correct' => 0, 'wrong' => 0, 'blank' => 0];
    return [
        'considered' => 0, 'truth_not_a_color' => 0, 'no_product' => 0, 'no_folder_key' => 0,
        'no_cutout' => 0, 'regions' => 0, 'multi_region' => 0, 'tagged_underfilled' => 0,
        'mask_missing' => 0, 'identify_failed' => 0,
        'methods' => ['match' => $cell, 'pixel' => $cell],
        'conflict' => ['match' => 0, 'pixel' => 0],
        'any' => ['match' => 0, 'pixel' => 0],
        'bands' => ['share' => [], 'support' => []],
        'by_color' => ['match' => [], 'pixel' => []],
        'confusion' => [],
    ];
}

/**
 * @param array{truth:string, regions:int, match:array{colorway:string,conflict:bool},
 *              pixel:array{colorway:string,conflict:bool}, match_share:float,
 *              match_support:int, any_match:bool, any_pixel:bool} $row
 */
function pr_colorway_crop_tally(array &$stats, array $row): void
{
    $stats['considered']++;
    $stats['regions'] += (int)$row['regions'];
    if ((int)$row['regions'] > 1) {
        $stats['multi_region']++;
    }
    foreach (['match', 'pixel'] as $method) {
        $vote = $row[$method];
        $outcome = pr_colorway_report_outcome($row['truth'], $vote['colorway']);
        $stats['methods'][$method][$outcome]++;
        if ($vote['conflict']) {
            $stats['conflict'][$method]++;
        }
        if ($row['any_' . $method]) {
            $stats['any'][$method]++;
        }
        $fold = pr_fold_label($row['truth']);
        $stats['by_color'][$method][$fold]['label'] ??= $row['truth'];
        $stats['by_color'][$method][$fold][$outcome] = ($stats['by_color'][$method][$fold][$outcome] ?? 0) + 1;
        if ($outcome === 'wrong') {
            $pair = $method . "\t" . $row['truth'] . "\t" . $vote['colorway'];
            $stats['confusion'][$pair] = ($stats['confusion'][$pair] ?? 0) + 1;
        }
        if ($method === 'match' && $outcome !== 'blank') {
            $share = pr_colorway_report_band((float)$row['match_share'], PR_COLORWAY_REPORT_SHARE_BANDS);
            $stats['bands']['share'][$share][$outcome] = ($stats['bands']['share'][$share][$outcome] ?? 0) + 1;
            $support = pr_colorway_report_support_band((int)$row['match_support']);
            $stats['bands']['support'][$support][$outcome] = ($stats['bands']['support'][$support][$outcome] ?? 0) + 1;
        }
    }
}

/** @return list<string> */
function pr_colorway_crop_summary(array $stats): array
{
    $pct = static fn(int $n, int $d): string => $d > 0 ? sprintf('%5.1f%%', 100 * $n / $d) : '    -';
    $lines = [];
    $lines[] = sprintf('Considered              %d', $stats['considered']);
    $lines[] = sprintf('  stored label not a color (skipped)  %d', $stats['truth_not_a_color']);
    $lines[] = sprintf('  no stored product (skipped)         %d', $stats['no_product']);
    $lines[] = sprintf('  no folder key (skipped)             %d', $stats['no_folder_key']);
    $lines[] = sprintf('  no usable cutout (skipped)          %d', $stats['no_cutout']);
    $lines[] = sprintf('  cutouts scored %d; photos with more than one %d', $stats['regions'], $stats['multi_region']);
    $lines[] = sprintf('  cutouts with fewer than k tagged neighbours %d', $stats['tagged_underfilled']);
    $lines[] = sprintf('  regions with no cutout file (not scored)    %d', $stats['mask_missing']);
    $lines[] = sprintf('  cutouts the service did not answer (not scored) %d', $stats['identify_failed']);
    $lines[] = '';
    $lines[] = 'method  voted  correct  wrong  blank  conflict  precision  coverage  any-region-right';
    foreach ($stats['methods'] as $method => $cell) {
        $voted = $cell['correct'] + $cell['wrong'];
        $all = $voted + $cell['blank'];
        $lines[] = sprintf('%-6s  %5d  %7d  %5d  %5d  %8d  %9s  %8s  %d',
            $method, $voted, $cell['correct'], $cell['wrong'], $cell['blank'],
            $stats['conflict'][$method], $pct($cell['correct'], $voted), $pct($voted, $all),
            $stats['any'][$method]);
    }
    foreach (['share' => 'match, by colorway vote share', 'support' => 'match, by neighbours carrying the winner'] as $key => $title) {
        $lines[] = '';
        $lines[] = "$title: band  correct  wrong  precision";
        $bands = $stats['bands'][$key];
        ksort($bands);
        foreach ($bands as $label => $cell) {
            $c = (int)($cell['correct'] ?? 0);
            $w = (int)($cell['wrong'] ?? 0);
            $lines[] = sprintf('  %-6s %7d  %5d  %9s', $label, $c, $w, $pct($c, $c + $w));
        }
    }
    $total = static fn(array $cell): int
        => (int)($cell['correct'] ?? 0) + (int)($cell['wrong'] ?? 0) + (int)($cell['blank'] ?? 0);
    foreach (['match', 'pixel'] as $method) {
        $lines[] = '';
        $lines[] = "$method, by stored colorway (top 15): correct  wrong  blank  precision";
        $by_color = $stats['by_color'][$method];
        uasort($by_color, static fn($x, $y) => $total($y) <=> $total($x));
        foreach (array_slice($by_color, 0, 15, true) as $cell) {
            $c = (int)($cell['correct'] ?? 0);
            $w = (int)($cell['wrong'] ?? 0);
            $lines[] = sprintf('  %-22s %7d  %5d  %5d  %9s', $cell['label'], $c, $w, (int)($cell['blank'] ?? 0), $pct($c, $c + $w));
        }
    }
    $lines[] = '';
    $lines[] = 'Most common wrong votes (method, stored, voted, count):';
    $confusion = $stats['confusion'];
    arsort($confusion);
    foreach (array_slice($confusion, 0, 15, true) as $pair => $n) {
        $lines[] = '  ' . $pair . "\t" . $n;
    }
    return $lines;
}

/**
 * Tagged lifestyle photos with a non-empty product_region row, CRC32-ordered.
 *
 * @return list<array{resource:int, colorway:string}>
 */
function pr_colorway_crop_photos(int $limit): array
{
    $photocat = pr_field_ref_by_name('photocategory');
    $colorway = pr_sheet_field('colorway');
    if ($photocat <= 0 || $colorway <= 0) {
        throw new RuntimeException('colorway crop report: Photo Category or Colorway field not found');
    }
    $sql = "SELECT r.ref AS resource, MIN(cn.name) AS colorway
              FROM resource r
              JOIN product_region pr ON pr.resource = r.ref AND pr.bbox IS NOT NULL AND pr.bbox NOT IN ('', '[]')
              JOIN resource_node pc ON pc.resource = r.ref
              JOIN node pcn ON pcn.ref = pc.node AND pcn.resource_type_field = ? AND pcn.name = 'Lifestyle'
              JOIN resource_node c ON c.resource = r.ref
              JOIN node cn ON cn.ref = c.node AND cn.resource_type_field = ?
                   AND NOT EXISTS (SELECT 1 FROM node ch WHERE ch.parent = cn.ref)
             WHERE r.ref > 0 AND r.archive >= 0
             GROUP BY r.ref
            HAVING COUNT(DISTINCT cn.ref) = 1
             ORDER BY CRC32(r.ref), r.ref";
    $params = ['i', $photocat, 'i', $colorway];
    if ($limit > 0) {
        $sql .= ' LIMIT ?';
        $params[] = 'i';
        $params[] = $limit;
    }
    $out = [];
    foreach (ps_query($sql, $params) as $row) {
        $out[] = ['resource' => (int)$row['resource'], 'colorway' => (string)$row['colorway']];
    }
    return $out;
}

/**
 * /identify on a cutout image. Null when this cutout got no answer (a bad PNG, or a mask
 * purged mid-run); the caller counts it and stops the run after several in a row, which
 * means the service itself is down.
 */
function pr_colorway_crop_identify(string $mask, int $wide): ?array
{
    global $product_recognition_space;
    return pr_service_post('/identify', [
        'db' => $GLOBALS['mysql_db'],
        'space' => $product_recognition_space,
        'k' => $wide,
        'top_n' => 3,
    ], $mask, 600, 10);
}

const PR_COLORWAY_CROP_MAX_FAILURES_IN_A_ROW = 5;

/**
 * Read-only cutout accuracy. Reads cached regions and mask files; never segments, and
 * never writes the identify cache (calls /identify directly).
 *
 * @param callable(array):void $on_row
 */
function pr_colorway_crop_report(int $limit, int $k, int $wide, callable $on_row): array
{
    $leaf_models = pr_leaf_models();
    $product_field = pr_product_field();
    $catalog = pr_catalog_picker_rows();
    $stats = pr_colorway_crop_new_stats();
    $facts = ['colorway' => [], 'folders' => [], 'paths' => []];
    $failures_in_a_row = 0;

    foreach (pr_colorway_crop_photos($limit) as $photo) {
        $resource = $photo['resource'];
        $truth = $photo['colorway'];
        if (!pr_colorway_label_counts($truth)) {
            $stats['truth_not_a_color']++;
            continue;
        }
        $names = pr_field_leaf_names($resource, $product_field);
        if ($names === []) {
            $stats['no_product']++;
            continue;
        }
        if (!isset($facts['colorway'][$resource])) {
            $new = pr_colorway_report_facts([$resource]);
            $facts['colorway'] += $new['colorway'];
            $facts['folders'] += $new['folders'];
            $facts['paths'] += $new['paths'];
        }
        # No folder key, no shoot exclusion: same-shoot siblings tagged from the same folder
        # name would vote, as the accuracy report keeps such photos out of its tagged figures.
        $query_keys = $facts['folders'][$resource] ?? [];
        if ($query_keys === []) {
            $stats['no_folder_key']++;
            continue;
        }
        $data = get_resource_data($resource);
        $checksum = $data === false ? '' : (string)$data['file_checksum'];
        $masks = [];
        foreach (pr_cached_regions($resource) as $i => $_bbox) {
            $mask = pr_mask_file($resource, $checksum, $i);
            if (is_file($mask)) {
                $masks[] = $mask;
            } else {
                $stats['mask_missing']++;
            }
        }
        if ($masks === []) {
            $stats['no_cutout']++;
            continue;
        }
        $pixel_model = pr_primary_tagged_model($names, $catalog);
        $match_votes = [];
        $pixel_votes = [];
        $best_share = 0.0;
        $best_support = 0;
        foreach ($masks as $mask) {
            $result = pr_colorway_crop_identify($mask, $wide);
            if ($result === null) {
                $stats['identify_failed']++;
                if (++$failures_in_a_row >= PR_COLORWAY_CROP_MAX_FAILURES_IN_A_ROW) {
                    throw new RuntimeException('colorway crop report: vector service did not answer '
                        . PR_COLORWAY_CROP_MAX_FAILURES_IN_A_ROW . ' cutouts in a row');
                }
                continue;
            }
            $failures_in_a_row = 0;
            $neighbors = is_array($result['neighbors'] ?? null) ? $result['neighbors'] : [];
            # An uploaded image has no resource to exclude; drop the photo's own vector.
            $neighbors = array_values(array_filter(
                $neighbors, static fn($n) => (int)($n['resource'] ?? 0) !== $resource
            ));
            $refs = array_map(static fn($n) => (int)($n['resource'] ?? 0), $neighbors);
            $refs[] = $resource;
            $missing = array_filter($refs, static fn(int $r): bool => !isset($facts['colorway'][$r]));
            if ($missing !== []) {
                $new = pr_colorway_report_facts($missing);
                $facts['colorway'] += $new['colorway'];
                $facts['folders'] += $new['folders'];
                $facts['paths'] += $new['paths'];
            }
            $neighbors = pr_colorway_report_with_colorways($neighbors, $facts['colorway']);
            $sets = pr_colorway_report_split_neighbors($neighbors, $query_keys, $facts['paths'], $k);
            if (count($sets['tagged']) < $k) {
                $stats['tagged_underfilled']++;
            }
            $vote = pr_voted_colorway_detail_for_names($sets['tagged'], $leaf_models, $names);
            $match_votes[] = $vote['colorway'];
            if ($vote['colorway'] !== '' && $vote['share'] >= $best_share) {
                $best_share = $vote['share'];
                $best_support = $vote['support'];
            }
            $pixel_votes[] = pr_colorway_from_mask($mask, $pixel_model, $catalog);
        }
        $row = [
            'resource' => $resource,
            'truth' => $truth,
            'product' => implode('|', $names),
            'regions' => count($masks),
            'match' => pr_colorway_crop_photo_vote($match_votes),
            'pixel' => pr_colorway_crop_photo_vote($pixel_votes),
            'match_share' => $best_share,
            'match_support' => $best_support,
            'any_match' => pr_colorway_crop_any_match($truth, $match_votes),
            'any_pixel' => pr_colorway_crop_any_match($truth, $pixel_votes),
            'match_votes' => $match_votes,
            'pixel_votes' => $pixel_votes,
        ];
        pr_colorway_crop_tally($stats, $row);
        $on_row($row);
    }
    return $stats;
}

/** @return list<string> */
function pr_colorway_crop_header(): array
{
    return ['resource', 'stored_colorway', 'stored_product', 'regions', 'match', 'match_share',
            'match_support', 'pixel', 'match_by_region', 'pixel_by_region'];
}

/** @return list<string> */
function pr_colorway_crop_fields(array $row): array
{
    return [
        (string)$row['resource'], $row['truth'], $row['product'], (string)$row['regions'],
        $row['match']['colorway'], sprintf('%.3f', $row['match_share']), (string)$row['match_support'],
        $row['pixel']['colorway'], implode('|', $row['match_votes']), implode('|', $row['pixel_votes']),
    ];
}
