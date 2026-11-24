<?php
/**
 * Tag-one-bag matching assertions (#34). Pure functions only: no database, no service, no
 * ResourceSpace. Runs on its own:
 *
 *   php scripts/product_recognition_selfcheck_bag_match.php
 *
 * and is required from product_recognition_selfcheck.php, which supplies pr_check() there.
 * The service half is scripts/test_bag_features.py and scripts/test_bag_endpoints.py.
 */
if (!function_exists('pr_check')) {
    function pr_check(string $label, bool $ok): void
    {
        printf("%-4s %s\n", $ok ? "ok" : "FAIL", $label);
        if (!$ok) {
            exit(1);
        }
    }
}
include_once dirname(__DIR__) . '/include/product_recognition_bag_gates.php';

# --- config: every auto flag is off, cutoffs are the spec's --------------------------------
$bag_cfg = pr_bag_config([]);
pr_check("config defaults: both auto-apply flags off, cutoffs .85/.10 and .90/.10, review floor .75",
         $bag_cfg['colorway_auto'] === false && $bag_cfg['product_auto'] === false
         && $bag_cfg['colorway_sim'] === 0.85 && $bag_cfg['colorway_margin'] === 0.10
         && $bag_cfg['product_sim'] === 0.90 && $bag_cfg['product_margin'] === 0.10
         && $bag_cfg['review_floor'] === 0.75 && $bag_cfg['copy_segments'] === []);
$bag_on = pr_bag_config([
    'product_recognition_bag_colorway_auto_apply' => true,
    'product_recognition_bag_product_auto_apply' => '1',
    'product_recognition_bag_copy_segments' => "Copy, Goat Hill (Copy);; ",
]);
$bag_on_cw = $bag_on;
$bag_on_cw['product_auto'] = false;
pr_check("config: flags and copy segments read from settings, segments folded and deduplicated",
         $bag_on['colorway_auto'] === true && $bag_on['product_auto'] === true
         && $bag_on['copy_segments'] === ['copy', 'goat hill copy']);

# --- bbox overlap and rebinding a re-cut seed -----------------------------------------------
pr_check("iou: identical 1, disjoint 0, half overlap one third, malformed 0",
         abs(pr_bag_bbox_iou([0, 0, 1, 1], [0, 0, 1, 1]) - 1.0) < 1e-12
         && pr_bag_bbox_iou([0, 0, .4, .4], [.5, .5, 1, 1]) === 0.0
         && abs(pr_bag_bbox_iou([0, 0, .5, 1], [.25, 0, .75, 1]) - 1 / 3) < 1e-12
         && pr_bag_bbox_iou([0, 0, 1], [0, 0, 1, 1]) === 0.0
         && pr_bag_bbox_iou([.5, .5, .5, .5], [.5, .5, .5, .5]) === 0.0);
$bag_seed = ['region_index' => 0, 'file_checksum' => 'abc', 'bbox' => [.10, .10, .50, .90]];
pr_check("rebind: the same index keeps its seed",
         pr_bag_rebind($bag_seed, 'abc', [0 => [.10, .10, .50, .90]]) === 0);
pr_check("rebind: a re-cut that renumbered the cutouts finds the seed by overlap",
         pr_bag_rebind($bag_seed, 'abc', [0 => [.55, .10, .95, .90], 1 => [.11, .10, .50, .91]]) === 1);
pr_check("rebind: overlap under 0.9 is ignored",
         pr_bag_rebind($bag_seed, 'abc', [0 => [.10, .10, .50, .70]]) === null);
pr_check("rebind: a different file is ignored whatever the boxes say",
         pr_bag_rebind($bag_seed, 'zzz', [0 => [.10, .10, .50, .90]]) === null
         && pr_bag_rebind(['file_checksum' => ''] + $bag_seed, '', [0 => [.10, .10, .50, .90]]) === null);
pr_check("rebind: a tie keeps the seed's own index",
         pr_bag_rebind($bag_seed, 'abc', [0 => [.10, .10, .50, .90], 1 => [.10, .10, .50, .90]]) === 0
         && pr_bag_rebind(['region_index' => 1] + $bag_seed, 'abc', [0 => [.10, .10, .50, .90], 1 => [.10, .10, .50, .90]]) === 1);

# --- shoot key -----------------------------------------------------------------------------
$bag_path = [['ref' => 30, 'name' => 'Evergreen'], ['ref' => 10, 'name' => 'Dropbox'], ['ref' => 20, 'name' => 'Loma']];
pr_check("shoot key: the node set, order-free",
         pr_bag_shoot_key($bag_path) === '10,20,30'
         && pr_bag_shoot_key(array_reverse($bag_path)) === '10,20,30');
pr_check("shoot key: different sets differ, and a photo with no path has no shoot",
         pr_bag_shoot_key($bag_path) !== pr_bag_shoot_key([['ref' => 10, 'name' => 'Dropbox'], ['ref' => 20, 'name' => 'Loma']])
         && pr_bag_shoot_key([]) === '');
pr_check("shoot key: a copy segment (any case) drops out, so a copy folder joins its original",
         pr_bag_shoot_key(array_merge($bag_path, [['ref' => 99, 'name' => 'COPY']]), ['copy']) === '10,20,30'
         && pr_bag_shoot_key(array_merge($bag_path, [['ref' => 99, 'name' => 'COPY']])) === '10,20,30,99');

