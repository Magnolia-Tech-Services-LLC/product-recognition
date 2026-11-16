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
 *   folder  the k nearest references not in the same Folder Path leaf as the photo, so a
 *           sibling from the same shoot, tagged from the same folder name, cannot vote
 *
 * Two vote paths, because they would write different photos:
 *   a  Name known: pr_voted_colorway_for_names with the photo's stored product leaf
 *   b  End to end: pr_aggregate_service_neighbors (family, then name, then colorway)
 *
 * The test set is biased: a lifestyle photo has a colorway mostly because its folder or
 * filename named one. Read the result as an upper bound for untagged lifestyle photos.
 */

const PR_COLORWAY_REPORT_SHARE_BANDS = [0.6, 0.7, 0.8, 0.9, 1.01];
const PR_COLORWAY_REPORT_SIMILARITY_BANDS = [0.4, 0.5, 0.6, 0.7, 0.8, 1.01];

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
            return $edge > 1.0 ? '<=1.0' : sprintf('<%.1f', $edge);
        }
    }
    return '<=1.0';
}

/**
 * Split /identify neighbours (already self-excluded, nearest first) into the two sets.
 * A neighbour is dropped from the folder set when it shares any Folder Path leaf with
 * the query photo. A query with no folder leaf drops nothing.
 *
 * @param list<array{resource?:int}> $neighbors
 * @param array<int,true> $query_folders folder leaf node => true
 * @param array<int,array<int,true>> $folders_by_resource
 * @return array{self:list<array>, folder:list<array>, dropped:int}
 */
function pr_colorway_report_split_neighbors(array $neighbors, array $query_folders,
                                            array $folders_by_resource, int $k): array
{
    $self = [];
    $folder = [];
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
            $dropped++;
            continue;
        }
        if (count($folder) < $k) {
            $folder[] = $neighbor;
        }
        if (count($self) >= $k && count($folder) >= $k) {
            break;
        }
    }
    return ['self' => $self, 'folder' => $folder, 'dropped' => $dropped];
}

/**
 * The two vote paths over one neighbour set. Neighbours must already carry 'colorway'.
 *
 * @param list<array> $neighbors
 * @param array<int,string> $leaf_models
 * @param list<string> $names stored product leaf names on the photo
 * @return array{a:?string, a_share:float, b:string, b_name:string}
 */
function pr_colorway_report_votes(array $neighbors, array $leaf_models, array $names): array
{
    $a = null;
    $a_share = 0.0;
    if ($names !== []) {
        $detail = pr_voted_colorway_detail_for_names($neighbors, $leaf_models, $names);
        $a = $detail['colorway'];
        $a_share = $detail['share'];
    }
    $agg = pr_aggregate_service_neighbors($neighbors, $leaf_models);
    $b_name = $agg['suggest'] ? (string)$agg['name'] : '';
    return [
        'a' => $a,
        'a_share' => $a_share,
        'b' => $b_name !== '' ? (string)$agg['colorway'] : '',
        'b_name' => $b_name,
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
        'dropped_same_folder' => 0,
        'paths' => [
            'a_self' => $cell, 'a_folder' => $cell,
            'b_self' => $cell, 'b_folder' => $cell,
        ],
        'share_bands' => [],
        'similarity_bands' => [],
        'confusion' => [],
    ];
}

/**
 * Count one photo. $row carries truth and, per path and set, the voted colorway
 * (null for path a when the photo has no stored product).
 *
 * @param array{truth:string, a_self:?string, a_folder:?string, b_self:string, b_folder:string,
 *              a_folder_share:float, folder_top_similarity:float, dropped:int} $row
 */
