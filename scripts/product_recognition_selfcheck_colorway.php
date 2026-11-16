<?php
/**
 * Colorway accuracy report assertions (issue 18). Pure functions only, no database.
 * Loaded from product_recognition_selfcheck.php. Do not run this file on its own.
 */

pr_check("colorway outcome: folded match is correct, blank is blank, other is wrong",
         pr_colorway_report_outcome('Midnight Green', 'midnight-green') === 'correct'
         && pr_colorway_report_outcome('Navy', '') === 'blank'
         && pr_colorway_report_outcome('Navy', 'Black') === 'wrong');

pr_check("colorway band: edges are exclusive upper bounds, 1.0 lands in the last band",
         pr_colorway_report_band(0.55, PR_COLORWAY_REPORT_SHARE_BANDS) === '<0.6'
         && pr_colorway_report_band(0.6, PR_COLORWAY_REPORT_SHARE_BANDS) === '<0.7'
         && pr_colorway_report_band(1.0, PR_COLORWAY_REPORT_SHARE_BANDS) === '<=1.0'
         && pr_colorway_report_band(0.2, PR_COLORWAY_REPORT_SIMILARITY_BANDS) === '<0.4');

$cw_neighbors = [
    ['resource' => 11, 'similarity' => 0.9],
    ['resource' => 12, 'similarity' => 0.8],
    ['resource' => 13, 'similarity' => 0.7],
    ['resource' => 14, 'similarity' => 0.6],
];
$cw_split = pr_colorway_report_split_neighbors(
    $cw_neighbors, [500 => true], [11 => [500 => true], 13 => [501 => true, 500 => true]], 2
);
pr_check("colorway split: self keeps the k nearest, folder set skips same-folder neighbours",
         array_column($cw_split['self'], 'resource') === [11, 12]
         && array_column($cw_split['folder'], 'resource') === [12, 14]
         && $cw_split['dropped'] === 2);
$cw_split = pr_colorway_report_split_neighbors($cw_neighbors, [], [11 => [500 => true]], 3);
# Query keys {Raw 4, Navy 3}; a neighbour in Navy/Selects/Final carries Navy on its path.
$cw_deep = pr_colorway_report_split_neighbors(
    $cw_neighbors, [4 => true, 3 => true], [11 => [1 => true, 2 => true, 3 => true, 6 => true, 7 => true]], 3
);
pr_check("colorway split: a neighbour deeper in the same shoot is dropped",
         array_column($cw_deep['folder'], 'resource') === [12, 13, 14]);
pr_check("colorway split: a photo with no folder drops nothing",
         array_column($cw_split['folder'], 'resource') === [11, 12, 13]
         && $cw_split['dropped'] === 0);

$cw_detail = pr_vote_colorway_detail([
    ['similarity' => 0.6, 'colorway' => 'Navy'],
    ['similarity' => 0.3, 'colorway' => 'Navy'],
    ['similarity' => 0.1, 'colorway' => ''],
]);
pr_check("colorway vote detail: winner and its share of all weight",
         $cw_detail['colorway'] === 'Navy' && abs($cw_detail['share'] - 0.9) < 1e-9);
$cw_detail = pr_vote_colorway_detail([
    ['similarity' => 0.5, 'colorway' => 'Navy'],
    ['similarity' => 0.5, 'colorway' => 'Black'],
]);
pr_check("colorway vote detail: half is not a win, and the share is still reported",
         $cw_detail['colorway'] === '' && abs($cw_detail['share'] - 0.5) < 1e-9
         && pr_vote_colorway([['similarity' => 1.0, 'colorway' => 'Navy']]) === 'Navy');

$cw_named = pr_named_service_neighbors(
    pr_colorway_report_with_colorways(
        [['model_node' => 7, 'similarity' => 0.9, 'resource' => 999999999]], []
    ),
    [7 => 'Loma']
);
pr_check("named neighbours: a resolved blank colorway is kept, not looked up again",
         count($cw_named) === 1 && $cw_named[0]['colorway'] === '');

pr_check("colorway band: 0.95 edge labels and support bands",
         pr_colorway_report_band(0.93, PR_COLORWAY_REPORT_SIMILARITY_BANDS) === '<0.95'
         && pr_colorway_report_band(0.97, PR_COLORWAY_REPORT_SIMILARITY_BANDS) === '<=1.0'
         && pr_colorway_report_support_band(1) === '1'
         && pr_colorway_report_support_band(4) === '3-4'
         && pr_colorway_report_support_band(9) === '5+');

