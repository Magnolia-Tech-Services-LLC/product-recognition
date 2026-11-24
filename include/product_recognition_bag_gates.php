<?php
/**
 * Tag one bag, find the same bag (#34): the decisions, as pure functions.
 *
 * No database, no ResourceSpace call, no service call in this file, so the self-check can
 * load it on its own (scripts/product_recognition_selfcheck_bag_match.php) and the nightly
 * writer cannot drift from what the tests assert. The service returns raw scores
 * (POST /match_bags); every cutoff, every "may this be written" and every undo step is here.
 *
 * Vocabulary:
 *  - seed      a product_bag_tag row: a person's explicit answer for one cutout. Only seeds
 *              are positive matches. Tags a bag match wrote are never seeds.
 *  - competitor a cutout of a photo that has exactly one product, one colorway and one
 *              cutout. It can only lower a margin, never be the match.
 *  - gate      'colorway' (key = colorway, within the query photo's products) or 'pair'
 *              (key = product|colorway, same shoot only).
 *  - photo     ['products' => list<int> leaf ids, 'colorways' => list<int>, 'cutouts' => int]
 */

/** A seed whose photo was re-cut keeps its bag when a current cutout overlaps it this much. */
const PR_BAG_REBIND_IOU = 0.9;

/**
 * Settings with their spec defaults. Nothing auto-applies until a flag is switched on, and the
 * flags are only to be switched on once --bag-match-report meets the enable condition.
 *
 * @param array<string,mixed>|null $source defaults to the plugin globals
 * @return array{colorway_auto:bool,product_auto:bool,colorway_sim:float,colorway_margin:float,product_sim:float,product_margin:float,review_floor:float,copy_segments:list<string>,batch:int}
 */
function pr_bag_config(?array $source = null): array
{
    $g = $source ?? $GLOBALS;
    $segments = $g['product_recognition_bag_copy_segments'] ?? '';
    if (is_string($segments)) {
        $segments = preg_split('/[,;\r\n]+/', $segments) ?: [];
    }
    $copy = [];
    foreach ((array)$segments as $name) {
        $folded = pr_bag_fold((string)$name);
        if ($folded !== '') {
            $copy[$folded] = $folded;
        }
    }
    return [
        'colorway_auto' => !empty($g['product_recognition_bag_colorway_auto_apply']),
        'product_auto' => !empty($g['product_recognition_bag_product_auto_apply']),
        'colorway_sim' => (float)($g['product_recognition_bag_colorway_min_similarity'] ?? 0.85),
        'colorway_margin' => (float)($g['product_recognition_bag_colorway_min_margin'] ?? 0.10),
        'product_sim' => (float)($g['product_recognition_bag_product_min_similarity'] ?? 0.90),
        'product_margin' => (float)($g['product_recognition_bag_product_min_margin'] ?? 0.10),
        'review_floor' => (float)($g['product_recognition_bag_review_floor'] ?? 0.75),
        'copy_segments' => array_values($copy),
        'batch' => max(1, (int)($g['product_recognition_bag_batch'] ?? 2000)),
    ];
}

/** Case, punctuation and spacing folded away, as pr_fold_label does for node names. */
function pr_bag_fold(string $name): string
{
    $name = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
    $name = strtolower(trim($name));
    $name = preg_replace("/['\x{2019}`]/u", '', $name) ?? $name;
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;
    return trim($name);
}

/**
 * Intersection over union of two [x1,y1,x2,y2] fractions. 0 for a malformed or empty box.
 *
 * @param array<int,float|int> $a
 * @param array<int,float|int> $b
 */