# --- competitors ---------------------------------------------------------------------------
pr_check("competitor: one cutout and one colorway; the product is not required (0 when none or several)",
         pr_bag_competitor_key(['products' => [7], 'colorways' => [3], 'cutouts' => 1]) === ['product' => 7, 'colorway' => 3]
         && pr_bag_competitor_key(['products' => [7], 'colorways' => [3], 'cutouts' => 2]) === null
         && pr_bag_competitor_key(['products' => [7], 'colorways' => [3, 4], 'cutouts' => 1]) === null
         && pr_bag_competitor_key(['products' => [], 'colorways' => [3], 'cutouts' => 1]) === ['product' => 0, 'colorway' => 3]
         && pr_bag_competitor_key(['products' => [7, 8], 'colorways' => [3], 'cutouts' => 1]) === ['product' => 0, 'colorway' => 3]
         && pr_bag_competitor_key(['products' => [7], 'colorways' => [], 'cutouts' => 1]) === null);

# --- the winning key and its margin --------------------------------------------------------
$bag_keys = [
    '1' => ['all' => 0.91, 'seed' => 0.91, 'ref' => 11, 'n' => 2],
    '2' => ['all' => 0.80, 'seed' => 0.78, 'ref' => 12, 'n' => 1],
    '3' => ['all' => 0.70, 'seed' => null, 'ref' => 0, 'n' => 1],
];
$bag_res = pr_bag_pool_result($bag_keys);
pr_check("pool result: best seed key, margin is over the best different key by its best score",
         $bag_res['key'] === '1' && $bag_res['ref'] === 11 && abs($bag_res['similarity'] - 0.91) < 1e-12
         && abs($bag_res['margin'] - 0.11) < 1e-12);
$bag_keys['3']['all'] = 0.95;
$bag_res = pr_bag_pool_result($bag_keys);
pr_check("pool result: a competitor key that outscores the seed makes the margin negative; it is never the winner",
         $bag_res['key'] === '1' && $bag_res['margin'] < 0);
pr_check("pool result: no key with a seed is no result",
         pr_bag_pool_result(['3' => ['all' => 0.9, 'seed' => null, 'ref' => 0, 'n' => 1]]) === null
         && pr_bag_pool_result([]) === null);
pr_check("pool result: with no rival key at all the margin is unknown, not waived",
         pr_bag_pool_result(['1' => ['all' => 0.99, 'seed' => 0.99, 'ref' => 5, 'n' => 1]])['margin'] === null);
pr_check("pool result: a competitor of the winner's own key is not a rival",
         abs(pr_bag_pool_result([
             '1' => ['all' => 0.99, 'seed' => 0.90, 'ref' => 5, 'n' => 2],
             '2' => ['all' => 0.60, 'seed' => 0.60, 'ref' => 6, 'n' => 1],
         ])['margin'] - 0.30) < 1e-12);

# --- a rejected colorway cannot win, but it still counts as a rival ----------------------------
$bag_black_navy = [
    '3' => ['all' => 0.90, 'seed' => 0.90, 'ref' => 11, 'n' => 1],      # Black, rejected for this cutout
    '4' => ['all' => 0.89, 'seed' => 0.89, 'ref' => 12, 'n' => 1],      # Navy
    '5' => ['all' => 0.60, 'seed' => 0.60, 'ref' => 13, 'n' => 1],
];
$bag_res = pr_bag_pool_result($bag_black_navy, [3]);
pr_check("pool result: with Black rejected the next best key (Navy) is offered, and Black still lowers its margin",
         $bag_res['key'] === '4' && $bag_res['ref'] === 12 && abs($bag_res['margin'] - (0.89 - 0.90)) < 1e-12
         && pr_bag_pool_result($bag_black_navy)['key'] === '3');
pr_check("pool result: a pair key is rejected by its colorway part; every key rejected is no result",
         pr_bag_pool_result(['7|3' => ['all' => 0.9, 'seed' => 0.9, 'ref' => 1], '7|4' => ['all' => 0.8, 'seed' => 0.8, 'ref' => 2]], [3])['key'] === '7|4'
         && pr_bag_pool_result(['7|3' => ['all' => 0.9, 'seed' => 0.9, 'ref' => 1]], [3]) === null
         && pr_bag_pool_result(['13' => ['all' => 0.9, 'seed' => 0.9, 'ref' => 1]], [3])['key'] === '13');
$bag_decide_q = ['photo' => ['products' => [7], 'colorways' => [], 'cutouts' => 2], 'shoot' => '10,77'];
$bag_decide_seeds = [11 => ['product' => 7, 'shoot' => '10,20'], 12 => ['product' => 7, 'shoot' => '10,20']];
$d = pr_bag_decide($bag_decide_q, $bag_black_navy, [], $bag_decide_seeds, null, [3], $bag_cfg);
pr_check("decide: rejecting Black does not hide Navy; it goes to review (the margin is negative), never auto",
         $d['action'] === 'insert' && $d['match']['colorway_node'] === 4 && $d['match']['seed_ref'] === 12
         && $d['auto'] === false && $d['match']['margin'] < 0);
$d = pr_bag_decide($bag_decide_q, $bag_black_navy, [], $bag_decide_seeds, null, [3, 4], $bag_cfg);
pr_check("decide: with both rejected, only a key under the review floor is left, so nothing", $d['action'] === 'none');

# --- gate numbers --------------------------------------------------------------------------
pr_check("gate: colorway needs .85 and .10, inclusive; unknown margin never passes",
         pr_bag_gate_passes('colorway', 0.85, 0.10, $bag_cfg)
         && !pr_bag_gate_passes('colorway', 0.8499, 0.50, $bag_cfg)
         && !pr_bag_gate_passes('colorway', 0.99, 0.0999, $bag_cfg)
         && !pr_bag_gate_passes('colorway', 0.99, null, $bag_cfg));