# Photo tagged with its whole path: Root(1) > Shoot(2) > Navy(3) > Raw(4).
$cw_keys = pr_colorway_report_folder_keys([
    ['ref' => 1, 'parent' => 0], ['ref' => 2, 'parent' => 1],
    ['ref' => 3, 'parent' => 2], ['ref' => 4, 'parent' => 3],
]);
ksort($cw_keys);
pr_check("folder keys: the photo's deepest folder and its parent, not the ancestors",
         array_keys($cw_keys) === [3, 4]);
$cw_keys = pr_colorway_report_folder_keys([
    ['ref' => 2, 'parent' => 1], ['ref' => 3, 'parent' => 2], ['ref' => 5, 'parent' => 2],
]);
ksort($cw_keys);
pr_check("folder keys: two deepest folders both count; no nodes, no keys",
         array_keys($cw_keys) === [2, 3, 5]
         && pr_colorway_report_folder_keys([]) === []);

$cw_detail = pr_vote_colorway_detail([
    ['similarity' => 0.5, 'colorway' => 'Navy'],
    ['similarity' => 0.4, 'colorway' => 'Navy'],
    ['similarity' => 0.1, 'colorway' => 'Black'],
]);
pr_check("colorway vote detail: support counts neighbours carrying the winner",
         $cw_detail['colorway'] === 'Navy' && $cw_detail['support'] === 2
         && pr_vote_colorway_detail([])['support'] === 0);

$cw_row = static fn(array $over): array => array_merge([
    'truth' => 'Navy', 'a_self' => 'Navy', 'a_folder' => 'Navy', 'a_folder_share' => 0.75,
    'a_folder_support' => 3, 'b_self' => '', 'b_folder' => 'Navy', 'b_folder_share' => 0.65,
    'folder_top_similarity' => 0.65, 'dropped' => 3, 'has_folder_key' => true,
    'folder_counted' => true,
], $over);
$cw_stats = pr_colorway_report_new_stats();
pr_colorway_report_tally($cw_stats, $cw_row(['a_folder' => 'Black']));
pr_colorway_report_tally($cw_stats, $cw_row(['a_self' => null, 'a_folder' => null,
    'b_self' => 'Navy', 'b_folder' => '', 'dropped' => 0]));
pr_colorway_report_tally($cw_stats, $cw_row(['folder_top_similarity' => 0.97]));
pr_colorway_report_tally($cw_stats, $cw_row(['folder_counted' => false]));
pr_colorway_report_tally($cw_stats, $cw_row(['has_folder_key' => false, 'folder_counted' => false]));
pr_check("colorway tally: folder figures skip unkeyed and under-filled rows",
         $cw_stats['considered'] === 5
         && $cw_stats['no_model'] === 1
         && $cw_stats['no_folder_key'] === 1
         && $cw_stats['folder_underfilled'] === 1
         && $cw_stats['paths']['a_self'] === ['correct' => 4, 'wrong' => 0, 'blank' => 0]
         && $cw_stats['paths']['a_folder'] === ['correct' => 1, 'wrong' => 1, 'blank' => 0]
         && $cw_stats['paths']['b_folder'] === ['correct' => 2, 'wrong' => 0, 'blank' => 1]);
pr_check("colorway tally: near-duplicate rows leave the <.95 figure only",
         $cw_stats['paths']['a_folder_nodup'] === ['correct' => 0, 'wrong' => 1, 'blank' => 0]
         && $cw_stats['paths']['b_folder_nodup'] === ['correct' => 1, 'wrong' => 0, 'blank' => 1]);
pr_check("colorway tally: bands, per-colour and confusion from counted folder rows",
         $cw_stats['confusion'] === ["a\tNavy\tBlack" => 1]
         && $cw_stats['by_color'] === ['navy' => ['label' => 'Navy', 'wrong' => 1, 'correct' => 1]]
         && $cw_stats['bands']['a_share'] === ['<0.8' => ['wrong' => 1, 'correct' => 1]]
         && $cw_stats['bands']['a_support'] === ['3-4' => ['wrong' => 1, 'correct' => 1]]
         && $cw_stats['bands']['a_similarity'] === ['<0.7' => ['wrong' => 1], '<=1.0' => ['correct' => 1]]
         && $cw_stats['bands']['b_share'] === ['<0.7' => ['correct' => 2]]);
pr_check("colorway summary prints every path row",
         count(array_filter(pr_colorway_report_summary($cw_stats),
             static fn($l) => preg_match('/^(a name|b e2e) /', $l) === 1)) === 8);

