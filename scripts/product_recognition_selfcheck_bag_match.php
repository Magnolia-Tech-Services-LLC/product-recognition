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

# --- config: cutoffs are the spec's, and there is no switch that writes tags ----------------
$bag_cfg = pr_bag_config([]);
pr_check("config defaults: cutoffs .85/.10 and .90/.10, review floor .75, no auto-apply setting at all",
         $bag_cfg['colorway_sim'] === 0.85 && $bag_cfg['colorway_margin'] === 0.10
         && $bag_cfg['product_sim'] === 0.90 && $bag_cfg['product_margin'] === 0.10
         && $bag_cfg['review_floor'] === 0.75 && $bag_cfg['copy_segments'] === []
         && !array_key_exists('colorway_auto', $bag_cfg) && !array_key_exists('product_auto', $bag_cfg)
         && !array_key_exists('colorway_auto', pr_bag_config(['product_recognition_bag_colorway_auto_apply' => true])));
$bag_seg = pr_bag_config(['product_recognition_bag_copy_segments' => "Copy, Goat Hill (Copy);; "]);
pr_check("config: copy segments read from settings, folded and deduplicated",
         $bag_seg['copy_segments'] === ['copy', 'goat hill copy']);

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
pr_check("decide: rejecting Black does not hide Navy; it goes to review (the margin is negative)",
         $d['action'] === 'insert' && $d['match']['colorway_node'] === 4 && $d['match']['seed_ref'] === 12
         && $d['match']['margin'] < 0);
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

# --- which strict gate a suggestion is (what a later automatic step could write) -------------
$bag_one = ['products' => [7], 'colorways' => [], 'cutouts' => 1];
pr_check("auto colorway: exactly one product, one cutout, and the seed has that product",
         pr_bag_auto_ok('colorway', $bag_one, 7, false)
         && !pr_bag_auto_ok('colorway', $bag_one, 8, false)
         && !pr_bag_auto_ok('colorway', ['products' => [7], 'colorways' => [], 'cutouts' => 2], 7, false)
         && !pr_bag_auto_ok('colorway', ['products' => [7, 8], 'colorways' => [], 'cutouts' => 1], 7, false)
         && !pr_bag_auto_ok('colorway', ['products' => [], 'colorways' => [], 'cutouts' => 1], 7, false));
pr_check("auto pair: same shoot, and the photo has no product or already has the seed's",
         pr_bag_auto_ok('pair', ['products' => [], 'colorways' => [], 'cutouts' => 3], 7, true)
         && pr_bag_auto_ok('pair', ['products' => [7, 9], 'colorways' => [], 'cutouts' => 3], 7, true)
         && !pr_bag_auto_ok('pair', ['products' => [9], 'colorways' => [], 'cutouts' => 3], 7, true)
         && !pr_bag_auto_ok('pair', $bag_one, 7, false));
pr_check("auto: a never-auto-apply product, or no product, is never the kind a later step could write",
         !pr_bag_auto_ok('pair', $bag_one, 7, true, [7 => true]) && !pr_bag_auto_ok('colorway', $bag_one, 7, true, [7 => true])
         && !pr_bag_auto_ok('colorway', $bag_one, 0, true) && pr_bag_auto_ok('colorway', $bag_one, 7, true, [8 => true]));

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

# --- the node ids a match wrote ------------------------------------------------------------
pr_check("ids csv: dedupes, drops zero",
         pr_bag_ids_csv([4, 5, 4, 0, -1]) === '4,5' && pr_bag_ids_csv([]) === '');

# --- a seed Accept can still stand on --------------------------------------------------------
$bag_tag = ['ref' => 5, 'resource' => 5, 'region_index' => 0, 'file_checksum' => 'abc', 'bbox' => '[0.1,0.1,0.5,0.9]', 'name_node' => 7, 'colorway_node' => 3];
$bag_now_s = ['file_checksum' => 'abc', 'bboxes' => [0 => [.1, .1, .5, .9]]];
$bag_feat_ok = ['status' => 'ok', 'attempts' => 0];
$bag_reason = static fn(?array $now, ?array $f, array $tag = [], array $prod = [7 => 'P'], array $cw = [3 => 'C']): string
    => pr_bag_seed_reason($tag + $bag_tag, $now, $f, $prod, $cw)['reason'];
pr_check("seed reason: usable is '' with the cutout it binds to; every way a seed stops being usable has its own reason",
         pr_bag_seed_reason($bag_tag, $bag_now_s, $bag_feat_ok, [7 => 'P'], [3 => 'C']) === ['reason' => '', 'region' => 0]
         && $bag_reason($bag_now_s, $bag_feat_ok, [], [], [3 => 'C']) === 'node_gone' && $bag_reason($bag_now_s, $bag_feat_ok, [], [7 => 'P'], []) === 'node_gone'
         && $bag_reason(null, null) === 'resource_gone' && $bag_reason(['file_checksum' => 'new'] + $bag_now_s, null) === 'file_changed'
         && $bag_reason(['bboxes' => [0 => [.6, .6, .9, .9]]] + $bag_now_s, null) === 'no_cutout_overlaps'
         && $bag_reason($bag_now_s, null) === 'features_pending' && $bag_reason($bag_now_s, ['status' => 'attempt', 'attempts' => PR_BAG_MAX_ATTEMPTS]) === 'features_failed'
         && $bag_reason($bag_now_s, ['status' => 'unusable', 'attempts' => 0]) === 'cutout_unusable');
pr_check("seed reason: Accept stands on a usable seed or one only waiting for its features, on no other",
         pr_bag_seed_reason_acceptable('') && pr_bag_seed_reason_acceptable('features_pending')
         && !pr_bag_seed_reason_acceptable('features_failed') && !pr_bag_seed_reason_acceptable('node_gone') && !pr_bag_seed_reason_acceptable('cutout_unusable'));

# --- a blocked preferred row gives way to an acceptable one from the other gate ----------------
$bag_q_blk = ['photo' => ['products' => [7], 'colorways' => [4], 'cutouts' => 2], 'shoot' => '10,20'];
$bag_blk_seeds = [6 => ['product' => 7, 'shoot' => '10,20'], 5 => ['product' => 7, 'shoot' => '10,99']];
# colorway gate would pick colorway 3 from seed 5 but the colorway-only plan is fine here; make the colorway row blocked instead
$bag_q_one_blk = ['photo' => ['products' => [7], 'colorways' => [4], 'cutouts' => 1], 'shoot' => '10,20'];
$d = pr_bag_decide($bag_q_one_blk, ['3' => ['all' => 0.90, 'seed' => 0.90, 'ref' => 6, 'n' => 1], '4' => ['all' => 0.5, 'seed' => 0.5, 'ref' => 6, 'n' => 1]],
                   ['7|3' => ['all' => 0.93, 'seed' => 0.93, 'ref' => 6, 'n' => 1]], $bag_blk_seeds, null, [], $bag_cfg);
pr_check("decide: with the photo already on another colorway, both gates are blocked, and the blocked row is still kept for a person",
         $d['action'] === 'insert' && $d['blocked'] === 'other_colorway');
$bag_q_other = ['photo' => ['products' => [9], 'colorways' => [], 'cutouts' => 1], 'shoot' => '10,20'];
$d = pr_bag_decide($bag_q_other, ['3' => ['all' => 0.90, 'seed' => 0.90, 'ref' => 6, 'n' => 1]],
                   ['7|3' => ['all' => 0.93, 'seed' => 0.93, 'ref' => 6, 'n' => 1]], $bag_blk_seeds, null, [], $bag_cfg);
pr_check("decide: the preferred pair row would add a Name the one-cutout photo contradicts (blocked), the colorway-only row can be written, so it wins",
         $d['action'] === 'insert' && $d['blocked'] === '' && $d['match']['gate'] === 'colorway');