function pr_bag_bbox_iou(array $a, array $b): float
{
    if (count($a) !== 4 || count($b) !== 4) {
        return 0.0;
    }
    $a = array_map('floatval', array_values($a));
    $b = array_map('floatval', array_values($b));
    $area = static fn(array $r): float => max(0.0, $r[2] - $r[0]) * max(0.0, $r[3] - $r[1]);
    $w = min($a[2], $b[2]) - max($a[0], $b[0]);
    $h = min($a[3], $b[3]) - max($a[1], $b[1]);
    $inter = ($w > 0 && $h > 0) ? $w * $h : 0.0;
    $union = $area($a) + $area($b) - $inter;
    return $union > 0 ? $inter / $union : 0.0;
}

/**
 * Where a seed lives now. A re-cut can renumber a photo's cutouts, so the seed is found again
 * by overlap, not by index. The file must be the same file (checksum) and the overlap at least
 * PR_BAG_REBIND_IOU; otherwise the seed is ignored and reported. The seed's own index wins a tie.
 *
 * @param array{region_index:int,file_checksum:string,bbox:array<int,float|int>} $seed
 * @param array<int,array<int,float|int>> $cutouts current region index => bbox
 * @return int|null current region index
 */
function pr_bag_rebind(array $seed, string $current_checksum, array $cutouts): ?int
{
    if ((string)$seed['file_checksum'] === '' || (string)$seed['file_checksum'] !== $current_checksum) {
        return null;
    }
    $best = null;
    $best_iou = 0.0;
    foreach ($cutouts as $index => $bbox) {
        $iou = pr_bag_bbox_iou((array)$seed['bbox'], (array)$bbox);
        if ($iou < PR_BAG_REBIND_IOU) {
            continue;
        }
        $better = $iou > $best_iou + 1e-12
            || (abs($iou - $best_iou) <= 1e-12 && (int)$index === (int)$seed['region_index']);
        if ($best === null || $better) {
            $best = (int)$index;
            $best_iou = $iou;
        }
    }
    return $best;
}

/**
 * The shoot a photo belongs to: its Folder Path node set, minus the configured copy segments,
 * as one comparable string. Two photos are the same shoot when the strings are equal and not
 * empty. A photo with no Folder Path has no shoot.
 *
 * @param list<array{ref:int,name:string}> $folder_nodes
 * @param list<string> $copy_segments names to ignore (any case or punctuation)
 */
function pr_bag_shoot_key(array $folder_nodes, array $copy_segments = []): string
{
    $skip = [];
    foreach ($copy_segments as $name) {
        $folded = pr_bag_fold((string)$name);
        if ($folded !== '') {
            $skip[$folded] = true;
        }
    }
    $ids = [];
    foreach ($folder_nodes as $node) {
        if ((int)$node['ref'] <= 0 || isset($skip[pr_bag_fold((string)($node['name'] ?? ''))])) {
            continue;
        }
        $ids[(int)$node['ref']] = true;
    }
    $ids = array_keys($ids);
    sort($ids);
    return implode(',', $ids);
}

/**
 * A photo that can stand in as a rival: exactly one cutout, one product and one colorway.
 *
 * @param array{products:list<int>,colorways:list<int>,cutouts:int} $photo
 * @return array{product:int,colorway:int}|null
 */
function pr_bag_competitor_key(array $photo): ?array
{
    if ((int)$photo['cutouts'] !== 1 || count($photo['products']) !== 1 || count($photo['colorways']) !== 1) {
        return null;
    }
    return ['product' => (int)$photo['products'][0], 'colorway' => (int)$photo['colorways'][0]];
}

/**
 * The best seed key of one pool and its margin over the best DIFFERENT key. A rival counts by
 * its best score over seeds AND competitors ('all'); only a key with a seed can win ('seed').
 * With no other key at all the margin is null: unknown, which never passes a gate.
 *
 * @param array<string,array{all:float,seed:?float,ref:int,n?:int}> $keys service output for one pool
 * @return array{key:string,similarity:float,margin:?float,ref:int}|null null when no key has a seed
 */