# Tagged set: same shoot and near-copies excluded, untagged and junk-labelled skipped.
$cw_tagged = pr_colorway_report_split_neighbors([
    ['resource' => 21, 'similarity' => 0.97, 'colorway' => 'Navy', 'colorway_resolved' => true],
    ['resource' => 22, 'similarity' => 0.9, 'colorway' => '', 'colorway_resolved' => true],
    ['resource' => 23, 'similarity' => 0.8, 'colorway' => 'Raw', 'colorway_resolved' => true],
    ['resource' => 24, 'similarity' => 0.7, 'colorway' => 'Navy', 'colorway_resolved' => true],
    ['resource' => 25, 'similarity' => 0.6, 'colorway' => 'Black', 'colorway_resolved' => true],
    ['resource' => 26, 'similarity' => 0.5, 'colorway' => 'Navy', 'colorway_resolved' => true],
], [9 => true], [24 => [9 => true]], 2);
pr_check("tagged set: skips near-copies, untagged, junk labels and the same shoot",
         array_column($cw_tagged['tagged'], 'resource') === [25, 26]
         # 24 shares the shoot but comes after the folder set filled, so it is not counted.
         && $cw_tagged['dropped'] === 0);
pr_check("tagged neighbours (projection): k nearest with a countable colorway, copies kept",
         array_column(pr_colorway_report_tagged_neighbors([
             ['resource' => 21, 'similarity' => 0.97, 'colorway' => 'Navy'],
             ['resource' => 22, 'similarity' => 0.9, 'colorway' => ''],
             ['resource' => 24, 'similarity' => 0.7, 'colorway' => 'Navy'],
             ['resource' => 25, 'similarity' => 0.6, 'colorway' => 'Black'],
         ], 2), 'resource') === [21, 24]);

$cw_stats = pr_colorway_report_new_stats();
pr_colorway_report_tally($cw_stats, $cw_row(['a_tagged' => 'Navy', 'a_tagged_share' => 0.95,
    'a_tagged_support' => 6, 'b_tagged' => 'Black', 'b_tagged_share' => 0.85, 'tagged_counted' => true]));
pr_colorway_report_tally($cw_stats, $cw_row(['a_tagged' => 'Navy', 'b_tagged' => 'Navy',
    'a_tagged_share' => 0.7, 'a_tagged_support' => 2, 'b_tagged_share' => 0.7, 'tagged_counted' => false]));
pr_check("colorway tally: tagged set counted only when full, with its own bands and colours",
         $cw_stats['tagged_underfilled'] === 1
         && $cw_stats['paths']['a_tagged'] === ['correct' => 1, 'wrong' => 0, 'blank' => 0]
         && $cw_stats['paths']['b_tagged'] === ['correct' => 0, 'wrong' => 1, 'blank' => 0]
         && $cw_stats['bands']['at_share'] === ['<=1.0' => ['correct' => 1]]
         && $cw_stats['bands']['at_support'] === ['5+' => ['correct' => 1]]
         && $cw_stats['bands']['bt_share'] === ['<0.9' => ['wrong' => 1]]
         && $cw_stats['confusion_tagged'] === ["b\tNavy\tBlack" => 1]
         && $cw_stats['by_color_tagged'] === ['navy' => ['label' => 'Navy', 'correct' => 1]]);

# --- cutout report ---------------------------------------------------------------------------
pr_check("crop photo vote: regions that agree win, disagreement and silence are blank",
         pr_colorway_crop_photo_vote(['Navy', '', 'navy']) === ['colorway' => 'Navy', 'conflict' => false]
         && pr_colorway_crop_photo_vote(['Navy', 'Black']) === ['colorway' => '', 'conflict' => true]
         && pr_colorway_crop_photo_vote(['', '']) === ['colorway' => '', 'conflict' => false]);
pr_check("crop any-region match",
         pr_colorway_crop_any_match('Navy', ['Black', 'navy'])
         && !pr_colorway_crop_any_match('Navy', ['Black', '']));