$d = pr_bag_decide($bag_q_other, [], ['7|3' => ['all' => 0.93, 'seed' => 0.93, 'ref' => 6, 'n' => 1]], $bag_blk_seeds, null, [], $bag_cfg);
pr_check("decide: a blocked row alone is kept (blocked), so the person sees why", $d['action'] === 'insert' && $d['blocked'] === 'other_name');

# --- what the feature pass does when a cutout fails ---------------------------------------------
pr_check("failure plan: no answer and no service afterwards keeps the try, stops, and moves past the cutout",
         pr_bag_failure_plan(true, false, true) === ['keep_try' => true, 'stop' => true, 'cursor' => 'past']);
pr_check("failure plan: no answer but the service came back (it died on this cutout) keeps the try and goes on",
         pr_bag_failure_plan(true, true, true) === ['keep_try' => true, 'stop' => false, 'cursor' => 'stay']);
pr_check("failure plan: an error answer with a failing probe is the service's: give the try back, stop, retry this cutout first",
         pr_bag_failure_plan(false, true, false) === ['keep_try' => false, 'stop' => true, 'cursor' => 'retry']);
pr_check("failure plan: an error answer with a working probe is the cutout's: keep the try, go on (a poison cutout cannot stop the run)",
         pr_bag_failure_plan(false, true, true) === ['keep_try' => true, 'stop' => false, 'cursor' => 'stay']);
# the same decision over a night: tries a run of cutouts end up with
$bag_night = static function (array $outcomes): array {
    $tries = [];
    foreach ($outcomes as $i => $o) {                  # o: [no_answer, back, probe_ok]
        $plan = pr_bag_failure_plan($o[0], $o[1], $o[2]);
        $tries[$i] = $plan['keep_try'] ? 1 : 0;
        if ($plan['stop']) {
            break;
        }
    }
    return $tries;
};
pr_check("failure plan: a model-failure night (every error answer, probe failing) gives back the first try and stops, however many cutouts follow",
         $bag_night(array_fill(0, 20, [false, true, false])) === [0]);
pr_check("failure plan: three poison cutouts among good ones keep their tries and the run reaches the end",
         $bag_night([[false, true, true], [false, true, true], [false, true, true], [false, true, true]]) === [1, 1, 1, 1]);
$bag_png = pr_bag_probe_png();
$bag_ihdr = unpack('Nw/Nh/Cdepth/Ctype', substr($bag_png, 16, 10));
$bag_idat_len = unpack('N', substr($bag_png, 33, 4))[1];
$bag_pix = (string)gzuncompress(substr($bag_png, 41, $bag_idat_len));
$bag_opaque = 0;
for ($bag_y = 0; $bag_y < 64; $bag_y++) {
    for ($bag_x = 0; $bag_x < 64; $bag_x++) {
        $bag_opaque += ord($bag_pix[$bag_y * (1 + 64 * 4) + 1 + $bag_x * 4 + 3]) > 128 ? 1 : 0;
    }
}
pr_check("probe png: a valid 64 x 64 RGBA file (color type 6) with at least 1500 pixels of alpha > 128, the same bytes every time, crc checked",
         str_starts_with($bag_png, "\x89PNG\r\n\x1a\n") && $bag_ihdr['w'] === 64 && $bag_ihdr['h'] === 64 && $bag_ihdr['depth'] === 8 && $bag_ihdr['type'] === 6
         && strlen($bag_pix) === 64 * (1 + 64 * 4) && $bag_opaque >= 1500 && $bag_opaque < 64 * 64
         && $bag_png === pr_bag_probe_png() && unpack('N', substr($bag_png, 29, 4))[1] === crc32(substr($bag_png, 12, 17))
         && ord($bag_png[25]) === 6 && str_ends_with($bag_png, "\0\0\0\0IEND\xaeB`\x82"));

# --- UNIQUE keys on tables RS created -----------------------------------------------------------
$bag_idx = static fn(string $name, int $non_unique, array $cols): array => array_map(
    static fn(int $i, string $c): array => ['INDEX_NAME' => $name, 'NON_UNIQUE' => (string)$non_unique, 'SEQ_IN_INDEX' => (string)($i + 1), 'COLUMN_NAME' => $c],
    array_keys($cols), $cols);
$bag_pk = $bag_idx('PRIMARY', 0, ['ref']);
pr_check("unique key: present (any name, exact columns, unique) is ok; none is added; a non-unique index of the same name is replaced",
         pr_bag_unique_key_action(array_merge($bag_pk, $bag_idx('cutout_feature_key', 0, ['resource', 'region_index'])), 'cutout_feature_key', ['resource', 'region_index']) === 'ok'
         && pr_bag_unique_key_action(array_merge($bag_pk, $bag_idx('other', 0, ['resource', 'region_index'])), 'cutout_feature_key', ['resource', 'region_index']) === 'ok'
         && pr_bag_unique_key_action($bag_pk, 'cutout_feature_key', ['resource', 'region_index']) === 'add'
         && pr_bag_unique_key_action(array_merge($bag_pk, $bag_idx('cutout_feature_key', 1, ['resource', 'region_index'])), 'cutout_feature_key', ['resource', 'region_index']) === 'replace'
         && pr_bag_unique_key_action(array_merge($bag_pk, $bag_idx('cutout_feature_key', 0, ['resource'])), 'cutout_feature_key', ['resource', 'region_index']) === 'replace'
         && pr_bag_unique_key_action(array_merge($bag_pk, $bag_idx('x', 0, ['region_index', 'resource'])), 'cutout_feature_key', ['resource', 'region_index']) === 'add');

# --- a failed Accept: what is taken back ---------------------------------------------------------
pr_check("recover plan: only planned nodes that are on the photo now, never one another accepted match wrote or stands on",
         pr_bag_recover_plan([10, 11, 30], [10, 30, 99], []) === ['remove' => [10, 30], 'kept' => []]
         && pr_bag_recover_plan([10, 11, 30], [10, 30, 99], [30]) === ['remove' => [10], 'kept' => [30]]
         && pr_bag_recover_plan([], [10], [])['remove'] === [] && pr_bag_recover_plan([10], [], [])['remove'] === []
         && pr_bag_recover_plan([10], [10, 99], [])['remove'] === [10]);       # 99 was never planned: untouched
pr_check("accept recovery: read the row after the failure: finished stays; a claim left behind is reopened; a rolled-back claim only has stray nodes taken back",
         pr_bag_accept_recovery(['state' => 'applied', 'decided_at_set' => true]) === 'completed'
         && pr_bag_accept_recovery(['state' => 'applied', 'decided_at_set' => false]) === 'reopen'
         && pr_bag_accept_recovery(['state' => 'open', 'decided_at_set' => false]) === 'clean'
         && pr_bag_accept_recovery(['state' => 'superseded', 'decided_at_set' => true]) === 'clean'
         && pr_bag_accept_recovery(null) === 'clean');

# --- the nightly decision for one cutout ---------------------------------------------------
$bag_seeds = [6 => ['product' => 7, 'shoot' => '10,20'], 5 => ['product' => 7, 'shoot' => '10,99']];
$bag_q = ['photo' => ['products' => [], 'colorways' => [], 'cutouts' => 3], 'shoot' => '10,20'];
$bag_pair_keys = ['7|3' => ['all' => 0.93, 'seed' => 0.93, 'ref' => 6, 'n' => 1], '7|4' => ['all' => 0.50, 'seed' => 0.50, 'ref' => 6, 'n' => 1]];
$d = pr_bag_decide($bag_q, [], $bag_pair_keys, $bag_seeds, null, [], $bag_cfg);
pr_check("decide: a passing pair on a photo with no product is inserted, same shoot, and writes nothing itself",
         $d['action'] === 'insert' && $d['match']['gate'] === 'pair' && $d['match']['name_node'] === 7
         && $d['match']['colorway_node'] === 3 && $d['match']['same_shoot'] === true && $d['blocked'] === ''
         && !array_key_exists('auto', $d));
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
$d = pr_bag_decide($bag_q_one, $bag_cw_keys, [], $bag_seeds, null, [], $bag_cfg);
pr_check("decide: a colorway-only match on a one-cutout photo with that product is suggested, from another shoot",
         $d['action'] === 'insert' && $d['match']['gate'] === 'colorway' && $d['match']['name_node'] === null && $d['match']['same_shoot'] === false);