function pr_colorway_report_tally(array &$stats, array $row): void
{
    $stats['considered']++;
    $stats['dropped_same_folder'] += (int)$row['dropped'];
    if ($row['a_self'] === null) {
        $stats['no_model']++;
    }
    foreach (['a_self', 'a_folder', 'b_self', 'b_folder'] as $key) {
        if ($row[$key] === null) {
            continue;
        }
        $outcome = pr_colorway_report_outcome($row['truth'], (string)$row[$key]);
        $stats['paths'][$key][$outcome]++;
        if ($outcome === 'wrong' && ($key === 'a_folder' || $key === 'b_folder')) {
            $pair = $key[0] . "\t" . $row['truth'] . "\t" . $row[$key];
            $stats['confusion'][$pair] = ($stats['confusion'][$pair] ?? 0) + 1;
        }
    }
    if ($row['a_folder'] !== null) {
        $outcome = pr_colorway_report_outcome($row['truth'], (string)$row['a_folder']);
        if ($outcome !== 'blank') {
            $band = pr_colorway_report_band((float)$row['a_folder_share'], PR_COLORWAY_REPORT_SHARE_BANDS);
            $stats['share_bands'][$band][$outcome] = ($stats['share_bands'][$band][$outcome] ?? 0) + 1;
            $band = pr_colorway_report_band(
                (float)$row['folder_top_similarity'], PR_COLORWAY_REPORT_SIMILARITY_BANDS
            );
            $stats['similarity_bands'][$band][$outcome] = ($stats['similarity_bands'][$band][$outcome] ?? 0) + 1;
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
    $lines[] = sprintf('  stored label not a color (skipped)  %d', $stats['truth_not_a_color']);
    $lines[] = sprintf('  no vector at the service (skipped)  %d', $stats['no_vector']);
    $lines[] = sprintf('  no stored product (path a n/a)      %d', $stats['no_model']);
    $lines[] = sprintf('  same-folder neighbours dropped      %d', $stats['dropped_same_folder']);
    $lines[] = '';
    $lines[] = 'path     set     voted  correct  wrong  blank  precision  coverage';
    foreach ($stats['paths'] as $key => $cell) {
        $voted = $cell['correct'] + $cell['wrong'];
        $all = $voted + $cell['blank'];
        [$path, $set] = explode('_', $key);
        $lines[] = sprintf(
            '%-8s %-7s %5d  %7d  %5d  %5d  %9s  %8s',
            $path === 'a' ? 'a name' : 'b e2e', $set, $voted,
            $cell['correct'], $cell['wrong'], $cell['blank'],
            $pct($cell['correct'], $voted), $pct($voted, $all)
        );
    }
    foreach (['share_bands' => 'vote share', 'similarity_bands' => 'top similarity'] as $key => $title) {
        $lines[] = '';
        $lines[] = "path a, folder set, by $title: band  correct  wrong  precision";
        $bands = $stats[$key];
        ksort($bands);
        foreach ($bands as $band => $cell) {
            $c = (int)($cell['correct'] ?? 0);
            $w = (int)($cell['wrong'] ?? 0);
            $lines[] = sprintf('  %-6s %7d  %5d  %9s', $band, $c, $w, $pct($c, $c + $w));
        }
    }
    $lines[] = '';
    $lines[] = 'Most common wrong votes, folder set (path, stored, voted, count):';
    $confusion = $stats['confusion'];
    arsort($confusion);
    foreach (array_slice($confusion, 0, 15, true) as $pair => $n) {
        $lines[] = '  ' . $pair . "\t" . $n;
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
 * Single colorway leaf (blank for none or several) and Folder Path leaves, for a batch of
 * resources in two queries.
 *
 * @param list<int> $resources
 * @return array{colorway:array<int,string>, folders:array<int,array<int,true>>}
 */
function pr_colorway_report_facts(array $resources): array
{
    $resources = array_values(array_unique(array_filter(array_map('intval', $resources))));
    $out = ['colorway' => [], 'folders' => []];
    if ($resources === []) {
        return $out;
    }
    $in = ps_param_insert(count($resources));
    $leaf = "NOT EXISTS (SELECT 1 FROM node ch WHERE ch.parent = n.ref)";
    $names = [];
    foreach (ps_query(
        "SELECT rn.resource, n.name FROM resource_node rn
           JOIN node n ON n.ref = rn.node AND n.resource_type_field = ? AND $leaf
          WHERE rn.resource IN ($in)",
        array_merge(['i', pr_sheet_field('colorway')], ps_param_fill($resources, 'i'))
    ) as $row) {
        $names[(int)$row['resource']][] = (string)$row['name'];
    }
    foreach ($resources as $resource) {
        $list = $names[$resource] ?? [];
        $out['colorway'][$resource] = count($list) === 1 ? $list[0] : '';
    }
    $folderpath = pr_field_ref_by_name('folderpath');
    if ($folderpath > 0) {
        foreach (ps_query(
            "SELECT rn.resource, n.ref FROM resource_node rn
               JOIN node n ON n.ref = rn.node AND n.resource_type_field = ? AND $leaf
              WHERE rn.resource IN ($in)",
            array_merge(['i', $folderpath], ps_param_fill($resources, 'i'))
        ) as $row) {
            $out['folders'][(int)$row['resource']][(int)$row['ref']] = true;
        }
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
        $facts = pr_colorway_report_facts($refs);
        $neighbors = pr_colorway_report_with_colorways($neighbors, $facts['colorway']);
        $sets = pr_colorway_report_split_neighbors(
            $neighbors, $facts['folders'][$resource] ?? [], $facts['folders'], $k
        );
        $names = pr_field_leaf_names($resource, $product_field);
        $self = pr_colorway_report_votes($sets['self'], $leaf_models, $names);
        $folder = pr_colorway_report_votes($sets['folder'], $leaf_models, $names);
        $row = [
            'resource' => $resource,
            'truth' => $truth,
            'model' => implode('|', $names),
            'a_self' => $self['a'],
            'a_folder' => $folder['a'],
            'a_folder_share' => $folder['a_share'],
            'b_self' => $self['b'],
            'b_folder' => $folder['b'],
            'b_folder_name' => $folder['b_name'],
            'folder_top_similarity' => (float)($sets['folder'][0]['similarity'] ?? 0.0),
            'dropped' => $sets['dropped'],
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
        $row['b_self'],
        $row['b_folder'],
        $row['b_folder_name'],
        sprintf('%.3f', $row['folder_top_similarity']),
        (string)$row['dropped'],
    ];
}

/** @return list<string> */
function pr_colorway_report_header(): array
{
    return [
        'resource', 'stored_colorway', 'stored_product', 'a_self', 'a_folder', 'a_folder_share',
        'b_self', 'b_folder', 'b_folder_name', 'folder_top_similarity', 'same_folder_dropped',
    ];
}

/**
 * What the vote would write on untagged lifestyle photos, as production would run it
 * (self set, path a when the photo has a product, else path b). Nothing is written.
 *
 * @param callable(array):void $on_row
 * @return array{considered:int, no_vector:int, voted:int, blank:int, by_colorway:array<string,int>}
 */
function pr_colorway_projection(int $limit, int $k, callable $on_row): array
{
    $leaf_models = pr_leaf_models();
    $product_field = pr_product_field();
    $stats = ['considered' => 0, 'no_vector' => 0, 'voted' => 0, 'blank' => 0, 'by_colorway' => []];

    foreach (pr_colorway_report_photos(false, $limit) as $photo) {
        $resource = $photo['resource'];
        $result = pr_colorway_report_identify($resource, $k);
        if ($result === null) {
            $stats['no_vector']++;
            continue;
        }
        $stats['considered']++;
        $neighbors = is_array($result['neighbors'] ?? null) ? $result['neighbors'] : [];
        $refs = array_map(static fn($n) => (int)($n['resource'] ?? 0), $neighbors);
        $neighbors = pr_colorway_report_with_colorways(
            array_slice($neighbors, 0, $k), pr_colorway_report_facts($refs)['colorway']
        );
        $names = pr_field_leaf_names($resource, $product_field);
        $votes = pr_colorway_report_votes($neighbors, $leaf_models, $names);
        $path = $names !== [] ? 'a' : 'b';
        $voted = $path === 'a' ? (string)$votes['a'] : $votes['b'];
        if ($voted === '') {
            $stats['blank']++;
        } else {
            $stats['voted']++;
            $stats['by_colorway'][$voted] = ($stats['by_colorway'][$voted] ?? 0) + 1;
        }
        $on_row([
            (string)$resource,
            $path,
            $names !== [] ? implode('|', $names) : $votes['b_name'],
            $voted,
            $path === 'a' ? sprintf('%.3f', $votes['a_share']) : '',
            sprintf('%.3f', (float)($neighbors[0]['similarity'] ?? 0.0)),
        ]);
    }
    arsort($stats['by_colorway']);
    return $stats;
}