function pr_bag_pool_result(array $keys): ?array
{
    $best = null;
    foreach ($keys as $key => $entry) {
        if ($entry['seed'] === null) {
            continue;
        }
        $key = (string)$key;
        if ($best === null || $entry['seed'] > $best['similarity'] + 1e-12
            || (abs($entry['seed'] - $best['similarity']) <= 1e-12 && strcmp($key, $best['key']) < 0)) {
            $best = ['key' => $key, 'similarity' => (float)$entry['seed'], 'margin' => null, 'ref' => (int)$entry['ref']];
        }
    }
    if ($best === null) {
        return null;
    }
    $rival = null;
    foreach ($keys as $key => $entry) {
        if ((string)$key === $best['key']) {
            continue;
        }
        $rival = $rival === null ? (float)$entry['all'] : max($rival, (float)$entry['all']);
    }
    $best['margin'] = $rival === null ? null : $best['similarity'] - $rival;
    return $best;
}

/** Whether one gate's numbers pass. An unknown margin never passes. */
function pr_bag_gate_passes(string $gate, float $similarity, ?float $margin, array $cfg): bool
{
    if ($margin === null) {
        return false;
    }
    $sim = $gate === 'pair' ? $cfg['product_sim'] : $cfg['colorway_sim'];
    $min_margin = $gate === 'pair' ? $cfg['product_margin'] : $cfg['colorway_margin'];
    return $similarity >= $sim - 1e-12 && $margin >= $min_margin - 1e-12;
}

/**
 * The one row a cutout gets: a passing pair, else a passing colorway, else the more similar of
 * what clears the review floor. A colorway a person already rejected for this cutout is never
 * offered again (it still counted toward the margins above).
 *
 * @param array{key:string,similarity:float,margin:?float,ref:int}|null $colorway pr_bag_pool_result of the colorway pool
 * @param array{key:string,similarity:float,margin:?float,ref:int}|null $pair     pr_bag_pool_result of the pair pool
 * @param list<int> $rejected_colorways
 * @return array{gate:string,name_node:?int,colorway_node:int,similarity:float,margin:?float,ref:int,passes:bool}|null
 */
function pr_bag_choose(?array $colorway, ?array $pair, array $cfg, array $rejected_colorways = []): ?array
{
    $options = [];
    if ($colorway !== null) {
        $options[] = ['gate' => 'colorway', 'name_node' => null, 'colorway_node' => (int)$colorway['key']] + $colorway;
    }
    if ($pair !== null) {
        $parts = explode('|', $pair['key']);
        if (count($parts) === 2 && (int)$parts[0] > 0 && (int)$parts[1] > 0) {
            $options[] = ['gate' => 'pair', 'name_node' => (int)$parts[0], 'colorway_node' => (int)$parts[1]] + $pair;
        }
    }
    $keep = [];
    foreach ($options as $option) {
        if ($option['colorway_node'] <= 0 || in_array($option['colorway_node'], $rejected_colorways, true)
            || $option['similarity'] < $cfg['review_floor'] - 1e-12) {
            continue;
        }
        $option['passes'] = pr_bag_gate_passes($option['gate'], $option['similarity'], $option['margin'], $cfg);
        unset($option['key']);
        $keep[] = $option;
    }
    if ($keep === []) {
        return null;
    }
    usort($keep, static function (array $a, array $b): int {
        if ($a['passes'] !== $b['passes']) {
            return $a['passes'] ? -1 : 1;
        }
        if ($a['passes'] && $a['gate'] !== $b['gate']) {
            return $a['gate'] === 'pair' ? -1 : 1;
        }
        return $b['similarity'] <=> $a['similarity'];
    });
    return $keep[0];
}

/**
 * Whether this match may be written without a person. Passing the numbers is a separate test
 * (the row's 'passes'); this is the rest of the gate.
 *
 * colorway: the photo already has exactly one product, one cutout, and the seed has that product.
 * pair:     same shoot, and the photo has no product or already has the seed's product.
 * Either: the config flag is on, and the seed's product is not on the never-auto-apply list.
 *
 * @param array{products:list<int>,colorways:list<int>,cutouts:int} $photo
 * @param array<int,true> $withheld product node => true
 */