$d = pr_bag_decide(['photo' => ['products' => [7], 'colorways' => [], 'cutouts' => 2]] + $bag_q_one, $bag_cw_keys, [], $bag_seeds, null, [], $bag_cfg);
pr_check("decide: a colorway-only match on a multi-cutout photo is still suggested", $d['action'] === 'insert');
$d = pr_bag_decide(['photo' => ['products' => [7], 'colorways' => [4], 'cutouts' => 1]] + $bag_q_one, $bag_cw_keys, [], $bag_seeds, null, [], $bag_cfg);
pr_check("decide: a single-cutout photo with another colorway goes to review, blocked",
         $d['action'] === 'insert' && $d['blocked'] === 'other_colorway');
$d = pr_bag_decide(['photo' => ['products' => [], 'colorways' => [], 'cutouts' => 1]] + $bag_q_one, $bag_cw_keys, [], $bag_seeds, null, [], $bag_cfg);
pr_check("decide: product unknown and not inheritable opens nothing", $d['action'] === 'none' && $d['match'] === null);
$d = pr_bag_decide(['photo' => ['products' => [7], 'colorways' => [3], 'cutouts' => 1]] + $bag_q_one, $bag_cw_keys, [], $bag_seeds, null, [], $bag_cfg);
pr_check("decide: a photo that already has this colorway is left alone, and closes a stale open row",
         $d['action'] === 'none' && pr_bag_decide(['photo' => ['products' => [7], 'colorways' => [3], 'cutouts' => 1]] + $bag_q_one,
             $bag_cw_keys, [], $bag_seeds, $open, [], $bag_cfg)['action'] === 'close');
pr_check("decide: when both gates have a row the usual preference picks it (a passing pair first)",
         pr_bag_decide($bag_q_one, $bag_cw_keys, $bag_pair_keys, $bag_seeds, null, [], $bag_cfg)['match']['gate'] === 'pair');
$d = pr_bag_decide($bag_q_one, ['3' => ['all' => 0.90, 'seed' => 0.90, 'ref' => 99, 'n' => 1]], [], $bag_seeds, null, [], $bag_cfg);
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

pr_check("import: a row missing region_index, or with a float, negative, bool or null one, is rejected before anything reads it",
         pr_bag_import_check(array_diff_key($bag_row, ['region_index' => 1]), $bag_now, 7, 3)['reason'] === 'no_region_index'
         && pr_bag_import_check(['region_index' => 0.0] + $bag_row, $bag_now, 7, 3)['reason'] === 'no_region_index'
         && pr_bag_import_check(['region_index' => -1] + $bag_row, $bag_now, 7, 3)['reason'] === 'no_region_index'
         && pr_bag_import_check(['region_index' => true] + $bag_row, $bag_now, 7, 3)['reason'] === 'no_region_index'
         && pr_bag_import_check(['region_index' => null] + $bag_row, $bag_now, 7, 3)['reason'] === 'no_region_index'
         && pr_bag_import_check(['region_index' => '0'] + $bag_row, $bag_now, 7, 3)['ok'] === true);
pr_check("import: every other key the store step reads is checked the same way (resource, name, colorway, checksum, bbox, user)",
         pr_bag_import_check(array_diff_key($bag_row, ['resource' => 1]), $bag_now, 7, 3)['reason'] === 'no_resource'
         && pr_bag_import_check(['resource' => 'x'] + $bag_row, $bag_now, 7, 3)['reason'] === 'no_resource'
         && pr_bag_import_check(['name' => ['Loma']] + $bag_row, $bag_now, 7, 3)['reason'] === 'no_product'
         && pr_bag_import_check(['colorway' => 5] + $bag_row, $bag_now, 7, 3)['reason'] === 'no_colorway'
         && pr_bag_import_check(['file_checksum' => 5] + $bag_row, $bag_now, 7, 3)['reason'] === 'checksum'
         && pr_bag_import_check(array_diff_key($bag_row, ['bbox' => 1]), $bag_now, 7, 3)['reason'] === 'bbox'
         && pr_bag_import_check(['bbox' => [.1, .1, .5]] + $bag_row, $bag_now, 7, 3)['reason'] === 'bbox'
         && pr_bag_import_check(['bbox' => [.1, .1, .5, 'x']] + $bag_row, $bag_now, 7, 3)['reason'] === 'bbox'
         && pr_bag_import_check(['bbox' => 'abc'] + $bag_row, $bag_now, 7, 3)['reason'] === 'bbox'
         && pr_bag_import_check(['user' => [1]] + $bag_row, $bag_now, 7, 3)['reason'] === 'user'
         && pr_bag_import_check(array_diff_key($bag_row, ['user' => 1]), $bag_now, 7, 3)['ok'] === true);

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

pr_check("features: a mask rewritten since (mtime differs) or gone makes the row stale, however the box reads",
         array_keys(pr_bag_current_features([$bag_feat(0, 'F1', '[0.1,0.1,0.5,0.9]') + ['mask_mtime' => 100]], $bag_feat_now, static fn(array $r): ?int => 100)) === ['5:0']
         && pr_bag_current_features([$bag_feat(0, 'F1', '[0.1,0.1,0.5,0.9]') + ['mask_mtime' => 100]], $bag_feat_now, static fn(array $r): ?int => 101) === []
         && pr_bag_current_features([$bag_feat(0, 'F1', '[0.1,0.1,0.5,0.9]') + ['mask_mtime' => 100]], $bag_feat_now, static fn(array $r): ?int => null) === []);
$bag_row_mt = $bag_feat(0, 'F1', '[0.1,0.1,0.5,0.9]') + ['mask_mtime' => 100];
$bag_split_gone = pr_bag_split_features([$bag_row_mt, $bag_feat(1, 'F1', '[0.1,0.1,0.5,0.9]') + ['mask_mtime' => 100]], $bag_feat_now, static fn(array $r): ?int => null);
pr_check("features: a mask that cannot be seen or read leaves the row unverified, never stale: kept, and not current (no seed, rival or query)",
         $bag_split_gone['stale'] === [2] && $bag_split_gone['unverified'] === [1] && $bag_split_gone['current'] === []);
$bag_split_mix = pr_bag_split_features([
    $bag_row_mt,                                                                       # mask read, same mtime: current
    ['ref' => 7] + $bag_feat(0, 'F1', '[0.1,0.1,0.5,0.9]') + ['mask_mtime' => 100],    # (same cutout twice is not a case here)
], $bag_feat_now, static fn(array $r): ?int => 100);
pr_check("features: a mask that was read with the same mtime is current",
         array_keys($bag_split_mix['current']) === ['5:0'] && $bag_split_mix['stale'] === [] && $bag_split_mix['unverified'] === []);
pr_check("features: a mask that was read with another mtime, another file or a moved box is stale (deleted), even when the mask is missing for a row that is also a leftover",
         pr_bag_split_features([$bag_row_mt], $bag_feat_now, static fn(array $r): ?int => 101)['stale'] === [1]
         && pr_bag_split_features([$bag_feat(0, 'OLD', '[0.1,0.1,0.5,0.9]') + ['mask_mtime' => 100]], $bag_feat_now, static fn(array $r): ?int => null)['stale'] === [1]
         && pr_bag_split_features([$bag_feat(3, 'F1', '[0.1,0.1,0.5,0.9]') + ['mask_mtime' => 100]], [], static fn(array $r): ?int => null)['stale'] === [4]);