pr_check("gate: pair needs .90 and .10, inclusive",
         pr_bag_gate_passes('pair', 0.90, 0.10, $bag_cfg)
         && !pr_bag_gate_passes('pair', 0.89, 0.50, $bag_cfg)
         && !pr_bag_gate_passes('pair', 0.95, 0.09, $bag_cfg));

# --- which row a cutout gets ---------------------------------------------------------------
$bag_c = ['key' => '3', 'similarity' => 0.88, 'margin' => 0.12, 'ref' => 5];
$bag_p = ['key' => '7|3', 'similarity' => 0.93, 'margin' => 0.20, 'ref' => 6];
$pick = pr_bag_choose($bag_c, $bag_p, $bag_cfg);
pr_check("choose: a passing pair wins and carries the product and colorway",
         $pick['gate'] === 'pair' && $pick['name_node'] === 7 && $pick['colorway_node'] === 3
         && $pick['passes'] === true && $pick['ref'] === 6);
$pick = pr_bag_choose($bag_c, ['margin' => 0.01] + $bag_p, $bag_cfg);
pr_check("choose: a passing colorway beats a pair that does not pass, with no product to write",
         $pick['gate'] === 'colorway' && $pick['name_node'] === null && $pick['colorway_node'] === 3
         && $pick['passes'] === true);
$pick = pr_bag_choose(['similarity' => 0.80, 'margin' => 0.02] + $bag_c, ['similarity' => 0.82, 'margin' => 0.5] + $bag_p, $bag_cfg);
pr_check("choose: with nothing passing, the more similar one goes to review",
         $pick['gate'] === 'pair' && $pick['passes'] === false);
pr_check("choose: under the .75 review floor is nothing",
         pr_bag_choose(['similarity' => 0.74] + $bag_c, null, $bag_cfg) === null
         && pr_bag_choose(['similarity' => 0.75] + $bag_c, null, $bag_cfg)['passes'] === false
         && pr_bag_choose(null, null, $bag_cfg) === null);
pr_check("choose: no margin means suggest only",
         pr_bag_choose(['margin' => null] + $bag_c, null, $bag_cfg)['passes'] === false);

# --- may it be written without a person ----------------------------------------------------
$bag_one = ['products' => [7], 'colorways' => [], 'cutouts' => 1];
pr_check("auto: both flags are off by default, whatever the numbers",
         !pr_bag_auto_ok('colorway', $bag_one, 7, true, $bag_cfg) && !pr_bag_auto_ok('pair', $bag_one, 7, true, $bag_cfg));
pr_check("auto colorway: exactly one product, one cutout, and the seed has that product",
         pr_bag_auto_ok('colorway', $bag_one, 7, false, $bag_on)
         && !pr_bag_auto_ok('colorway', $bag_one, 8, false, $bag_on)
         && !pr_bag_auto_ok('colorway', ['products' => [7], 'colorways' => [], 'cutouts' => 2], 7, false, $bag_on)
         && !pr_bag_auto_ok('colorway', ['products' => [7, 8], 'colorways' => [], 'cutouts' => 1], 7, false, $bag_on)
         && !pr_bag_auto_ok('colorway', ['products' => [], 'colorways' => [], 'cutouts' => 1], 7, false, $bag_on));
pr_check("auto pair: same shoot, and the photo has no product or already has the seed's",
         pr_bag_auto_ok('pair', ['products' => [], 'colorways' => [], 'cutouts' => 3], 7, true, $bag_on)
         && pr_bag_auto_ok('pair', ['products' => [7, 9], 'colorways' => [], 'cutouts' => 3], 7, true, $bag_on)
         && !pr_bag_auto_ok('pair', ['products' => [9], 'colorways' => [], 'cutouts' => 3], 7, true, $bag_on)
         && !pr_bag_auto_ok('pair', $bag_one, 7, false, $bag_on));
pr_check("auto: each gate obeys its own flag, and a never-auto-apply product is left for a person",
         !pr_bag_auto_ok('pair', $bag_one, 7, true, $bag_on_cw) && pr_bag_auto_ok('colorway', $bag_one, 7, true, $bag_on_cw)
         && !pr_bag_auto_ok('pair', $bag_one, 7, true, $bag_on, [7 => true])
         && !pr_bag_auto_ok('colorway', $bag_one, 7, true, $bag_on, [7 => true]));

# --- what a write may add (fill-empty, never replace) --------------------------------------
pr_check("plan: a single-cutout photo with nothing gets the name and the colorway",
         pr_bag_write_plan(['products' => [], 'colorways' => [], 'cutouts' => 1], 7, 3)
         === ['add_name' => true, 'add_colorway' => true, 'blocked' => '']);
pr_check("plan: a photo that already has the product only gains the colorway",
         pr_bag_write_plan($bag_one, 7, 3) === ['add_name' => false, 'add_colorway' => true, 'blocked' => '']
         && pr_bag_write_plan($bag_one, null, 3) === ['add_name' => false, 'add_colorway' => true, 'blocked' => '']);
pr_check("plan: product unknown and not inheritable writes nothing",
         pr_bag_write_plan(['products' => [], 'colorways' => [], 'cutouts' => 2], null, 3)
         === ['add_name' => false, 'add_colorway' => false, 'blocked' => 'no_product']);
pr_check("plan: a single-cutout photo with a different Name or Colorway is blocked, never replaced",
         pr_bag_write_plan($bag_one, 8, 3)['blocked'] === 'other_name'
         && pr_bag_write_plan(['products' => [7], 'colorways' => [4], 'cutouts' => 1], 7, 3)['blocked'] === 'other_colorway'
         && pr_bag_write_plan(['products' => [7], 'colorways' => [4], 'cutouts' => 1], 7, 3)['add_colorway'] === false);