function pr_bag_auto_ok(string $gate, array $photo, int $seed_product, bool $same_shoot, array $cfg, array $withheld = []): bool
{
    if ($seed_product <= 0 || isset($withheld[$seed_product])) {
        return false;
    }
    if ($gate === 'pair') {
        return $cfg['product_auto'] && $same_shoot
            && ($photo['products'] === [] || in_array($seed_product, $photo['products'], true));
    }
    return $cfg['colorway_auto'] && count($photo['products']) === 1 && (int)$photo['cutouts'] === 1
        && (int)$photo['products'][0] === $seed_product;
}

/**
 * What a match may add to its photo. Only ever adds. Pure; the caller writes.
 *
 *  - Product unknown and not inheritable (a colorway-only match on a photo with no product):
 *    nothing is written.
 *  - A single-cutout photo that already has a different Name or a different Colorway: blocked,
 *    the match stays in review.
 *  - A multi-cutout photo may gain the matched bag's Name and Colorway beside what it has.
 *
 * @param array{products:list<int>,colorways:list<int>,cutouts:int} $photo
 * @return array{add_name:bool,add_colorway:bool,blocked:string}
 */
function pr_bag_write_plan(array $photo, ?int $name_node, int $colorway_node): array
{
    $plan = ['add_name' => false, 'add_colorway' => false, 'blocked' => ''];
    if ($colorway_node <= 0) {
        $plan['blocked'] = 'no_colorway';
        return $plan;
    }
    if ($name_node === null && $photo['products'] === []) {
        $plan['blocked'] = 'no_product';
        return $plan;
    }
    if ((int)$photo['cutouts'] <= 1) {
        if ($name_node !== null && $photo['products'] !== [] && !in_array($name_node, $photo['products'], true)) {
            $plan['blocked'] = 'other_name';
            return $plan;
        }
        if ($photo['colorways'] !== [] && !in_array($colorway_node, $photo['colorways'], true)) {
            $plan['blocked'] = 'other_colorway';
            return $plan;
        }
    }
    $plan['add_name'] = $name_node !== null && !in_array($name_node, $photo['products'], true);
    $plan['add_colorway'] = !in_array($colorway_node, $photo['colorways'], true);
    return $plan;
}

/**
 * Node ids a match wrote, as stored in name_written / colorway_written.
 *
 * @param list<int> $ids
 */
function pr_bag_ids_csv(array $ids): string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $n): bool => $n > 0)));
    return implode(',', $ids);
}

/** @return list<int> */
function pr_bag_csv_ids(string $csv): array
{
    $out = [];
    foreach (explode(',', $csv) as $part) {
        if ((int)$part > 0) {
            $out[(int)$part] = (int)$part;
        }
    }
    return array_values($out);
}

/**
 * Undo: remove only the nodes this match wrote, and only the ones still on the photo. Anything
 * a person added or kept since is left alone. Only an applied match has anything to undo.
 *
 * @param list<int> $present node ids currently on the photo (both fields)
 * @return array{remove:list<int>,already_gone:list<int>}
 */
function pr_bag_undo_plan(string $state, string $name_written, string $colorway_written, array $present): array
{
    if ($state !== 'applied') {
        return ['remove' => [], 'already_gone' => []];
    }
    $wrote = array_values(array_unique(array_merge(pr_bag_csv_ids($name_written), pr_bag_csv_ids($colorway_written))));
    return [
        'remove' => array_values(array_intersect($wrote, array_map('intval', $present))),
        'already_gone' => array_values(array_diff($wrote, array_map('intval', $present))),
    ];
}