pr_check("features: a whole storage outage (every mask unreadable) deletes nothing",
         (static function () use ($bag_feat, $bag_feat_now): bool {
             $rows = [];
             for ($i = 0; $i < 50; $i++) {
                 $rows[] = ['ref' => $i + 1, 'resource' => 5, 'region_index' => 0, 'file_checksum' => 'F1', 'bbox' => '[0.1,0.1,0.5,0.9]', 'mask_mtime' => 100];
             }
             $split = pr_bag_split_features($rows, $bag_feat_now, static fn(array $r): ?int => null);
             return $split['stale'] === [] && count($split['unverified']) === 50 && $split['current'] === [];
         })());
pr_check("features: no row is retried; a finished row is done; an attempt is retried until " . PR_BAG_MAX_ATTEMPTS . " tries, then left alone",
         pr_bag_feature_state(null) === 'retry'
         && pr_bag_feature_state(['status' => 'ok', 'attempts' => 0]) === 'done'
         && pr_bag_feature_state(['status' => 'unusable', 'attempts' => 0]) === 'done'
         && pr_bag_feature_state(['status' => 'attempt', 'attempts' => PR_BAG_MAX_ATTEMPTS - 1]) === 'retry'
         && pr_bag_feature_state(['status' => 'attempt', 'attempts' => PR_BAG_MAX_ATTEMPTS]) === 'given_up');

# --- the check before any write: facts read just now --------------------------------------
$bag_pm = ['name_node' => null, 'colorway_node' => 3];
$bag_ps = ['name_node' => 7, 'colorway_node' => 3];
$bag_pp = ['products' => [7], 'colorways' => [], 'cutouts' => 1];
$bag_pw = static function (array $o = []) use ($bag_pm, $bag_ps, $bag_pp): array {
    $get = static fn(string $k, $default) => array_key_exists($k, $o) ? $o[$k] : $default;
    return pr_bag_pre_write_verdict($get('bound', 0), $get('match', $bag_pm), $get('seed', $bag_ps), $get('own', false),
                                    $get('rej', false), $get('answered', false), $get('usable', true), $get('photo', $bag_pp), $get('name_ok', true), $get('cw_ok', true));
};
pr_check("pre-write: everything as it was is ok, with the plan",
         $bag_pw()['status'] === 'ok' && $bag_pw()['plan']['add_colorway'] === true);
pr_check("pre-write: a cutout no longer on the photo, a seed that is gone or re-answered (either value), is gone",
         $bag_pw(['bound' => null])['reason'] === 'cutout' && $bag_pw(['seed' => null])['reason'] === 'seed_gone'
         && $bag_pw(['seed' => ['colorway_node' => 4] + $bag_ps])['reason'] === 'seed_changed'
         && $bag_pw(['match' => ['name_node' => 7, 'colorway_node' => 3], 'seed' => ['name_node' => 8] + $bag_ps])['reason'] === 'seed_changed'
         && $bag_pw(['seed' => ['name_node' => 8] + $bag_ps])['status'] === 'blocked');      # a colorway-only match only needs the photo to have the seed's product
pr_check("pre-write: a cutout with an answer of its own, or the colorway rejected for it, is gone",
         $bag_pw(['own' => true])['reason'] === 'own_seed' && $bag_pw(['rej' => true])['reason'] === 'rejected'
         && $bag_pw(['own' => true])['status'] === 'gone');
pr_check("pre-write: a seed that is no longer usable (re-cut away, features failed, node gone) is gone",
         $bag_pw(['usable' => false])['status'] === 'gone' && $bag_pw(['usable' => false])['reason'] === 'seed_unusable');
pr_check("pre-write: a cutout another match was already accepted for is gone, so a second Accept or a new suggestion for it is closed",
         $bag_pw(['answered' => true])['status'] === 'gone' && $bag_pw(['answered' => true])['reason'] === 'answered'
         && $bag_pw(['answered' => true])['plan'] === null && $bag_pw(['bound' => null, 'answered' => true])['reason'] === 'cutout');
pr_check("pre-write: the photo's tags as they are now decide the plan; a Product that is no longer a leaf fails; a deleted Colorway blocks",
         $bag_pw(['photo' => ['products' => [7], 'colorways' => [4], 'cutouts' => 1]])['reason'] === 'other_colorway'
         && $bag_pw(['photo' => ['products' => [9], 'colorways' => [], 'cutouts' => 1]])['reason'] === 'product_changed'
         && $bag_pw(['photo' => null])['status'] === 'failed'
         && $bag_pw(['name_ok' => false])['status'] === 'failed'
         && $bag_pw(['cw_ok' => false])['status'] === 'blocked');

# --- the report: unmeasured hidden queries are not safe, and a minimum must be measurable ---
$bag_win = ['key' => '3', 'similarity' => 0.95, 'margin' => 0.2, 'ref' => 1];
pr_check("report outcome: in hidden mode a query with no winner or no margin is unmeasured, not 'none'",
         pr_bag_report_outcome('colorway', null, '3', $bag_cfg, true) === 'unmeasured'
         && pr_bag_report_outcome('colorway', ['margin' => null] + $bag_win, '3', $bag_cfg, true) === 'unmeasured'
         && pr_bag_report_outcome('colorway', ['margin' => null] + $bag_win, '3', $bag_cfg, false) === 'none'
         && pr_bag_report_outcome('colorway', ['margin' => 0.05] + $bag_win, '3', $bag_cfg, true) === 'none'
         && pr_bag_report_outcome('colorway', $bag_win, '4', $bag_cfg, true) === 'wrong');
$bag_few = ['loo:colorway' => ['right' => 49, 'wrong' => 0, 'none' => 9], 'loo:pair' => ['right' => 60, 'wrong' => 1, 'none' => 2],
            'hidden:colorway' => ['right' => 0, 'wrong' => 0, 'none' => 49, 'unmeasured' => 900], 'hidden:pair' => ['right' => 0, 'wrong' => 0, 'none' => 63]];
$bag_few_v = pr_bag_report_verdict($bag_few);
pr_check("verdict: 49 measurable hidden queries is not enough however many had no margin; 50 is, and the minimum is configurable",
         $bag_few_v['ok'] === false && count($bag_few_v['reasons']) === 1 && str_contains($bag_few_v['reasons'][0], '900 had no margin')
         && pr_bag_report_verdict(['hidden:colorway' => ['none' => 50] + $bag_few['hidden:colorway']] + $bag_few)['ok'] === true
         && pr_bag_report_verdict($bag_few, 49)['ok'] === true
         && pr_bag_config(['product_recognition_bag_report_min_hidden' => 12])['report_min_hidden'] === 12
         && pr_bag_config([])['report_min_hidden'] === 50);