pr_check("plan: the same colorway already there is a no-op, not a block",
         pr_bag_write_plan(['products' => [7], 'colorways' => [3], 'cutouts' => 1], 7, 3)
         === ['add_name' => false, 'add_colorway' => false, 'blocked' => '']);
pr_check("plan: a multi-cutout photo may add the matched bag's values beside its own",
         pr_bag_write_plan(['products' => [9], 'colorways' => [4], 'cutouts' => 3], 7, 3)
         === ['add_name' => true, 'add_colorway' => true, 'blocked' => '']
         && pr_bag_write_plan(['products' => [], 'colorways' => [], 'cutouts' => 3], 7, 3)['add_name'] === true);
pr_check("plan: a colorway-only match is blocked once the photo lost the seed's product; a pair match is not affected",
         pr_bag_write_plan($bag_one, null, 3, 7)['blocked'] === ''
         && pr_bag_write_plan($bag_one, null, 3, 9)['blocked'] === 'product_changed'
         && pr_bag_write_plan($bag_one, null, 3, 0)['blocked'] === 'product_changed'
         && pr_bag_write_plan($bag_one, 7, 3, 9)['blocked'] === '');
pr_check("plan: no colorway is no write", pr_bag_write_plan($bag_one, 7, 0)['blocked'] === 'no_colorway');

# --- undo ----------------------------------------------------------------------------------
pr_check("ids csv: dedupes, drops zero, round-trips",
         pr_bag_ids_csv([4, 5, 4, 0, -1]) === '4,5' && pr_bag_csv_ids('4,5,x,0,4') === [4, 5]
         && pr_bag_csv_ids('') === []);
$bag_undo = pr_bag_undo_plan('applied', '10,11', '30', [10, 30, 99]);
pr_check("undo: removes only what the match wrote and what is still there",
         $bag_undo['remove'] === [10, 30] && $bag_undo['already_gone'] === [11]);
pr_check("undo: a person's node the match did not write is untouched",
         pr_bag_undo_plan('applied', '', '30', [30, 31])['remove'] === [30]);
$bag_undo = pr_bag_undo_plan('applied', '10,11', '30', [10, 30], [10]);
pr_check("undo: a node another applied match on the photo also wrote stays",
         $bag_undo['remove'] === [30] && $bag_undo['protected'] === [10]);
pr_check("undo: a Colorway a person's accepted match relies on stays, though that match wrote nothing itself",
         pr_bag_undo_plan('applied', '', '30,31', [30, 31], [30])['remove'] === [31]);
pr_check("undo: only an applied match has anything to undo",
         pr_bag_undo_plan('open', '10', '30', [10, 30]) === ['remove' => [], 'already_gone' => [], 'protected' => []]
         && pr_bag_undo_plan('undone', '10', '30', [10, 30])['remove'] === []
         && pr_bag_undo_plan('applied', '', '', [10])['remove'] === []);


# --- the nightly decision for one cutout ---------------------------------------------------
$bag_seeds = [6 => ['product' => 7, 'shoot' => '10,20'], 5 => ['product' => 7, 'shoot' => '10,99']];
$bag_q = ['photo' => ['products' => [], 'colorways' => [], 'cutouts' => 3], 'shoot' => '10,20'];
$bag_pair_keys = ['7|3' => ['all' => 0.93, 'seed' => 0.93, 'ref' => 6, 'n' => 1], '7|4' => ['all' => 0.50, 'seed' => 0.50, 'ref' => 6, 'n' => 1]];
$d = pr_bag_decide($bag_q, [], $bag_pair_keys, $bag_seeds, null, [], $bag_cfg);
pr_check("decide: a passing pair on a photo with no product is inserted, same shoot, and not auto while the flag is off",
         $d['action'] === 'insert' && $d['match']['gate'] === 'pair' && $d['match']['name_node'] === 7
         && $d['match']['colorway_node'] === 3 && $d['match']['same_shoot'] === true && $d['auto'] === false);
$d = pr_bag_decide($bag_q, [], $bag_pair_keys, $bag_seeds, null, [], $bag_on);
pr_check("decide: the same match is auto once the product flag is on", $d['auto'] === true && $d['blocked'] === '');
$d = pr_bag_decide($bag_q, [], $bag_pair_keys, $bag_seeds, null, [], $bag_on, [7 => true]);
pr_check("decide: a never-auto-apply product stays in review", $d['action'] === 'insert' && $d['auto'] === false);
$open = ['ref' => 40, 'name_node' => 7, 'colorway_node' => 3, 'seed_ref' => 6];
$d = pr_bag_decide($bag_q, [], $bag_pair_keys, $bag_seeds, $open, [], $bag_cfg);
pr_check("decide: the same match against its open row is an update, not a second row",
         $d['action'] === 'update' && $d['open_ref'] === 40);
$d = pr_bag_decide($bag_q, [], $bag_pair_keys, $bag_seeds, ['colorway_node' => 9] + $open, [], $bag_cfg);
pr_check("decide: a different match replaces the open row", $d['action'] === 'insert' && $d['open_ref'] === 40);
$d = pr_bag_decide($bag_q, [], ['7|3' => ['all' => 0.60, 'seed' => 0.60, 'ref' => 6, 'n' => 1]], $bag_seeds, $open, [], $bag_cfg);
pr_check("decide: an open row that no longer clears the floor is closed; with no open row, nothing",
         $d['action'] === 'close'
         && pr_bag_decide($bag_q, [], [], $bag_seeds, null, [], $bag_cfg)['action'] === 'none');