$cw_crop = pr_colorway_crop_new_stats();
pr_colorway_crop_tally($cw_crop, [
    'truth' => 'Navy', 'regions' => 2,
    'match' => ['colorway' => '', 'conflict' => true], 'pixel' => ['colorway' => 'Navy', 'conflict' => false],
    'match_share' => 0.0, 'match_support' => 0, 'any_match' => true, 'any_pixel' => true,
    'named' => true, 'stored_names' => ['Loma'], 'e2e_names' => ['Loma', ''],
    'e2e_name' => ['colorway' => 'Loma', 'conflict' => false],
    'e2e' => ['colorway' => 'Navy', 'conflict' => false], 'e2e_name_share' => 0.8, 'e2e_share' => 0.95,
    'e2e_support' => 6, 'any_e2e' => true,
]);
pr_colorway_crop_tally($cw_crop, [
    'truth' => 'Navy', 'regions' => 1,
    'match' => ['colorway' => 'Black', 'conflict' => false], 'pixel' => ['colorway' => '', 'conflict' => false],
    'match_share' => 0.92, 'match_support' => 4, 'any_match' => false, 'any_pixel' => false,
    'named' => true, 'stored_names' => ['Loma'], 'e2e_names' => ['Ryder'],
    'e2e_name' => ['colorway' => 'Ryder', 'conflict' => false],
    'e2e' => ['colorway' => '', 'conflict' => false], 'e2e_name_share' => 0.0, 'e2e_share' => 0.0,
    'e2e_support' => 0, 'any_e2e' => false, 'category' => 'Lifestyle',
]);
pr_colorway_crop_tally($cw_crop, [
    'truth' => 'Black', 'regions' => 1, 'named' => false, 'stored_names' => [], 'e2e_names' => ['Ryder'],
    'match' => ['colorway' => '', 'conflict' => false], 'pixel' => ['colorway' => '', 'conflict' => false],
    'match_share' => 0.0, 'match_support' => 0, 'any_match' => false, 'any_pixel' => false,
    'e2e_name' => ['colorway' => 'Ryder', 'conflict' => false], 'e2e' => ['colorway' => 'Black', 'conflict' => false],
    'e2e_name_share' => 0.7, 'e2e_share' => 1.0, 'e2e_support' => 3, 'any_e2e' => true,
]);
pr_check("crop tally: end to end scores every photo, unnamed separately; product vs stored Name",
         $cw_crop['methods']['e2e_named'] === ['correct' => 1, 'wrong' => 0, 'blank' => 1]
         && $cw_crop['methods']['e2e_unnamed'] === ['correct' => 1, 'wrong' => 0, 'blank' => 0]
         && $cw_crop['product'] === ['correct' => 1, 'wrong' => 1, 'blank' => 0]
         && $cw_crop['bands']['e2e_support'] === ['5+' => ['correct' => 1], '3-4' => ['correct' => 1]]
         && pr_colorway_crop_product_outcome(['Loma', 'Ryder'], ['ryder']) === 'correct'
         && pr_colorway_crop_product_outcome(['Loma', 'Ryder'], ['Loma', 'Ryder']) === 'correct'
         && pr_colorway_crop_product_outcome(['Loma'], ['Loma', 'Ryder']) === 'wrong'
         && pr_colorway_crop_product_outcome(['Loma'], ['', '']) === 'blank');
pr_check("crop tally: split by photo category, rows without one under (none)",
         $cw_crop['by_category']['match'] === ['(none)' => ['blank' => 1], 'Lifestyle' => ['wrong' => 1]]
         && $cw_crop['by_category']['pixel'] === ['(none)' => ['correct' => 1], 'Lifestyle' => ['blank' => 1]]
         && $cw_crop['by_category']['e2e_unnamed'] === ['(none)' => ['correct' => 1]]);
pr_check("crop tally: per method, conflicts, any-region, bands from match votes only",
         $cw_crop['considered'] === 3 && $cw_crop['regions'] === 4 && $cw_crop['multi_region'] === 1
         && $cw_crop['methods']['match'] === ['correct' => 0, 'wrong' => 1, 'blank' => 1]
         && $cw_crop['methods']['pixel'] === ['correct' => 1, 'wrong' => 0, 'blank' => 1]
         && $cw_crop['conflict'] === ['match' => 1, 'pixel' => 0, 'e2e_named' => 0, 'e2e_unnamed' => 0]
         && $cw_crop['any'] === ['match' => 1, 'pixel' => 1, 'e2e_named' => 1, 'e2e_unnamed' => 1]
         && $cw_crop['bands']['share'] === ['<=1.0' => ['wrong' => 1]]
         && $cw_crop['bands']['support'] === ['3-4' => ['wrong' => 1]]
         && $cw_crop['confusion'] === ["match\tNavy\tBlack" => 1]
         && count(pr_colorway_crop_summary($cw_crop)) > 10);