# --- a competitor with no product rivals other colorways only ------------------------------
$bag_pp_keys = [
    '7|1' => ['all' => 0.95, 'seed' => 0.95, 'ref' => 5, 'n' => 1],
    '0|1' => ['all' => 0.99, 'seed' => null, 'ref' => 0, 'n' => 1],       # same colorway, product unknown: ignored
    '0|2' => ['all' => 0.80, 'seed' => null, 'ref' => 0, 'n' => 1],       # another colorway: a rival
    '8|1' => ['all' => 0.70, 'seed' => null, 'ref' => 0, 'n' => 1],       # same colorway, another product: a rival pair key
];
pr_check("pool result: a productless competitor of the winner's own colorway is ignored, one of another colorway rivals it, a known other product still does",
         abs(pr_bag_pool_result($bag_pp_keys)['margin'] - 0.15) < 1e-12
         && pr_bag_pool_result(['7|1' => $bag_pp_keys['7|1'], '0|1' => $bag_pp_keys['0|1']])['margin'] === null
         && abs(pr_bag_pool_result(['7|1' => $bag_pp_keys['7|1'], '8|1' => $bag_pp_keys['8|1']])['margin'] - 0.25) < 1e-12);

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
         && preg_match('/function pr_bag_accept_match\([^)]*\): string\s*\{\s*if \(!pr_bag_tables_exist\(\)\)/', $bag_match_src) === 1
         && preg_match('/function pr_bag_reject_match\([^)]*\): string\s*\{\s*if \(!pr_bag_tables_exist\(\)\)/', $bag_match_src) === 1);

# --- the DB layer is wired to the pure checks (source checks: the SQL itself needs a database) ---
$bag_fn = static function (string $name) use ($bag_match_src): string {
    $at = (int)strpos($bag_match_src, "function $name(");
    $end = strpos($bag_match_src, "\n}\n", $at);
    return substr($bag_match_src, $at, ($end === false ? strlen($bag_match_src) : $end) - $at);
};
$bag_accept = $bag_fn('pr_bag_accept_match');
pr_check("wiring: Accept checks edit access, then in one transaction locks the photo first, checks (locking), claims with the planned nodes, and only then adds nodes",
         str_contains($bag_accept, 'pr_can_edit_resource($resource)')
         && preg_match('/db_begin_transaction.*?pr_bag_lock_photo\(\$resource\).*?pr_bag_pre_write\(\$m, true\).*?SET state = .applied., decided_by = \?, decided_at = NULL, name_written = \?, colorway_written = \?.*?add_resource_nodes.*?db_end_transaction/s', $bag_accept) === 1
         && !str_contains($bag_accept, 'pr_bag_gate_passes') && !str_contains($bag_accept, 'manual'));
pr_check("wiring: Accept turns use_error_exception on and restores it, and every failure (throw, or a last step that matches no row) goes to pr_bag_accept_recover",
         preg_match('/use_error_exception.*?= true;.*?catch \(Throwable \$e\).*?pr_bag_accept_recover.*?finally.*?use_error_exception/s', $bag_accept) === 1
         && preg_match('/SET decided_at = NOW\(\)\s*WHERE ref = \? AND state = .applied. AND decided_by = \? AND decided_at IS NULL.*?sql_affected_rows\(\) !== 1\)\s*\{\s*\$in_flight = false;\s*return pr_bag_accept_recover/s', $bag_accept) === 1);
$bag_recover = $bag_fn('pr_bag_accept_recover');
pr_check("wiring: recovery re-reads the row after the rollback, never removes what another accepted match wrote or stands on, and only reopens a claim with no decision time",
         preg_match('/db_rollback_transaction.*?SELECT state, decided_at, decided_by FROM product_bag_match.*?pr_bag_accept_recovery\(/s', $bag_recover) === 1
         && str_contains($bag_recover, "state = 'applied' AND ref <> ?") && str_contains($bag_recover, 'pr_bag_recover_plan(')
         && str_contains($bag_recover, "SET state = 'open'") && str_contains($bag_recover, 'decided_at IS NULL'));
pr_check("wiring: recovery reopens the row before it logs, every step is its own guarded step, and a failed log cannot undo or skip the reopen",
         strpos($bag_recover, "SET state = 'open'") !== false && strpos($bag_recover, 'resource_log(') !== false
         && strpos($bag_recover, "SET state = 'open'") < strpos($bag_recover, 'resource_log(')
         && strpos($bag_recover, 'delete_resource_nodes(') < strpos($bag_recover, "SET state = 'open'")
         && str_contains($bag_recover, '$step = static function') && substr_count($bag_recover, '$step(') >= 6
         && preg_match('/\$step = static function.*?use_error_exception.*?catch \(Throwable \$e\).*?pr_bag_log/s', $bag_recover) === 1);
pr_check("accept: a cutout whose mask cannot be verified is refused as 'failed' (stays open), not closed as gone",
         pr_bag_pre_write_verdict(0, $bag_pm, $bag_ps, false, false, false, true, $bag_pp, true, true, false)['reason'] === 'cutout_unverified'
         && pr_bag_pre_write_verdict(0, $bag_pm, $bag_ps, false, false, false, true, $bag_pp, true, true, false)['status'] === 'failed'
         && pr_bag_pre_write_verdict(0, $bag_pm, $bag_ps, false, false, false, true, $bag_pp, true, true, true)['status'] === 'ok'
         && str_contains($bag_fn('pr_bag_pre_write'), "'pr_bag_mask_mtime'"));
$bag_drop = $bag_fn('pr_bag_drop_stale_features');
pr_check("wiring: nothing is deleted for a missing or unreadable mask: the drop takes only the stale refs, no folder guard stands in for the proof, and the unverified rows are counted and printed",
         !str_contains($bag_drop, 'is_dir') && !str_contains($bag_drop, 'pr_mask_dir') && str_contains($bag_drop, 'DELETE FROM product_cutout_feature')
         && substr_count($bag_match_src, "pr_bag_drop_stale_features(\$split['stale'])") === 3
         && !preg_match('/pr_bag_drop_stale_features\(\$(?!split\[.stale.\])/', $bag_match_src)
         && str_contains($bag_fn('pr_bag_drop_stale_pass'), "count(\$split['unverified'])")
         && str_contains($bag_fn('pr_bag_run_match'), "pr_bag_drop_stale_pass(\$stats['unverified_features'])")
         && str_contains(file_get_contents(__DIR__ . '/product_recognition_cli.php'), 'unverified_features')
         && str_contains($bag_fn('pr_bag_backfill_features'), "\$stats['unverified'] += count(\$split['unverified'])"));
pr_check("wiring: a cutout whose mask cannot be seen is skipped without a try, and Accept still refuses it (its check reads the feature with the mask mtime)",
         preg_match('/pr_bag_mask_stat\(\$mask\) === null.*?skipped_no_mask.*?continue;.*?pr_bag_store_attempt\(/s', $bag_fn('pr_bag_backfill_features')) === 1
         && str_contains($bag_fn('pr_bag_seed_reason_now'), "'pr_bag_mask_mtime'"));
pr_check("wiring: U2 an unreadable mask header is null (no row, the try given back, counted), never 'unusable'; Save as seed fails rather than guessing",
         str_contains($bag_fn('pr_bag_png_alpha'), 'return null') && str_contains($bag_fn('pr_bag_compute_features'), "'unreadable'")
         && preg_match("/'unreadable'.*?pr_bag_give_back_attempts\\(\\[\\\$tried\\]\\).*?unverified'\\]\\+\\+.*?continue;/s", $bag_fn('pr_bag_backfill_features')) === 1
         && preg_match('/pr_bag_png_alpha\\(\\$mask\\);\\s*if \\(\\$alpha === null\\) \\{\\s*return .failed./', $bag_fn('pr_bag_save_tag')) === 1
         && !str_contains($bag_match_src, 'pr_bag_png_has_alpha'));
pr_check("wiring: U3 the final row is read back inside the transaction before commit, for a seed and for an opened match; a missing row rolls back and reports failure",
         preg_match('/pr_bag_store_tag_locked\\(.*?pr_bag_require_row\\(.*?catch \\(PrBagRowMissing.*?db_rollback_transaction.*?return false;.*?db_end_transaction/s', $bag_fn('pr_bag_store_tag')) === 1
         && preg_match('/INSERT INTO product_bag_match.*?pr_bag_require_row\\(.*?catch \\(PrBagRowMissing.*?db_rollback_transaction.*?failed_writes.*?return;.*?db_end_transaction/s', $bag_fn('pr_bag_enact')) === 1
         && str_contains($bag_fn('pr_bag_require_row'), 'throw new PrBagRowMissing'));
