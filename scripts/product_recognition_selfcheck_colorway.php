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
             static fn($l) => preg_match('/^(a name|b e2e) /', $l) === 1)) === 6);
