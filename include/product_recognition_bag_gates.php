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

/** Cutouts in a row the service may fail on before the feature pass treats it as down. */
const PR_BAG_MAX_FEATURE_FAILURES = 5;

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

/**
 * CREATE TABLE IF NOT EXISTS for the three bag tables, matching dbstruct/ column for column and
 * index for index (dbstruct lists NOT NULL but ResourceSpace's structure check ignores it, so the
 * check would create them nullable). Run only by the CLI writing modes (pr_bag_ensure_tables),
 * as pr_sheet_ensure_tables does for the sheet tables.
 *
 * @return array<string,string> table => statement
 */
function pr_bag_table_definitions(): array
{
    $tail = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    return [
        'product_bag_tag' => "CREATE TABLE IF NOT EXISTS product_bag_tag (
            ref int NOT NULL AUTO_INCREMENT,
            resource int NOT NULL,
            region_index int NOT NULL DEFAULT '0',
            file_checksum varchar(64) NOT NULL DEFAULT '',
            bbox varchar(255) NOT NULL DEFAULT '',
            name_node int NOT NULL DEFAULT '0',
            colorway_node int NOT NULL DEFAULT '0',
            user int DEFAULT NULL,
            created datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (ref),
            KEY bag_tag_resource (resource, file_checksum)
        $tail",
        'product_cutout_feature' => "CREATE TABLE IF NOT EXISTS product_cutout_feature (
            ref int NOT NULL AUTO_INCREMENT,
            resource int NOT NULL,
            region_index int NOT NULL DEFAULT '0',
            file_checksum varchar(64) NOT NULL DEFAULT '',
            bbox varchar(255) NOT NULL DEFAULT '',
            mask_mtime int NOT NULL DEFAULT '0',
            dinov2 blob,
            hist blob,
            status varchar(10) NOT NULL DEFAULT 'ok',
            created datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (ref),
            UNIQUE KEY cutout_feature_key (resource, region_index)
        $tail",
        'product_bag_match' => "CREATE TABLE IF NOT EXISTS product_bag_match (
            ref int NOT NULL AUTO_INCREMENT,
            resource int NOT NULL,
            region_index int NOT NULL DEFAULT '0',
            file_checksum varchar(64) NOT NULL DEFAULT '',
            bbox varchar(255) NOT NULL DEFAULT '',
            seed_ref int NOT NULL DEFAULT '0',
            name_node int DEFAULT NULL,
            colorway_node int NOT NULL DEFAULT '0',
            similarity double NOT NULL DEFAULT '0',
            margin double DEFAULT NULL,
            same_shoot tinyint(1) NOT NULL DEFAULT '0',
            state varchar(10) NOT NULL DEFAULT 'open',
            name_written varchar(255) NOT NULL DEFAULT '',
            colorway_written varchar(255) NOT NULL DEFAULT '',
            decided_by int DEFAULT NULL,
            decided_at datetime DEFAULT NULL,
            created datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (ref),
            KEY bag_match_cutout (resource, region_index),
            KEY bag_match_state (state),
            KEY bag_match_seed (seed_ref)
        $tail",
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
 * A stored cutout box (tag or match row) as a plain [x, y, w, h] list. Accepts a JSON list, a
 * JSON list holding one list, or "x,y,w,h". Anything else is [].
 *
 * @return list<float>
 */
function pr_bag_stored_bbox(string $stored): array
{
    $stored = trim($stored);
    if ($stored === '') {
        return [];
    }
    $parts = $stored[0] === '[' ? json_decode($stored, true) : explode(',', $stored);
    if (!is_array($parts)) {
        return [];
    }
    if (isset($parts[0]) && is_array($parts[0])) {
        $parts = $parts[0];
    }
    return count($parts) === 4 ? array_map('floatval', array_values($parts)) : [];
}

/**
 * Where a stored row's cutout is now. A cutout is its file checksum plus its box (IoU >= 0.9),
 * never its index: re-cuts renumber. A row for another file binds to nothing.
 *
 * @param array<string,mixed> $row resource, region_index, file_checksum, bbox
 * @param array{file_checksum:string,bboxes:array<int,array<int,float>>}|null $now
 */
function pr_bag_bind_row(array $row, ?array $now): ?int
{
    if ($now === null) {
        return null;
    }
    return pr_bag_rebind([
        'region_index' => (int)($row['region_index'] ?? 0),
        'file_checksum' => (string)($row['file_checksum'] ?? ''),
        'bbox' => pr_bag_stored_bbox((string)($row['bbox'] ?? '')),
    ], (string)$now['file_checksum'], (array)$now['bboxes']);
}

/**
 * Bind many stored rows. Each result is the row plus 'bound' (current index or null).
 * When two rows bind to one cutout, the higher ref is 'newest' and the other is not.
 *
 * @param list<array<string,mixed>> $rows
 * @param array<int,array{file_checksum:string,bboxes:array<int,array<int,float>>}> $current by resource
 * @return list<array<string,mixed>>
 */
function pr_bag_bind_rows(array $rows, array $current): array
{
    $out = [];
    $top = [];
    foreach ($rows as $row) {
        $bound = pr_bag_bind_row($row, $current[(int)$row['resource']] ?? null);
        $row['bound'] = $bound;
        if ($bound !== null) {
            $key = (int)$row['resource'] . ':' . $bound;
            $top[$key] = max($top[$key] ?? 0, (int)($row['ref'] ?? 0));
        }
        $out[] = $row;
    }
    foreach ($out as &$row) {
        $row['newest'] = $row['bound'] !== null
            && (int)($row['ref'] ?? 0) === $top[(int)$row['resource'] . ':' . $row['bound']];
    }
    unset($row);
    return $out;
}

/**
 * Feature rows that describe the cutout now at their index: the file is the current file and the
 * stored box overlaps the current box (the service reads a cutout's features by index, so a row
 * at another index is no use to it). Anything else is a leftover from before a re-cut.
 *
 * @param list<array<string,mixed>> $rows resource, region_index, file_checksum, bbox, ...
 * @param array<int,array{file_checksum:string,bboxes:array<int,array<int,float>>}> $current by resource
 * @return array<string,array<string,mixed>> "resource:index" => row
 */
function pr_bag_current_features(array $rows, array $current): array
{
    $out = [];
    foreach ($rows as $row) {
        if (pr_bag_bind_row($row, $current[(int)$row['resource']] ?? null) === (int)$row['region_index']) {
            $out[(int)$row['resource'] . ':' . (int)$row['region_index']] = $row;
        }
    }
    return $out;
}

/**
 * The feature pass got no answer for a cutout. If the service is up, or this same cutout was
 * already the one running when it went down last run, the cutout is what it cannot take: skip it.
 * Otherwise it is the service: stop here and remember the cutout, so a cutout that keeps killing
 * the service (slower to restart than the wait) is passed on the next run, not retried every night.
 *
 * @return 'skip'|'stop'
 */
function pr_bag_no_answer_action(bool $service_up, string $suspect, string $cutout): string
{
    return ($service_up || ($suspect !== '' && $suspect === $cutout)) ? 'skip' : 'stop';
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
 * A photo that can stand in as a rival (issue #34): exactly one cutout and one colorway. The
 * product is not required: with none known (or more than one, which says nothing about this
 * cutout) it is 0, and the rival counts as that colorway only.
 *
 * @param array{products:list<int>,colorways:list<int>,cutouts:int} $photo
 * @return array{product:int,colorway:int}|null
 */
function pr_bag_competitor_key(array $photo): ?array
{
    if ((int)$photo['cutouts'] !== 1 || count($photo['colorways']) !== 1) {
        return null;
    }
    return ['product' => count($photo['products']) === 1 ? (int)$photo['products'][0] : 0,
            'colorway' => (int)$photo['colorways'][0]];
}

/**
 * The best seed key of one pool and its margin over the best DIFFERENT key. A rival counts by
 * its best score over seeds AND competitors ('all'); only a key with a seed can win ('seed').
 * A key whose colorway a person rejected for this cutout cannot win, but it still counts as a
 * rival: the next best key is offered, with the rejected one lowering its margin.
 * With no other key at all the margin is null: unknown, which never passes a gate.
 *
 * @param array<string,array{all:float,seed:?float,ref:int,n?:int}> $keys service output for one pool
 * @param list<int> $rejected_colorways
 * @return array{key:string,similarity:float,margin:?float,ref:int}|null null when no key may win
 */
function pr_bag_pool_result(array $keys, array $rejected_colorways = []): ?array
{
    $best = null;
    foreach ($keys as $key => $entry) {
        $key = (string)$key;
        $cut = strrpos($key, '|');
        if ($entry['seed'] === null || in_array((int)($cut === false ? $key : substr($key, $cut + 1)), $rejected_colorways, true)) {
            continue;
        }
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
 * what clears the review floor.
 *
 * @param array{key:string,similarity:float,margin:?float,ref:int}|null $colorway pr_bag_pool_result of the colorway pool
 * @param array{key:string,similarity:float,margin:?float,ref:int}|null $pair     pr_bag_pool_result of the pair pool
 * @return array{gate:string,name_node:?int,colorway_node:int,similarity:float,margin:?float,ref:int,passes:bool}|null
 */
function pr_bag_choose(?array $colorway, ?array $pair, array $cfg): ?array
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
        if ($option['colorway_node'] <= 0 || $option['similarity'] < $cfg['review_floor'] - 1e-12) {
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
 *  - A colorway-only match whose seed product is no longer on the photo: blocked. The colorway was
 *    only compared within that product.
 *  - A single-cutout photo that already has a different Name or a different Colorway: blocked,
 *    the match stays in review.
 *  - A multi-cutout photo may gain the matched bag's Name and Colorway beside what it has.
 *
 * @param array{products:list<int>,colorways:list<int>,cutouts:int} $photo
 * @param int|null $seed_product the seed's product; null skips the product check
 * @return array{add_name:bool,add_colorway:bool,blocked:string}
 */
function pr_bag_write_plan(array $photo, ?int $name_node, int $colorway_node, ?int $seed_product = null): array
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
    if ($name_node === null && $seed_product !== null && !in_array($seed_product, $photo['products'], true)) {
        $plan['blocked'] = 'product_changed';
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
 * What the nightly pass does for one cutout, given the service's scores. No I/O.
 *
 * The one row a cutout gets is pr_bag_choose(). Against the open row it already has:
 * nothing to do, close it (it no longer qualifies), update its numbers (same match), or
 * replace it (a different match). A passing match that the config allows, and that the photo
 * can take as an add, is marked for auto-apply.
 *
 * @param array{photo:array{products:list<int>,colorways:list<int>,cutouts:int},shoot:string} $query
 * @param array<string,array> $colorway_keys service 'colorway' pool
 * @param array<string,array> $pair_keys     service 'pair' pool
 * @param array<int,array{product:int,shoot:string}> $seeds seed ref => seed
 * @param array{ref:int,name_node:?int,colorway_node:int,seed_ref:int}|null $open the cutout's open row
 * @param list<int> $rejected colorways rejected or undone for this cutout
 * @param array<int,true> $withheld never-auto-apply products
 * @return array{action:string,match:?array,open_ref:int,auto:bool,blocked:string}
 */
function pr_bag_decide(array $query, array $colorway_keys, array $pair_keys, array $seeds, ?array $open,
                       array $rejected, array $cfg, array $withheld = []): array
{
    $out = ['action' => 'none', 'match' => null, 'open_ref' => (int)($open['ref'] ?? 0), 'auto' => false, 'blocked' => ''];
    $by_colorway = pr_bag_pool_result($colorway_keys, $rejected);
    $by_pair = pr_bag_pool_result($pair_keys, $rejected);

    # What one choice would do on this photo: its row, its plan, whether the pass may write it unattended.
    $assess = static function (?array $choice) use ($query, $seeds, $cfg, $withheld): ?array {
        $seed = $choice === null ? null : ($seeds[$choice['ref']] ?? null);
        if ($choice === null || $seed === null) {
            return null;
        }
        $same_shoot = $choice['gate'] === 'pair' || ($seed['shoot'] !== '' && $seed['shoot'] === $query['shoot']);
        $plan = pr_bag_write_plan($query['photo'], $choice['name_node'], $choice['colorway_node']);
        return [
            'match' => [
                'gate' => $choice['gate'], 'seed_ref' => $choice['ref'], 'name_node' => $choice['name_node'],
                'colorway_node' => $choice['colorway_node'], 'similarity' => $choice['similarity'],
                'margin' => $choice['margin'], 'same_shoot' => $same_shoot,
            ],
            'blocked' => $plan['blocked'],
            # The photo already says exactly this, or nothing could ever be written: not worth a row.
            'void' => in_array($plan['blocked'], ['no_product', 'no_colorway'], true)
                || ($plan['blocked'] === '' && !$plan['add_name'] && !$plan['add_colorway']),
            'auto' => $choice['passes'] && $plan['blocked'] === ''
                && pr_bag_auto_ok($choice['gate'], $query['photo'], (int)$seed['product'], $same_shoot, $cfg, $withheld),
        ];
    };
    # An auto-eligible gate wins even when the other gate also passes: the two flags are independent.
    $by_gate = [pr_bag_choose(null, $by_pair, $cfg), pr_bag_choose($by_colorway, null, $cfg)];
    $picked = null;
    foreach ($by_gate as $choice) {
        $one = $assess($choice);
        if ($one !== null && !$one['void'] && $one['auto']) {
            $picked = $one;
            break;
        }
    }
    # Otherwise the usual preference, falling to the other gate if the preferred row is void.
    if ($picked === null) {
        foreach ([pr_bag_choose($by_colorway, $by_pair, $cfg), $by_gate[0], $by_gate[1]] as $choice) {
            $one = $assess($choice);
            if ($one !== null && !$one['void']) {
                $picked = $one;
                break;
            }
        }
    }
    if ($picked === null) {
        $out['action'] = $open === null ? 'none' : 'close';
        return $out;
    }
    $match = $picked['match'];
    $out['match'] = $match;
    $out['blocked'] = $picked['blocked'];
    $out['auto'] = $picked['auto'];
    if ($open !== null && (int)$open['seed_ref'] === $match['seed_ref'] && (int)$open['colorway_node'] === $match['colorway_node']
        && ($open['name_node'] === null ? null : (int)$open['name_node']) === $match['name_node']) {
        $out['action'] = 'update';
    } else {
        $out['action'] = 'insert';
    }
    return $out;
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
 * a person added or kept since is left alone, and so is a node another applied match on the
 * same photo also relies on, or that a person's seed answer on the photo confirms ($protect).
 * Only an applied match has anything to undo.
 *
 * @param list<int> $present node ids currently on the photo (both fields)
 * @param list<int> $protect node ids written by this photo's other applied matches, or in its seeds
 * @return array{remove:list<int>,already_gone:list<int>,protected:list<int>}
 */
function pr_bag_undo_plan(string $state, string $name_written, string $colorway_written, array $present, array $protect = []): array
{
    if ($state !== 'applied') {
        return ['remove' => [], 'already_gone' => [], 'protected' => []];
    }
    $wrote = array_values(array_unique(array_merge(pr_bag_csv_ids($name_written), pr_bag_csv_ids($colorway_written))));
    $there = array_values(array_intersect($wrote, array_map('intval', $present)));
    $keep = array_values(array_intersect($there, array_map('intval', $protect)));
    return [
        'remove' => array_values(array_diff($there, $keep)),
        'already_gone' => array_values(array_diff($wrote, $there)),
        'protected' => $keep,
    ];
}

/** An --import-bag-tags row must be a JSON object. A number, a string, null or a list is a rejected row. */
function pr_bag_import_row_is_object($row): bool
{
    return is_array($row) && ($row === [] || !array_is_list($row));
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
 * Report queries in chunks of about $size that never split a photo. The service's near-copy rule
 * looks at every seed cutout of the query's photo, and only sees the ones in the same request.
 *
 * @param list<array<string,mixed>> $seeds
 * @return list<list<array<string,mixed>>>
 */
function pr_bag_report_chunks(array $seeds, int $size = 200): array
{
    $by_photo = [];
    foreach ($seeds as $seed) {
        $by_photo[(int)$seed['resource']][] = $seed;
    }
    $chunks = [];
    $chunk = [];
    foreach ($by_photo as $group) {
        if ($chunk !== [] && count($chunk) + count($group) > $size) {
            $chunks[] = $chunk;
            $chunk = [];
        }
        $chunk = array_merge($chunk, $group);
    }
    if ($chunk !== []) {
        $chunks[] = $chunk;
    }
    return $chunks;
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