pr_check("wiring: a rolled-back claim ('clean') takes back the planned nodes through the same protect set as a reopen, and only a reopen touches the row",
         !str_contains($bag_recover, "=== 'clean'") && preg_match("/pr_bag_recover_plan\\(.*?delete_resource_nodes\\(.*?\\\$action === 'reopen' && \\\$removed.*?SET state = 'open'/s", $bag_recover) === 1
         && substr_count($bag_recover, "SET state = 'open'") === 1);
pr_check("recovery plan: a clean (rolled-back) accept removes its planned nodes that are on the photo, never one another applied match stands on or one it did not plan",
         pr_bag_accept_recovery(['state' => 'open', 'decided_at_set' => false]) === 'clean'
         && pr_bag_accept_recovery(null) === 'clean'
         && pr_bag_recover_plan([278, 282], [278, 282, 5], [])['remove'] === [278, 282]
         && pr_bag_recover_plan([278, 282], [278, 282], [282])['remove'] === [278]
         && pr_bag_recover_plan([278, 282], [5], [])['remove'] === []);
pr_check("wiring: U5 after the commit, and only when a Name was added, Accept fills identity (add on several cutouts, else keep); a failure is logged and flagged, not undone",
         preg_match("/db_end_transaction\\('pr_bag_accept'\\).*?\\\$name_missing !== \\[\\].*?try \\{\\s*pr_write_accept_identity\\(.*?> 1 \\? 'add' : 'keep'\\).*?catch \\(Throwable.*?identity_failed = true;.*?return 'applied';/s", $bag_fn('pr_bag_accept_match')) === 1
         && str_contains($bag_src('pages/review.php'), 'bag_applied_identity') && str_contains($bag_src('install.txt'), 'identity fields'));
pr_check("wiring: U6 the review tab's cutout and seed image URLs carry the mask mtime",
         str_contains($bag_fn('pr_bag_render_review'), "'m' => (int)pr_bag_mask_stat("));
pr_check("wiring: U8 pr_bag_log uses logScript only on the command line, error_log on the web",
         str_contains($bag_fn('pr_bag_log'), "PHP_SAPI === 'cli'") && !str_contains($bag_match_src, 'logScript("'));
pr_check("wiring: U9 missing candidates are counted in the match pass and the report, and the report gives no verdict while any are missing",
         str_contains($bag_fn('pr_bag_run_match'), "missing_candidates") && str_contains($bag_fn('pr_bag_report'), "\$missing > 0")
         && str_contains($bag_fn('pr_bag_report'), "['ok'] = false"));
pr_check("wiring: Accept refuses (stays open) when the seed's own mask cannot be read",
         str_contains($bag_fn('pr_bag_pre_write'), 'pr_bag_seed_mask_readable(') && str_contains($bag_fn('pr_bag_seed_mask_readable'), 'pr_bag_png_alpha(')
         && pr_bag_pre_write_verdict(0, $bag_pm, $bag_ps, false, false, false, true, $bag_pp, true, true, true, false)['reason'] === 'seed_mask_unverified');
pr_check("wiring: U1 on a multi-cutout photo the visible picker starts from this cutout's seed or detection, and the seed form posts what it shows",
         preg_match('/\\$region_count > 1 && \\$bag_live.*?\\$bag_seed_here.*?\\$shown_model/s', $bag_src('hooks/view.php')) === 1
         && str_contains($bag_src('hooks/view.php'), "\$bag_pre = \$pre;") && !str_contains($bag_src('hooks/view.php'), '$bag_pre = $bag_here'));
pr_check("wiring: S1 the feature and match passes arm use_error_exception at the start, again after get_sysvar, and per page/cutout, so a SQL error throws into the finally that saves the cursor",
         str_contains($bag_fn('pr_bag_arm_exceptions'), "\$GLOBALS['use_error_exception'] = true")
         && preg_match("/pr_bag_arm_exceptions\\(\\);.*?get_sysvar\\('product_recognition_bag_feature_position'.*?pr_bag_arm_exceptions\\(\\);.*?foreach \\(\\\$rows as \\\$row\\) \\{\\s*pr_bag_arm_exceptions\\(\\);/s", $bag_fn('pr_bag_backfill_features')) === 1
         && preg_match('/finally \\{.*?set_sysvar\\(.*?pr_bag_arm_exceptions/s', $bag_fn('pr_bag_backfill_features')) === 1
         && substr_count($bag_fn('pr_bag_run_match'), 'pr_bag_arm_exceptions()') >= 4);
$bag_funcs_src = $bag_src('include/product_recognition_functions.php');
pr_check("wiring: S1 the process lock is also freed by a shutdown function when the run ends past its finally",
         preg_match('/function pr_with_lock.*?\\$held = true;.*?register_shutdown_function\\(.*?clear_process_lock\\(.*?finally \\{\\s*\\$held = false;\\s*clear_process_lock/s', $bag_funcs_src) === 1);