/**
 * Validate one --import-bag-tags row against the photo as it is now. Pure: the labels are
 * already resolved to node ids (null = unknown label).
 *
 * @param array<string,mixed> $row           resource, region_index, file_checksum, bbox, name, colorway
 * @param array{file_checksum:string,bboxes:array<int,array<int,float|int>>}|null $current null = no such resource
 * @return array{ok:bool,reason:string}
 */
function pr_bag_import_check(array $row, ?array $current, ?int $name_node, ?int $colorway_node): array
{
    $fail = static fn(string $why): array => ['ok' => false, 'reason' => $why];
    if (trim((string)($row['name'] ?? '')) === '') {
        return $fail('no_product');
    }
    if (trim((string)($row['colorway'] ?? '')) === '') {
        return $fail('no_colorway');
    }
    if ($current === null) {
        return $fail('no_resource');
    }
    if ((string)($row['file_checksum'] ?? '') === '' || (string)$row['file_checksum'] !== (string)$current['file_checksum']) {
        return $fail('checksum');
    }
    $index = (int)($row['region_index'] ?? 0);
    if (!isset($current['bboxes'][$index])) {
        return $fail('no_cutout');
    }
    if (pr_bag_bbox_iou((array)($row['bbox'] ?? []), (array)$current['bboxes'][$index]) < PR_BAG_REBIND_IOU) {
        return $fail('bbox');
    }
    if ($name_node === null) {
        return $fail('unknown_name');
    }
    if ($colorway_node === null) {
        return $fail('unknown_colorway');
    }
    return ['ok' => true, 'reason' => ''];
}

/**
 * Report halves: a shoot is held out when its checksum is odd. Deterministic and independent of
 * how many shoots there are, so adding seeds does not move an old shoot between halves.
 */
function pr_bag_report_held_out(string $shoot_key): bool
{
    return (crc32($shoot_key) & 1) === 1;
}

/**
 * Score one report query for one gate and mode.
 *
 * @param array{key:string,similarity:float,margin:?float,ref:int}|null $result pr_bag_pool_result
 * @return string 'right'|'wrong'|'none' (none: nothing passed the gate, or no candidate)
 */
function pr_bag_report_outcome(string $gate, ?array $result, string $truth_key, array $cfg): string
{
    if ($result === null || !pr_bag_gate_passes($gate, $result['similarity'], $result['margin'], $cfg)) {
        return 'none';
    }
    return $result['key'] === $truth_key ? 'right' : 'wrong';
}

/**
 * The enable condition for auto-apply, from held-out-half tallies:
 * zero wrong among hidden-colorway cutouts, and at least 98% right among leave-one-out cutouts
 * that were tagged. A tally with nothing tagged is no evidence, so it does not pass.
 *
 * @param array<string,array{right:int,wrong:int,none:int}> $tally keys "loo:<gate>" and "hidden:<gate>"
 * @return array{ok:bool,reasons:list<string>}
 */
function pr_bag_report_verdict(array $tally): array
{
    $reasons = [];
    foreach (['colorway', 'pair'] as $gate) {
        $loo = $tally["loo:$gate"] ?? ['right' => 0, 'wrong' => 0, 'none' => 0];
        $hidden = $tally["hidden:$gate"] ?? ['right' => 0, 'wrong' => 0, 'none' => 0];
        $tagged = $loo['right'] + $loo['wrong'];
        if ($tagged === 0) {
            $reasons[] = "$gate: nothing tagged in leave-one-out, no evidence";
        } elseif ($loo['right'] / $tagged < 0.98) {
            $reasons[] = sprintf('%s: %d of %d right in leave-one-out (%.1f%%), below 98%%',
                $gate, $loo['right'], $tagged, 100 * $loo['right'] / $tagged);
        }
        if ($hidden['right'] + $hidden['wrong'] > 0) {
            $reasons[] = sprintf('%s: %d tagged with the true colorway hidden (every one is wrong)',
                $gate, $hidden['right'] + $hidden['wrong']);
        }
    }
    return ['ok' => $reasons === [], 'reasons' => $reasons];
}