$d = pr_bag_decide($bag_q, [], $bag_pair_keys, $bag_seeds, null, [3], $bag_cfg);
pr_check("decide: a rejected colorway is not offered again", $d['action'] === 'none');
$bag_q_one = ['photo' => ['products' => [7], 'colorways' => [], 'cutouts' => 1], 'shoot' => '10,77'];
$bag_cw_keys = ['3' => ['all' => 0.90, 'seed' => 0.90, 'ref' => 6, 'n' => 1], '4' => ['all' => 0.70, 'seed' => 0.70, 'ref' => 5, 'n' => 1]];
$d = pr_bag_decide($bag_q_one, $bag_cw_keys, [], $bag_seeds, null, [], $bag_on);
pr_check("decide: a colorway-only match on a one-cutout photo with that product is auto, from another shoot",
         $d['match']['gate'] === 'colorway' && $d['match']['name_node'] === null && $d['match']['same_shoot'] === false
         && $d['auto'] === true);
$d = pr_bag_decide(['photo' => ['products' => [7], 'colorways' => [], 'cutouts' => 2]] + $bag_q_one, $bag_cw_keys, [], $bag_seeds, null, [], $bag_on);
pr_check("decide: the colorway-only gate never auto-writes on a multi-cutout photo, it still suggests",
         $d['action'] === 'insert' && $d['auto'] === false);
$d = pr_bag_decide(['photo' => ['products' => [7], 'colorways' => [4], 'cutouts' => 1]] + $bag_q_one, $bag_cw_keys, [], $bag_seeds, null, [], $bag_on);
pr_check("decide: a single-cutout photo with another colorway goes to review, blocked, never auto",
         $d['action'] === 'insert' && $d['blocked'] === 'other_colorway' && $d['auto'] === false);
$d = pr_bag_decide(['photo' => ['products' => [], 'colorways' => [], 'cutouts' => 1]] + $bag_q_one, $bag_cw_keys, [], $bag_seeds, null, [], $bag_on);
pr_check("decide: product unknown and not inheritable opens nothing",
         $d['action'] === 'none' && $d['match'] === null && $d['auto'] === false);
$d = pr_bag_decide(['photo' => ['products' => [7], 'colorways' => [3], 'cutouts' => 1]] + $bag_q_one, $bag_cw_keys, [], $bag_seeds, null, [], $bag_on);
pr_check("decide: a photo that already has this colorway is left alone, and closes a stale open row",
         $d['action'] === 'none' && pr_bag_decide(['photo' => ['products' => [7], 'colorways' => [3], 'cutouts' => 1]] + $bag_q_one,
             $bag_cw_keys, [], $bag_seeds, $open, [], $bag_cfg)['action'] === 'close');
$bag_both = pr_bag_decide($bag_q_one, $bag_cw_keys, $bag_pair_keys, $bag_seeds, null, [], $bag_on_cw);
pr_check("decide: with only the colorway flag on, the colorway gate is the one that auto-applies even when the pair gate also passes",
         $bag_both['match']['gate'] === 'colorway' && $bag_both['auto'] === true
         && pr_bag_decide($bag_q_one, $bag_cw_keys, $bag_pair_keys, $bag_seeds, null, [], $bag_cfg)['match']['gate'] === 'pair');
$d = pr_bag_decide($bag_q_one, ['3' => ['all' => 0.90, 'seed' => 0.90, 'ref' => 99, 'n' => 1]], [], $bag_seeds, null, [], $bag_on);
pr_check("decide: a score for a seed that is no longer there is dropped", $d['action'] === 'none');

# --- importing tags ------------------------------------------------------------------------
$bag_row = ['resource' => 5, 'region_index' => 0, 'file_checksum' => 'abc', 'bbox' => [.1, .1, .5, .9],
            'name' => 'Loma', 'colorway' => 'Evergreen', 'user' => 1];
$bag_now = ['file_checksum' => 'abc', 'bboxes' => [0 => [.1, .1, .5, .9]]];
pr_check("import: a row that still matches the cutout is accepted",
         pr_bag_import_check($bag_row, $bag_now, 7, 3) === ['ok' => true, 'reason' => '']);
pr_check("import: rejects a changed file, a moved box, a missing cutout, unknown labels, and no product",
         pr_bag_import_check($bag_row, ['file_checksum' => 'new'] + $bag_now, 7, 3)['reason'] === 'checksum'
         && pr_bag_import_check($bag_row, ['bboxes' => [0 => [.1, .1, .5, .5]]] + $bag_now, 7, 3)['reason'] === 'bbox'
         && pr_bag_import_check(['region_index' => 4] + $bag_row, $bag_now, 7, 3)['reason'] === 'no_cutout'
         && pr_bag_import_check($bag_row, $bag_now, null, 3)['reason'] === 'unknown_name'
         && pr_bag_import_check($bag_row, $bag_now, 7, null)['reason'] === 'unknown_colorway'
         && pr_bag_import_check(['name' => ' '] + $bag_row, $bag_now, 7, 3)['reason'] === 'no_product'
         && pr_bag_import_check(['colorway' => ''] + $bag_row, $bag_now, 7, 3)['reason'] === 'no_colorway'
         && pr_bag_import_check($bag_row, null, 7, 3)['reason'] === 'no_resource'
         && pr_bag_import_check(['file_checksum' => ''] + $bag_row, $bag_now, 7, 3)['reason'] === 'checksum');

# --- the report and its enable condition ---------------------------------------------------
pr_check("report halves: deterministic, and both halves are used",
         pr_bag_report_held_out('10,20,30') === pr_bag_report_held_out('10,20,30')
         && count(array_unique(array_map(static fn(int $n): bool => pr_bag_report_held_out((string)$n), range(1, 40)))) === 2);