# --- color from the cutout ---------------------------------------------------------------------
pr_check("color bins: grays by value, hues apart, all in range",
         pr_color_bin(10, 10, 10) === 0 && pr_color_bin(250, 250, 250) === PR_COLOR_GRAY_BINS - 1
         && pr_color_bin(200, 30, 30) !== pr_color_bin(30, 120, 60)
         && pr_color_bin(30, 120, 60) !== pr_color_bin(150, 120, 200)
         && max(array_map(fn($c) => pr_color_bin(...$c), [[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 0, 254]])) < pr_color_bins());
$cw_png = static function (array $rgb, bool $alpha): string {
    $im = imagecreatetruecolor(40, 40);
    imagealphablending($im, false);
    imagesavealpha($im, $alpha);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, $alpha ? 127 : 0));
    imagefilledrectangle($im, 5, 5, 34, 34, imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], 0));
    $path = tempnam(sys_get_temp_dir(), 'prc') . '.png';
    imagepng($im, $path);
    return $path;
};
$cw_green = $cw_png([30, 110, 60], true);
$cw_rect = $cw_png([30, 110, 60], false);
$cw_hist = pr_cutout_color_histogram($cw_green);
pr_check("cutout histogram reads only the masked pixels; a rectangle crop is refused",
         $cw_hist !== null && abs(array_sum($cw_hist) - 1.0) < 1e-9
         && $cw_hist[pr_color_bin(30, 110, 60)] === 1.0
         && pr_cutout_color_histogram($cw_rect) === null);
@unlink($cw_green);
@unlink($cw_rect);
$cw_bins = pr_color_bins();
$cw_one = static function (int $bin) use ($cw_bins): array {
    $h = array_fill(0, $cw_bins, 0.0);
    $h[$bin] = 1.0;
    return $h;
};
$cw_lib = [
    ['resource' => 1, 'names' => ['Loma'], 'colorway' => 'Evergreen', 'hists' => [$cw_one(10)], 'keys' => [100]],
    ['resource' => 2, 'names' => ['Loma'], 'colorway' => 'Evergreen', 'hists' => [$cw_one(10)], 'keys' => [200]],
    ['resource' => 3, 'names' => ['Loma'], 'colorway' => 'Lavender', 'hists' => [$cw_one(30)], 'keys' => [300]],
    ['resource' => 4, 'names' => ['Ryder'], 'colorway' => 'Black', 'hists' => [$cw_one(0)], 'keys' => [400]],
    ['resource' => 5, 'names' => ['Loma'], 'colorway' => 'Black', 'hists' => [$cw_one(0), $cw_one(0)], 'keys' => [500]],
];
$cw_prof = pr_color_profiles($cw_lib, ['Loma'], [200], 1);
pr_check("profiles: same product only, other shoots only, single-cutout photos only, not itself",
         array_keys($cw_prof) === ['lavender'] && $cw_prof['lavender']['n'] === 1);
$cw_prof = pr_color_profiles($cw_lib, ['loma'], [999], 0);
$cw_pick = pr_color_pick($cw_one(10), $cw_prof);
pr_check("pick: closest profile wins with its margin and support",
         array_keys($cw_prof) === ['evergreen', 'lavender'] && $cw_pick['colorway'] === 'Evergreen'
         && abs($cw_pick['margin'] - 1.0) < 1e-9 && $cw_pick['support'] === 2 && $cw_pick['candidates'] === 2
         && pr_color_pick($cw_one(10), [])['colorway'] === '');
$cw_cs = pr_color_new_stats();
pr_color_tally($cw_cs, ['truth' => 'Evergreen', 'category' => 'Lifestyle', 'vote' => ['colorway' => 'Evergreen', 'conflict' => false], 'margin' => 0.3, 'candidates' => 2]);
pr_color_tally($cw_cs, ['truth' => 'Black', 'category' => '', 'vote' => ['colorway' => 'Black', 'conflict' => false], 'margin' => 1.0, 'candidates' => 1]);
pr_color_tally($cw_cs, ['truth' => 'Lavender', 'category' => 'Ecomm', 'vote' => ['colorway' => 'Evergreen', 'conflict' => false], 'margin' => 0.03, 'candidates' => 2]);
pr_check("color tally: margin bands, one-colorway products apart, category, confusion",
         $cw_cs['cell'] === ['correct' => 2, 'wrong' => 1, 'blank' => 0] && $cw_cs['single_candidate'] === 1
         && $cw_cs['margin'] === ['<=1.0' => ['correct' => 1], 'only' => ['correct' => 1], '<0.05' => ['wrong' => 1]]
         && $cw_cs['category']['(none)'] === ['correct' => 1]
         && $cw_cs['confusion'] === ["Lavender\tEvergreen" => 1]
         && count(pr_color_summary($cw_cs)) > 10);
