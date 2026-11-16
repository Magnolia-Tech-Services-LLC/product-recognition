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

$cw_stats = pr_colorway_report_new_stats();
pr_colorway_report_tally($cw_stats, [
    'truth' => 'Navy', 'a_self' => 'Navy', 'a_folder' => 'Black', 'a_folder_share' => 0.75,
    'b_self' => '', 'b_folder' => 'Navy', 'folder_top_similarity' => 0.65, 'dropped' => 3,
]);
pr_colorway_report_tally($cw_stats, [
    'truth' => 'Navy', 'a_self' => null, 'a_folder' => null, 'a_folder_share' => 0.0,
    'b_self' => 'Navy', 'b_folder' => '', 'folder_top_similarity' => 0.5, 'dropped' => 0,
]);
pr_check("colorway tally: per path and set, n/a path a not counted, confusion from folder set",
         $cw_stats['considered'] === 2
         && $cw_stats['no_model'] === 1
         && $cw_stats['dropped_same_folder'] === 3
         && $cw_stats['paths']['a_self'] === ['correct' => 1, 'wrong' => 0, 'blank' => 0]
         && $cw_stats['paths']['a_folder'] === ['correct' => 0, 'wrong' => 1, 'blank' => 0]
         && $cw_stats['paths']['b_self'] === ['correct' => 1, 'wrong' => 0, 'blank' => 1]
         && $cw_stats['paths']['b_folder'] === ['correct' => 1, 'wrong' => 0, 'blank' => 1]
         && $cw_stats['confusion'] === ["a\tNavy\tBlack" => 1]
         && $cw_stats['share_bands'] === ['<0.8' => ['wrong' => 1]]
         && $cw_stats['similarity_bands'] === ['<0.7' => ['wrong' => 1]]);
pr_check("colorway summary prints every path row",
         count(array_filter(pr_colorway_report_summary($cw_stats),
             static fn($l) => preg_match('/^(a name|b e2e) /', $l) === 1)) === 4);