$bag_chunks = pr_bag_report_chunks([['resource' => 1, 'ref' => 1], ['resource' => 2, 'ref' => 2], ['resource' => 3, 'ref' => 3], ['resource' => 1, 'ref' => 4]], 2);
pr_check("report chunks: every seed once, and a photo's seeds are never split",
         array_map(static fn(array $c): array => array_column($c, 'ref'), $bag_chunks) === [[1, 4], [2, 3]]
         && pr_bag_report_chunks([]) === []);
$bag_win = ['key' => '3', 'similarity' => 0.95, 'margin' => 0.2, 'ref' => 1];
pr_check("report outcome: right, wrong, or nothing passed",
         pr_bag_report_outcome('colorway', $bag_win, '3', $bag_cfg) === 'right'
         && pr_bag_report_outcome('colorway', $bag_win, '4', $bag_cfg) === 'wrong'
         && pr_bag_report_outcome('colorway', ['margin' => 0.05] + $bag_win, '3', $bag_cfg) === 'none'
         && pr_bag_report_outcome('pair', null, '7|3', $bag_cfg) === 'none');
$bag_good = ['loo:colorway' => ['right' => 49, 'wrong' => 0, 'none' => 9], 'loo:pair' => ['right' => 60, 'wrong' => 1, 'none' => 2],
             'hidden:colorway' => ['right' => 0, 'wrong' => 0, 'none' => 58], 'hidden:pair' => ['right' => 0, 'wrong' => 0, 'none' => 63]];
pr_check("verdict: 98% right or better in leave-one-out and nothing tagged with the true colorway hidden passes",
         pr_bag_report_verdict($bag_good)['ok'] === true);
$bag_bad = $bag_good;
$bag_bad['loo:colorway'] = ['right' => 48, 'wrong' => 2, 'none' => 0];
pr_check("verdict: 96% right fails",
         pr_bag_report_verdict($bag_bad)['ok'] === false);
$bag_bad = $bag_good;
$bag_bad['hidden:pair']['wrong'] = 1;
pr_check("verdict: one tag with the true colorway hidden fails, however good the rest",
         pr_bag_report_verdict($bag_bad)['ok'] === false);
pr_check("verdict: nothing tagged is no evidence, not a pass",
         pr_bag_report_verdict([])['ok'] === false);



# --- identity: file checksum + box, never the index ----------------------------------------
pr_check("stored bbox: JSON list, nested list and CSV all read the same; junk is empty",
         pr_bag_stored_bbox('[0.1,0.2,0.3,0.4]') === [0.1, 0.2, 0.3, 0.4]
         && pr_bag_stored_bbox('[[0.1,0.2,0.3,0.4]]') === [0.1, 0.2, 0.3, 0.4]
         && pr_bag_stored_bbox('0.1,0.2,0.3,0.4') === [0.1, 0.2, 0.3, 0.4]
         && pr_bag_stored_bbox('') === [] && pr_bag_stored_bbox('[1,2]') === [] && pr_bag_stored_bbox('nope') === []);
$bag_now = ['file_checksum' => 'F1', 'bboxes' => [0 => [0.60, 0.1, 0.9, 0.9], 1 => [0.1, 0.1, 0.5, 0.9]]];
$bag_row = ['resource' => 5, 'region_index' => 0, 'file_checksum' => 'F1', 'bbox' => '[0.1,0.1,0.5,0.9]', 'ref' => 1];
pr_check("identity: a row re-binds to the cutout with its box after the indexes were renumbered (0 became 1)",
         pr_bag_bind_row($bag_row, $bag_now) === 1);
pr_check("identity: the same index but a different box does not bind (index alone is not identity)",
         pr_bag_bind_row(['bbox' => '[0.6,0.1,0.9,0.9]'] + $bag_row, ['file_checksum' => 'F1', 'bboxes' => [0 => [0.1, 0.1, 0.5, 0.9]]]) === null);
pr_check("identity: after a file replacement (checksum differs) nothing re-binds, even an identical box",
         pr_bag_bind_row($bag_row, ['file_checksum' => 'F2', 'bboxes' => [1 => [0.1, 0.1, 0.5, 0.9]]]) === null
         && pr_bag_bind_row($bag_row, null) === null);
$bag_two = pr_bag_bind_rows([
    ['ref' => 1, 'resource' => 5, 'region_index' => 0, 'file_checksum' => 'F1', 'bbox' => '[0.1,0.1,0.5,0.9]'],
    ['ref' => 2, 'resource' => 5, 'region_index' => 7, 'file_checksum' => 'F1', 'bbox' => '[0.1,0.1,0.5,0.9]'],
    ['ref' => 3, 'resource' => 5, 'region_index' => 1, 'file_checksum' => 'OLD', 'bbox' => '[0.1,0.1,0.5,0.9]'],
], [5 => $bag_now]);
pr_check("identity: two rows for one cutout bind to the same index and only the higher ref is newest; a row for another file is unbound",
         $bag_two[0]['bound'] === 1 && $bag_two[1]['bound'] === 1 && $bag_two[0]['newest'] === false
         && $bag_two[1]['newest'] === true && $bag_two[2]['bound'] === null && $bag_two[2]['newest'] === false);


# --- a stored feature row is the cutout only if file and box say so ------------------------
$bag_feat_now = [5 => ['file_checksum' => 'F1', 'bboxes' => [0 => [0.1, 0.1, 0.5, 0.9], 1 => [0.6, 0.1, 0.9, 0.9]]]];
$bag_feat = static fn(int $i, string $sum, string $box): array => ['ref' => $i + 1, 'resource' => 5, 'region_index' => $i, 'file_checksum' => $sum, 'bbox' => $box];
$bag_fresh = pr_bag_current_features([
    $bag_feat(0, 'F1', '[0.1,0.1,0.5,0.9]'),       # current
    $bag_feat(1, 'F1', '[0.1,0.1,0.5,0.9]'),       # stored under the old numbering: index 1 is another bag now
], $bag_feat_now);
pr_check("features: a row whose box is not the box now at its index is a leftover from a re-cut, however the checksum reads",
         array_keys($bag_fresh) === ['5:0']);