# Behavior of the alpha check on real files (the two functions are plain PHP, so they are run from their source).
if (!function_exists('pr_bag_png_alpha')) {
    eval($bag_fn('pr_bag_quiet') . "\n}\n" . $bag_fn('pr_bag_png_alpha') . "\n}\n");
}
$bag_png_dir = sys_get_temp_dir() . '/prbagpng' . getmypid();
mkdir($bag_png_dir);
$bag_png = static fn(string $name, int $color_type): string => (static function () use ($bag_png_dir, $name, $color_type): string {
    $path = $bag_png_dir . '/' . $name;
    file_put_contents($path, "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 8, 8) . chr(8) . chr($color_type) . str_repeat("\0", 20));
    return $path;
})();
pr_check("png alpha: a header read with alpha is true, without is false, a file that is not there or too short is null (not 'unusable')",
         pr_bag_png_alpha($bag_png('a.png', 6)) === true && pr_bag_png_alpha($bag_png('b.png', 2)) === false
         && pr_bag_png_alpha($bag_png_dir . '/missing.png') === null
         && (file_put_contents($bag_png_dir . '/short.png', 'PNG') !== false && pr_bag_png_alpha($bag_png_dir . '/short.png') === null));
$bag_unreadable = $bag_png('c.png', 6);
chmod($bag_unreadable, 0000);
pr_check("png alpha: a mask that exists but cannot be read is null, so it is never stored as unusable",
         posix_geteuid() === 0 || pr_bag_png_alpha($bag_unreadable) === null);
chmod($bag_unreadable, 0644);
array_map('unlink', glob($bag_png_dir . '/*') ?: []);
rmdir($bag_png_dir);
pr_check("wiring: review tab 1 the import runs Save as seed's alpha check (unreadable is its own reason), uses store_tag's result, and counts accepted only after it",
         preg_match("/pr_bag_png_alpha\\(.*?'mask_unreadable'.*?need_cutout.*?!pr_bag_store_tag\\(.*?store_failed.*?\\\$stats\\['accepted'\\]\\+\\+;/s", $bag_fn('pr_bag_import_tags')) === 1);
pr_check("wiring: the review tab hides the seed photo's link and caption when its photo cannot be opened",
         str_contains($bag_fn('pr_bag_open_matches'), "get_resource_access(\$seed_res)") && str_contains($bag_fn('pr_bag_open_matches'), "'seed_visible'")
         && preg_match("/if \\(\\\$r\\['seed_visible'\\]\\).*?pages\\/view.php\\?ref=' \\. \\(int\\)\\\$r\\['seed_resource'\\].*?bag_seed_caption/s", $bag_fn('pr_bag_render_review')) === 1);
pr_check("wiring: the review tab uses the same live predicate as everything else, not archive = 0",
         str_contains($bag_fn('pr_bag_open_matches'), "pr_bag_live_sql('r')") && !str_contains($bag_fn('pr_bag_open_matches'), 'archive = 0'));
pr_check("wiring: /match_bags callers send the candidate list once per run (key afterwards), and resend when the service does not know the key",
         str_contains($bag_fn('pr_bag_match_request'), "unknown_candidates") && str_contains($bag_fn('pr_bag_match_request'), 'candidates_key')
         && str_contains($bag_fn('pr_bag_run_match'), 'pr_bag_match_request(') && str_contains($bag_fn('pr_bag_report'), 'pr_bag_match_request(')
         && substr_count($bag_match_src, "pr_bag_post_json('/match_bags'") === 2);
pr_check("wiring: half-applied accepts are counted (never repaired) in the match pass and in --bag-match-report",
         str_contains($bag_fn('pr_bag_half_applied'), "state = 'applied' AND decided_at IS NULL")
         && str_contains($bag_fn('pr_bag_run_match'), 'pr_bag_half_applied()') && str_contains($bag_fn('pr_bag_report'), 'pr_bag_half_applied()')
         && !str_contains($bag_fn('pr_bag_half_applied'), 'UPDATE') && !str_contains($bag_fn('pr_bag_half_applied'), 'delete_resource_nodes'));
pr_check("wiring: a rejected row that someone else already decided is 'gone', not 'rejected'",
         preg_match('/state = .rejected.*?return sql_affected_rows\(\) === 1 \? .rejected. : .gone./s', $bag_fn('pr_bag_reject_match')) === 1);
pr_check("wiring: opening a suggestion takes the photo's lock, checks pr_bag_pre_write locking, then inserts, in one transaction",
         preg_match('/db_begin_transaction.*?pr_bag_lock_photo.*?pr_bag_pre_write\(\$row, true\).*?INSERT INTO product_bag_match.*?db_end_transaction/s', $bag_fn('pr_bag_enact')) === 1
         && str_contains($bag_fn('pr_bag_pre_write'), "state = 'applied' AND ref <> ?"));
pr_check("wiring: the end of a run closes open rows for cutouts accepted meanwhile, read again at that point",
         preg_match('/pr_bag_answered_cutouts\(\).*?isset\(\$answered\[\$cut_key\]\)/s', $bag_fn('pr_bag_run_match')) === 1);
pr_check("wiring: nothing but a person's Accept writes tags: no auto-apply settings, no undo, one add_resource_nodes caller, no seed save in apply.php",
         substr_count($bag_src('include/product_recognition_bag_match.php'), 'add_resource_nodes(') === 2
         && !str_contains($bag_src('include/product_recognition_bag_match.php'), 'pr_bag_undo')
         && !str_contains($bag_src('include/product_recognition_bag_match.php'), 'pr_bag_apply_match')
         && !str_contains($bag_src('config/config.php'), 'bag_colorway_auto_apply') && !str_contains($bag_src('config/config.php'), 'bag_product_auto_apply')
         && !str_contains($bag_src('scripts/product_recognition_cli.php'), 'undo-bag-matches')
         && !str_contains($bag_src('pages/apply.php'), 'pr_bag_save_tag'));
pr_check("wiring: the nightly bag stage turns on use_error_exception and restores it, so a SQL error cannot end the CLI run",
         preg_match('/use_error_exception.*?= true;.*?pr_bag_backfill_features.*?pr_bag_run_match.*?finally/s', $bag_fn('pr_bag_nightly')) === 1);
pr_check("wiring: an open row is checked with pr_bag_pre_write before it is inserted",
         preg_match('/pr_bag_pre_write\(\$row\).*?INSERT INTO product_bag_match/s', $bag_fn('pr_bag_enact')) === 1);
pr_check("wiring: matching and the report check /health first; both build candidates with pr_bag_build_candidates and load competitors",
         preg_match('/pr_bag_enabled\(\)\)\s*\{.*?pr_bag_drop_stale_pass\(.*?if \(!pr_service_health\(\)\)/s', $bag_fn('pr_bag_run_match')) === 1
         && str_contains($bag_fn('pr_bag_run_match'), 'pr_bag_build_candidates(') && str_contains($bag_fn('pr_bag_report'), 'pr_bag_build_candidates(')
         && str_contains($bag_fn('pr_bag_report'), 'pr_bag_load_competitors(') && str_contains($bag_fn('pr_bag_report'), 'pr_service_health()')
         && !str_contains($bag_fn('pr_bag_report'), 'hide_product'));
pr_check("wiring: an open row whose seed is only waiting for its features is not closed",
         str_contains($bag_fn('pr_bag_run_match'), "'close' && isset(\$pending_seeds["));
pr_check("wiring: the feature pass records an attempt before posting, stores only if the mask mtime did not move (stat cache cleared before both reads), skips .fail masks, and has no sysvar or rewind",
         preg_match('/\$mtime = pr_bag_mask_stat\(\$mask\);.*?pr_bag_store_attempt\(.*?pr_bag_compute_features.*?pr_bag_mask_stat\(\$mask\) !== \$mtime.*?pr_bag_store_features/s', $bag_fn('pr_bag_backfill_features')) === 1
         && str_contains($bag_fn('pr_bag_backfill_features'), "'.fail'") && !str_contains($bag_match_src, 'feature_suspect'));
$bag_view_src = $bag_src('include/product_recognition_view.php');
pr_check("wiring: a region commit clears the cutout features before and after it writes the masks",
         preg_match('/function pr_commit_regions.*?pr_bag_forget_cutouts\(\$ref\);.*?pr_write_mask_bytes.*?pr_bag_forget_cutouts\(\$ref\);\s*\}/s', $bag_view_src) === 1);
$bag_feat = $bag_fn('pr_bag_backfill_features');
pr_check("wiring: the feature pass checks the service before its first attempt, scans every resource with regions (no prefilter on stored rows), and has no streak",
         preg_match('/pr_bag_service_up\(\)\)\s*\{\s*\$stats\[.service_down.\] = true;\s*return.*?pr_bag_store_attempt\(/s', $bag_feat) === 1
         && !str_contains($bag_feat, 'streak') && !str_contains($bag_feat, 'COUNT(*) FROM product_cutout_feature')
         && str_contains($bag_feat, 'pr_bag_count_given_up()') && !str_contains($bag_feat, "\$stats['given_up']++"));
pr_check("wiring: a failing cutout is judged by pr_bag_failure_plan, probing the service only for an error answer; the try is given back only when the plan says so",
         preg_match('/pr_bag_failure_plan\(\$no_answer, \$back, \$no_answer \|\| pr_bag_probe_service\(\)\).*?keep_try.*?pr_bag_give_back_attempts\(\[\$tried\]\)/s', $bag_feat) === 1
         && substr_count($bag_feat, 'pr_bag_give_back_attempts(') === 2 && str_contains($bag_fn('pr_bag_probe_service'), 'pr_bag_probe_png()'));
pr_check("wiring: the match pass drops stale feature rows before it looks for seeds, so a night with no seeds still does",
         preg_match('/pr_bag_drop_stale_pass\(.*?pr_bag_load_seeds\(/s', $bag_fn('pr_bag_run_match')) === 1);
pr_check("wiring: every writing CLI mode creates the tables and checks the UNIQUE keys first; seeds, queries and competitors skip resources in the deletion state",
         substr_count($bag_match_src, 'pr_bag_prepare_tables();') === 3 && str_contains($bag_fn('pr_bag_ensure_unique_keys'), 'ADD UNIQUE KEY')
         && !str_contains($bag_fn('pr_bag_ensure_unique_keys'), 'DELETE')
         && str_contains($bag_fn('pr_bag_current_cutouts'), 'pr_bag_live_sql') && str_contains($bag_fn('pr_bag_load_competitors'), 'pr_bag_live_sql')
         && str_contains($bag_fn('pr_bag_load_cutout_page'), 'pr_bag_live_sql') && str_contains($bag_feat, 'pr_bag_live_sql'));
pr_check("wiring: the panel and the review tab compute blocked with the same function, the panel hides Accept on a blocked match, and the tab cuts its page after blocked is known",
         str_contains($bag_fn('pr_bag_panel_state'), 'pr_bag_match_blocked(') && str_contains($bag_fn('pr_bag_open_matches'), 'pr_bag_match_blocked(')
         && preg_match('/pr_bag_match_blocked\(.*?foreach \(array_merge\(\$good, \$blocked\) as \$r\).*?get_resource_access\(.*?\$more = true;/s', $bag_fn('pr_bag_open_matches')) === 1
         && substr_count($bag_fn('pr_bag_open_matches'), 'get_resource_access(') === 2
         && strpos($bag_fn('pr_bag_open_matches'), 'get_resource_access(') > strpos($bag_fn('pr_bag_open_matches'), 'pr_bag_match_blocked(')
         && str_contains($bag_src('hooks/view.php'), "\$bag_action === 'bag_accept' && \$bag_here['match']['blocked'] !== ''"));
pr_check("wiring: the not-ready message names the CLI modes",
         preg_match('/product_recognition_bag_no_tables"\] = "[^"]*--cutout-features or --all/', $bag_src('languages/en.php')) === 1);

pr_check("wiring: Accept sets the flag again before every write and has a shutdown handler that recovers an in-flight claim, disarmed on return",
         substr_count($bag_accept, '$arm();') >= 10 && str_contains($bag_accept, 'register_shutdown_function(')
         && preg_match('/if \(\$in_flight\) \{\s*\$in_flight = false;.*?pr_bag_accept_recover\(/s', $bag_accept) === 1
         && substr_count($bag_accept, '$in_flight = false;') >= 6 && str_contains($bag_accept, "unset(\$GLOBALS['use_error_exception'])"));
pr_check("wiring: the nightly bag stage sets the flag before each stage, and pr_run_all keeps last_run and the earlier summary for a bag stage that still exits",
         substr_count($bag_fn('pr_bag_nightly'), "\$GLOBALS['use_error_exception'] = true;") >= 3
         && preg_match('/set_sysvar\(.product_recognition_last_run.*?register_shutdown_function.*?pr_bag_nightly\(\)/s', $bag_src('include/product_recognition_functions.php')) === 1);
pr_check("wiring: file reads on the paths this change adds go through pr_bag_quiet or pr_bag_mask_stat, with no @ and no bare filemtime",
         !preg_match('/@(filemtime|file_get_contents|unlink|filesize|fopen)/', $bag_match_src)
         && substr_count(preg_replace('/function pr_bag_mask_stat.*?\n}\n/s', '', $bag_match_src), 'filemtime(') === 0
         && str_contains($bag_fn('pr_bag_mask_stat'), 'clearstatcache(true, $mask)') && str_contains($bag_fn('pr_bag_compute_features'), 'pr_bag_quiet(')
         && !str_contains($bag_fn('pr_bag_compute_features'), 'pr_png_has_alpha(') && !str_contains($bag_fn('pr_bag_save_tag'), 'pr_png_has_alpha(')
         && preg_match('/try \{.*?while \(\$budget > 0\).*?\} finally \{.*?set_sysvar\(.product_recognition_bag_feature_position/s', $bag_feat) === 1
         && substr_count($bag_feat, 'pr_bag_mask_stat(') >= 3);
pr_check("wiring: saving or importing a seed takes the photo's lock as the first statement of its transaction",
         preg_match('/db_begin_transaction\(.pr_bag_seed.\);\s*try \{\s*pr_bag_lock_photo\(\$resource\);\s*\$saved = pr_bag_store_tag_locked/', $bag_fn('pr_bag_store_tag')) === 1
         && str_contains($bag_fn('pr_bag_import_tags'), 'pr_bag_store_tag(') && str_contains($bag_fn('pr_bag_import_tags'), 'pr_bag_import_row_shape($row)'));
pr_check("wiring: the match pass closes open rows whose seed is gone or ignored for any reason but features_pending; the pre-write check binds the seed",
         str_contains($bag_fn('pr_bag_run_match'), "!isset(\$seeds[(int)\$r['seed_ref']]) && !isset(\$pending_seeds[(int)\$r['seed_ref']])")
         && str_contains($bag_fn('pr_bag_pre_write'), 'pr_bag_seed_reason_now(') && str_contains($bag_fn('pr_bag_load_seeds'), 'pr_bag_seed_reason('));
pr_check("wiring: the bags tab loads the leaf lists and access once, leaves pr_open_suggestions out, and keeps the page through Accept and Reject",
         str_contains($bag_fn('pr_bag_open_matches'), '$product_leaves = pr_leaf_models()') && str_contains($bag_fn('pr_bag_open_matches'), '$access[$res] ??=')
         && str_contains($bag_src('pages/review.php'), "\$tab === 'bags' ? [] : pr_open_suggestions(100)")
         && str_contains($bag_src('pages/review.php'), "review_params['bagpage']") && str_contains($bag_match_src, 'name="bagpage"'));
pr_check("wiring: the panel hides the seed form and buttons on a photo that is not live, and a multi-cutout photo's seed form does not use the photo-level colorway",
         str_contains($bag_src('hooks/view.php'), '$bag_live = $bag_cuts !== null') && str_contains($bag_src('hooks/view.php'), "empty(\$row['fallback']) && \$bag_live")
         && preg_match('/\$region_count > 1 && \$bag_live.*?pr_picker_preselect\(\(string\)\$row\[.model_name.\], \(string\)\$row\[.colorway.\], \$catalog\)/s', $bag_src('hooks/view.php')) === 1
         && preg_match('/name="model_text"\s+id="<\?php echo escape\(\$picker_id\); \?>-bag-model"\s+value="<\?php echo escape\(\$bag_pre\[/', $bag_src('hooks/view.php')) === 1);

# --- forms: the AJAX post never sends the clicked button -----------------------------------
$bag_view = $bag_src('hooks/view.php');
pr_check("forms: the panel's bag Accept and Reject carry the action in a hidden field, not in a submit button's name",
         !preg_match('/<button[^>]*name="action"[^>]*value="bag_/', $bag_view)
         && str_contains($bag_view, "'bag_accept' => 'product_recognition_accept'")
         && preg_match('/<input type="hidden" name="action" value="<\?php echo escape\(\$bag_action\)/', $bag_view) === 1);
pr_check("forms: the panel's Apply form does not carry a seed (only the Save as seed bag form carries the file checksum and box)",
         preg_match('/pr_catalog_apply.*?name="model_text"/s', $bag_view, $bag_apply_form) === 1
         && !str_contains($bag_apply_form[0], 'name="file_checksum"') && !str_contains($bag_apply_form[0], 'name="bbox"')
         && preg_match('/pr_bag_tag.*?name="file_checksum".*?name="bbox"/s', $bag_view) === 1);
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
# keys built at run time: product_recognition_bag_blocked_<reason>, product_recognition_bag_gate_<gate>
foreach (['colorway', 'pair'] as $bag_gate) {
    if (!isset($bag_lang['product_recognition_bag_gate_' . $bag_gate])) {
        $bag_missing[] = 'product_recognition_bag_gate_' . $bag_gate;
    }
}
foreach (['other_name', 'other_colorway', 'no_product', 'product_changed', 'product_gone', 'colorway_gone', 'no_colorway'] as $bag_reason) {
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