pr_check("features: another file's row, or a row for a cutout that is gone, is no use",
         pr_bag_current_features([$bag_feat(0, 'OLD', '[0.1,0.1,0.5,0.9]'), $bag_feat(3, 'F1', '[0.1,0.1,0.5,0.9]')], $bag_feat_now) === []
         && pr_bag_current_features([$bag_feat(0, 'F1', '[0.1,0.1,0.5,0.9]')], []) === []);
pr_check("features: a box that moved a little (IoU >= 0.9) is still the cutout",
         array_keys(pr_bag_current_features([$bag_feat(0, 'F1', '[0.11,0.1,0.5,0.9]')], $bag_feat_now)) === ['5:0']);

pr_check("features: no answer with the service up skips the cutout; with it down, stop, unless this same cutout was the one running last time",
         pr_bag_no_answer_action(true, '', '5:0') === 'skip'
         && pr_bag_no_answer_action(false, '', '5:0') === 'stop'
         && pr_bag_no_answer_action(false, '4:1', '5:0') === 'stop'
         && pr_bag_no_answer_action(false, '5:0', '5:0') === 'skip');

# --- the three tables: CREATE statements match dbstruct, and only the CLI writes them ------
$bag_defs = pr_bag_table_definitions();
pr_check("tables: exactly the three bag tables, each CREATE TABLE IF NOT EXISTS",
         array_keys($bag_defs) === ['product_bag_tag', 'product_cutout_feature', 'product_bag_match']
         && count(array_filter($bag_defs, static fn(string $q, string $t): bool => str_starts_with($q, "CREATE TABLE IF NOT EXISTS $t ("), ARRAY_FILTER_USE_BOTH)) === 3);
$bag_dbstruct_bad = [];
foreach ($bag_defs as $bag_table => $bag_sql) {
    foreach (file(dirname(__DIR__) . "/dbstruct/table_$bag_table.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $bag_line) {
        [$bag_col, $bag_type, $bag_null, $bag_key, $bag_default, $bag_extra] = array_pad(explode(',', $bag_line, 6), 6, '');
        $bag_type = preg_replace('/^(int|bigint)\(\d+\)$/', '$1', $bag_type);
        if (!preg_match('/^\s+' . preg_quote($bag_col, '/') . ' (\S+?)(?: (NOT NULL))?(?: AUTO_INCREMENT)?(?: DEFAULT ([^\s,]+))?,?$/m', $bag_sql, $bag_m)) {
            $bag_dbstruct_bad[] = "$bag_table.$bag_col missing";
            continue;
        }
        $bag_has_default = isset($bag_m[3]);
        $bag_want_default = $bag_default === '' ? null : trim($bag_default, "'");
        if ($bag_m[1] !== $bag_type) {
            $bag_dbstruct_bad[] = "$bag_table.$bag_col type {$bag_m[1]} vs $bag_type";
        }
        if ((($bag_m[2] ?? '') === 'NOT NULL') !== ($bag_null === 'NO')) {
            $bag_dbstruct_bad[] = "$bag_table.$bag_col nullability";
        }
        if ($bag_want_default !== null && (!$bag_has_default || trim($bag_m[3], "'") !== $bag_want_default)) {
            $bag_dbstruct_bad[] = "$bag_table.$bag_col default";
        }
        if (str_contains($bag_extra, 'auto_increment') !== str_contains($bag_sql, "$bag_col int NOT NULL AUTO_INCREMENT")) {
            $bag_dbstruct_bad[] = "$bag_table.$bag_col auto_increment";
        }
    }
    $bag_indexes = [];
    foreach (glob(dirname(__DIR__) . "/dbstruct/index_$bag_table.txt") as $bag_file) {
        foreach (file($bag_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $bag_line) {
            $c = explode(',', $bag_line);
            $bag_indexes[$c[2]]['unique'] = $c[1] === '0';
            $bag_indexes[$c[2]]['cols'][(int)$c[3]] = $c[4];
        }
    }
    foreach ($bag_indexes as $bag_name => $bag_ix) {
        ksort($bag_ix['cols']);
        $bag_want = ($bag_ix['unique'] ? 'UNIQUE KEY' : 'KEY') . " $bag_name (" . implode(', ', $bag_ix['cols']) . ')';
        if (!str_contains($bag_sql, $bag_want)) {
            $bag_dbstruct_bad[] = "$bag_table index $bag_want";
        }
    }
    if (substr_count($bag_sql, 'KEY ') !== count($bag_indexes) + 1) {     # + PRIMARY KEY
        $bag_dbstruct_bad[] = "$bag_table has an index dbstruct does not";
    }
}
pr_check("tables: every dbstruct column (type, NOT NULL, default, auto_increment) and index is in the CREATE statement" . ($bag_dbstruct_bad ? ' (' . implode('; ', $bag_dbstruct_bad) . ')' : ''),
         $bag_dbstruct_bad === []);
# Web requests, read-only modes and the hot paths never create tables or run the structure check.
$bag_src = static fn(string $f): string => (string)file_get_contents(dirname(__DIR__) . '/' . $f);
$bag_no_check = true;
foreach (array_merge(glob(dirname(__DIR__) . '/include/*.php'), glob(dirname(__DIR__) . '/hooks/*.php'), glob(dirname(__DIR__) . '/pages/*.php')) as $bag_file) {
    foreach (token_get_all((string)file_get_contents($bag_file)) as $bag_tok) {       # code only, not comments
        if (is_array($bag_tok) && $bag_tok[0] === T_STRING && $bag_tok[1] === 'check_db_structs') {
            $bag_no_check = false;
        }
    }
}
$bag_ensure = $bag_src('include/product_recognition_bag_match.php');
$bag_ensure = substr($bag_ensure, (int)strpos($bag_ensure, 'function pr_bag_ensure_tables'), 700);
pr_check("tables: nothing calls check_db_structs(), and creating the tables refuses to run outside the CLI",
         $bag_no_check && str_contains($bag_ensure, "PHP_SAPI !== 'cli'") && str_contains($bag_ensure, 'pr_bag_table_definitions()'));
$bag_callers = [];
foreach (array_merge(glob(dirname(__DIR__) . '/include/*.php'), glob(dirname(__DIR__) . '/hooks/*.php'), glob(dirname(__DIR__) . '/pages/*.php')) as $bag_file) {
    if (preg_match_all('/pr_bag_ensure_tables\(/', (string)file_get_contents($bag_file), $bag_m) && !str_ends_with($bag_file, 'product_recognition_bag_match.php')) {
        $bag_callers[] = basename($bag_file);
    }
}
pr_check("tables: no hook or page calls pr_bag_ensure_tables()", $bag_callers === []);
$bag_match_src = $bag_src('include/product_recognition_bag_match.php');
pr_check("tables: the review tab, accept and reject check the tables exist before they query them",
         preg_match('/function pr_bag_render_review\(\): void\s*\{\s*global[^;]*;\s*if \(!pr_bag_tables_exist\(\)\)/', $bag_match_src) === 1
         && preg_match('/function pr_bag_apply_match\([^)]*\): string\s*\{\s*if \(!pr_bag_tables_exist\(\)\)/', $bag_match_src) === 1
         && preg_match('/function pr_bag_reject_match\([^)]*\): string\s*\{\s*if \(!pr_bag_tables_exist\(\)\)/', $bag_match_src) === 1);

# --- forms: the AJAX post never sends the clicked button -----------------------------------
$bag_view = $bag_src('hooks/view.php');
pr_check("forms: the panel's bag Accept and Reject carry the action in a hidden field, not in a submit button's name",
         !preg_match('/<button[^>]*name="action"[^>]*value="bag_/', $bag_view)
         && str_contains($bag_view, "'bag_accept' => 'product_recognition_accept'")
         && preg_match('/<input type="hidden" name="action" value="<\?php echo escape\(\$bag_action\)/', $bag_view) === 1);
pr_check("forms: the panel's Apply form carries the file checksum and box, and apply.php passes them to the seed save",
         preg_match('/pr_catalog_apply.*?name="file_checksum".*?name="bbox"/s', $bag_view) === 1
         && preg_match('/pr_bag_save_tag\([^;]*getval\("file_checksum"[^;]*getval\("bbox"/s', $bag_src('pages/apply.php')) === 1);
pr_check("panel: tells the person when the tables are missing instead of showing nothing",
         str_contains($bag_view, "pr_bag_disabled_reason() === 'tables'") && str_contains($bag_view, 'product_recognition_bag_no_tables'));

# --- import rows must be objects -----------------------------------------------------------
pr_check("import: only a JSON object is a row; a number, string, null, list or bool is rejected, not fatal",
         pr_bag_import_row_is_object(['resource' => 1]) && pr_bag_import_row_is_object([])
         && !pr_bag_import_row_is_object(5) && !pr_bag_import_row_is_object('x') && !pr_bag_import_row_is_object(null)
         && !pr_bag_import_row_is_object([1, 2]) && !pr_bag_import_row_is_object(true));

# --- language strings: every key the bag screens use exists, and none has an em dash -------
# Loaded in a closure: this file is required into the main self-check's scope, where $lang is ResourceSpace's.
$bag_lang = (static function (): array {
    $lang = [];
    include dirname(__DIR__) . '/languages/en.php';
    return $lang;
})();
$bag_used = [];
foreach (['include/product_recognition_bag_match.php', 'hooks/view.php', 'pages/review.php', 'pages/bag_tag.php', 'pages/apply.php'] as $bag_file) {
    preg_match_all("/(?:\\\$lang\\[|=>\\s*)['\"](product_recognition_bag_[a-z_]*[a-z])['\"]/", (string)file_get_contents(dirname(__DIR__) . '/' . $bag_file), $bag_m);
    $bag_used = array_merge($bag_used, $bag_m[1]);
}
$bag_missing = array_values(array_filter(array_unique($bag_used), static fn(string $k): bool => !isset($bag_lang[$k])));
# keys built at run time: product_recognition_bag_blocked_<reason>
foreach (['other_name', 'other_colorway', 'no_product', 'product_changed', 'colorway_gone', 'no_colorway'] as $bag_reason) {
    if (!isset($bag_lang['product_recognition_bag_blocked_' . $bag_reason])) {
        $bag_missing[] = 'product_recognition_bag_blocked_' . $bag_reason;
    }
}
pr_check("language: every product_recognition_bag_* key the screens use is in languages/en.php" . ($bag_missing ? ' (missing ' . implode(', ', $bag_missing) . ')' : ''),
         $bag_missing === [] && count($bag_used) > 10);
$bag_ui = array_filter($bag_lang, static fn($v, $k): bool => str_starts_with((string)$k, 'product_recognition_bag_') && is_string($v) && str_contains($v, "\u{2014}"), ARRAY_FILTER_USE_BOTH);
pr_check("language: no em dash in the bag strings", $bag_ui === []);

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    echo "\nall checks passed\n";
}
