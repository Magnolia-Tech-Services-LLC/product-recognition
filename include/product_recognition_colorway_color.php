<?php
/*
 * Colorway from the measured color of the product cutout (read-only report).
 *
 * The DINOv2 neighbour vote identifies the product but not its color: all colorways of
 * one bag look alike to it (#18). Here the cutout's own pixels decide. Each cutout gets a
 * color histogram (pixels inside the mask only). A product's colorway profile is the mean
 * histogram of tagged cutouts of that product in that colorway, from single-product
 * photos. A photo is scored against the profiles of its stored product's colorways,
 * built without any photo from the same shoot (folder keys, as the accuracy report).
 * Photo Category plays no part; it is reported only to check that.
 */

const PR_COLOR_HUE_BINS = 12;
const PR_COLOR_GRAY_BINS = 4;
const PR_COLOR_MIN_PIXELS = 50;
const PR_COLOR_MARGIN_BANDS = [0.02, 0.05, 0.1, 0.2, 1.01];

/** Number of bins in a color histogram. */
function pr_color_bins(): int
{
    return PR_COLOR_GRAY_BINS + PR_COLOR_HUE_BINS * 4;
}

/**
 * Bin for one RGB pixel: low-saturation pixels go to gray bins by value; the rest to a
 * hue bin split by saturation (2) and value (2).
 */
function pr_color_bin(int $r, int $g, int $b): int
{
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $v = $max / 255;
    $s = $max > 0 ? ($max - $min) / $max : 0.0;
    if ($s < 0.2 || $v < 0.12) {
        return min(PR_COLOR_GRAY_BINS - 1, (int)floor($v * PR_COLOR_GRAY_BINS));
    }
    $d = $max - $min;
    if ($max === $r) {
        $h = fmod(($g - $b) / $d, 6.0);
    } elseif ($max === $g) {
        $h = ($b - $r) / $d + 2;
    } else {
        $h = ($r - $g) / $d + 4;
    }
    $h = fmod($h * 60 + 360, 360);
    $hue = min(PR_COLOR_HUE_BINS - 1, (int)floor($h / (360 / PR_COLOR_HUE_BINS)));
    return PR_COLOR_GRAY_BINS + $hue * 4 + ($s >= 0.5 ? 2 : 0) + ($v >= 0.55 ? 1 : 0);
}

/**
 * True when the PNG has an alpha channel (color type 4 or 6). A cutout without one is a
 * rectangle of the scene, not the product.
 */
function pr_png_has_alpha(string $path): bool
{
    $head = @file_get_contents($path, false, null, 0, 26);
    return is_string($head) && strlen($head) === 26 && in_array(ord($head[25]), [4, 6], true);
}

/**
 * Normalised color histogram of the opaque pixels of a cutout, or null when the file
 * has no alpha channel or too few opaque pixels.
 *
 * @return list<float>|null
 */
function pr_cutout_color_histogram(string $path): ?array
{
    if (!pr_png_has_alpha($path)) {
        return null;
    }
    $im = @imagecreatefrompng($path);
    if ($im === false) {
        return null;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $step = max(1, (int)floor(max($w, $h) / 120));
    $hist = array_fill(0, pr_color_bins(), 0);
    $n = 0;
    for ($y = 0; $y < $h; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            $rgba = imagecolorat($im, $x, $y);
            if ((($rgba & 0x7F000000) >> 24) > 16) {
                continue;
            }
            $hist[pr_color_bin(($rgba >> 16) & 255, ($rgba >> 8) & 255, $rgba & 255)]++;
            $n++;
        }
    }
    if ($n < PR_COLOR_MIN_PIXELS) {
        return null;
    }
    return array_map(static fn(int $c): float => $c / $n, $hist);
}

/**
 * Histogram intersection (1 = identical distributions).
 *
 * @param list<float> $a
 * @param list<float> $b
 */
function pr_color_similarity(array $a, array $b): float
{
    $sum = 0.0;
    foreach ($a as $i => $value) {
        $sum += min($value, $b[$i] ?? 0.0);
    }
    return $sum;
}

/**
 * Closest colorway profile to one cutout.
 *
 * @param list<float> $hist
 * @param array<string, array{label:string, sum:list<float>, n:int}> $profiles
 * @return array{colorway:string, similarity:float, margin:float, candidates:int, support:int}
 */
function pr_color_pick(array $hist, array $profiles): array
{
    $scores = [];
    foreach ($profiles as $key => $profile) {
        if ($profile['n'] <= 0) {
            continue;
        }
        $mean = array_map(static fn(float $v): float => $v / $profile['n'], $profile['sum']);
        $scores[$key] = pr_color_similarity($hist, $mean);
    }
    if ($scores === []) {
        return ['colorway' => '', 'similarity' => 0.0, 'margin' => 0.0, 'candidates' => 0, 'support' => 0];
    }
    arsort($scores);
    $keys = array_keys($scores);
    $best = $keys[0];
    $second = isset($keys[1]) ? $scores[$keys[1]] : 0.0;
    return [
        'colorway' => $profiles[$best]['label'],
        'similarity' => $scores[$best],
        'margin' => $scores[$best] - $second,
        'candidates' => count($scores),
        'support' => $profiles[$best]['n'],
    ];
}

/**
 * Profiles for one query photo: per colorway of the given product names, summed
 * histograms of single-cutout library photos, skipping any photo that shares a folder
 * key with the query.
 *
 * @param list<array{resource:int, names:list<string>, colorway:string, hists:list<list<float>>, keys:list<int>}> $library
 * @param list<string> $names
 * @param list<int> $query_keys
 * @return array<string, array{label:string, sum:list<float>, n:int}>
 */
function pr_color_profiles(array $library, array $names, array $query_keys, int $exclude): array
{
    $want = array_flip(array_map('pr_fold_label', $names));
    $skip = array_flip($query_keys);
    $profiles = [];
    foreach ($library as $item) {
        if ($item['resource'] === $exclude || count($item['hists']) !== 1 || count($item['names']) !== 1) {
            continue;
        }
        if (!isset($want[pr_fold_label($item['names'][0])])) {
            continue;
        }
        foreach ($item['keys'] as $key) {
            if (isset($skip[$key])) {
                continue 2;
            }
        }
        $fold = pr_fold_label($item['colorway']);
        if (!isset($profiles[$fold])) {
            $profiles[$fold] = ['label' => $item['colorway'], 'sum' => array_fill(0, pr_color_bins(), 0.0), 'n' => 0];
        }
        foreach ($item['hists'][0] as $i => $v) {
            $profiles[$fold]['sum'][$i] += $v;
        }
        $profiles[$fold]['n']++;
    }
    return $profiles;
}

function pr_color_new_stats(): array
{
    return [
        'photos' => 0, 'not_a_color' => 0, 'no_name' => 0, 'no_alpha_cutout' => 0, 'no_folder_key' => 0,
        'no_profile' => 0, 'scored' => 0, 'single_candidate' => 0,
        'cell' => ['correct' => 0, 'wrong' => 0, 'blank' => 0], 'conflict' => 0,
        'margin' => [], 'category' => [], 'by_color' => [], 'confusion' => [],
    ];
}

/**
 * @param array{truth:string, category:string, vote:array{colorway:string,conflict:bool},
 *              margin:float, candidates:int} $row
 */
function pr_color_tally(array &$stats, array $row): void
{
    $stats['scored']++;
    if ($row['candidates'] === 1) {
        $stats['single_candidate']++;
    }
    if ($row['vote']['conflict']) {
        $stats['conflict']++;
    }
    $outcome = pr_colorway_report_outcome($row['truth'], $row['vote']['colorway']);
    $stats['cell'][$outcome]++;
    $category = $row['category'] === '' ? '(none)' : $row['category'];
    $stats['category'][$category][$outcome] = ($stats['category'][$category][$outcome] ?? 0) + 1;
    $fold = pr_fold_label($row['truth']);
    $stats['by_color'][$fold]['label'] ??= $row['truth'];
    $stats['by_color'][$fold][$outcome] = ($stats['by_color'][$fold][$outcome] ?? 0) + 1;
    if ($outcome === 'blank') {
        return;
    }
    # A product with one known colorway has no rival; report it apart from the margin bands.
    $band = $row['candidates'] === 1 ? 'only' : pr_colorway_report_band($row['margin'], PR_COLOR_MARGIN_BANDS);
    $stats['margin'][$band][$outcome] = ($stats['margin'][$band][$outcome] ?? 0) + 1;
    if ($outcome === 'wrong') {
        $pair = $row['truth'] . "\t" . $row['vote']['colorway'];
        $stats['confusion'][$pair] = ($stats['confusion'][$pair] ?? 0) + 1;
    }
}

/** @return list<string> */
function pr_color_summary(array $stats): array
{
    $pct = static fn(int $n, int $d): string => $d > 0 ? sprintf('%5.1f%%', 100 * $n / $d) : '    -';
    $row = static function (string $label, array $cell) use ($pct): string {
        $c = (int)($cell['correct'] ?? 0);
        $w = (int)($cell['wrong'] ?? 0);
        $b = (int)($cell['blank'] ?? 0);
        return sprintf('  %-22s %7d  %5d  %5d  %9s  %8s', $label, $c, $w, $b, $pct($c, $c + $w), $pct($c + $w, $c + $w + $b));
    };
    $lines = [];
    $lines[] = sprintf('Tagged photos with cutouts  %d', $stats['photos']);
    $lines[] = sprintf('  stored label not a color (skipped)        %d', $stats['not_a_color']);
    $lines[] = sprintf('  no stored product (skipped)               %d', $stats['no_name']);
    $lines[] = sprintf('  no masked cutout, rectangle only (skipped) %d', $stats['no_alpha_cutout']);
    $lines[] = sprintf('  no folder key (skipped)                   %d', $stats['no_folder_key']);
    $lines[] = sprintf('  no profile for the product (skipped)      %d', $stats['no_profile']);
    $lines[] = sprintf('  scored %d (product with one known colorway: %d; regions disagree: %d)',
        $stats['scored'], $stats['single_candidate'], $stats['conflict']);
    $lines[] = '';
    $lines[] = '                         correct  wrong  blank  precision  coverage';
    $lines[] = $row('all', $stats['cell']);
    $lines[] = '';
    $lines[] = 'by margin over the next colorway (only = product has one known colorway):';
    $bands = $stats['margin'];
    ksort($bands);
    foreach ($bands as $label => $cell) {
        $lines[] = $row((string)$label, $cell);
    }
    $lines[] = '';
    $lines[] = 'by photo category:';
    $categories = $stats['category'];
    ksort($categories);
    foreach ($categories as $label => $cell) {
        $lines[] = $row((string)$label, $cell);
    }
    $lines[] = '';
    $lines[] = 'by stored colorway (top 30):';
    $by_color = $stats['by_color'];
    $total = static fn(array $c): int => (int)($c['correct'] ?? 0) + (int)($c['wrong'] ?? 0) + (int)($c['blank'] ?? 0);
    uasort($by_color, static fn($x, $y) => $total($y) <=> $total($x));
    foreach (array_slice($by_color, 0, 30, true) as $cell) {
        $lines[] = $row($cell['label'], $cell);
    }
    $lines[] = '';
    $lines[] = 'Most common wrong votes (stored, voted, count):';
    $confusion = $stats['confusion'];
    arsort($confusion);
    foreach (array_slice($confusion, 0, 15, true) as $pair => $n) {
        $lines[] = '  ' . $pair . "\t" . $n;
    }
    return $lines;
}

/**
 * Read-only: histograms every tagged cutout once, then scores each photo against
 * profiles built from other shoots. Reads mask files and metadata; writes nothing.
 *
 * @param callable(array):void $on_row
 */
function pr_colorway_color_report(callable $on_row): array
{
    $stats = pr_color_new_stats();
    $product_field = pr_product_field();
    $photos = pr_colorway_crop_photos(0);
    $stats['photos'] = count($photos);
    $facts = ['folders' => []];
    foreach (array_chunk(array_column($photos, 'resource'), 500) as $chunk) {
        $facts['folders'] += pr_colorway_report_facts($chunk)['folders'];
    }

    $library = [];
    foreach ($photos as $photo) {
        $resource = $photo['resource'];
        if (!pr_colorway_label_counts($photo['colorway'])) {
            $stats['not_a_color']++;
            continue;
        }
        $names = pr_field_leaf_names($resource, $product_field);
        if ($names === []) {
            $stats['no_name']++;
            continue;
        }
        $keys = $facts['folders'][$resource] ?? [];
        if ($keys === []) {
            $stats['no_folder_key']++;
            continue;
        }
        $data = get_resource_data($resource);
        $checksum = $data === false ? '' : (string)$data['file_checksum'];
        $hists = [];
        $boxes = pr_cached_regions($resource);
        foreach ($boxes as $i => $_bbox) {
            $hist = pr_cutout_color_histogram(pr_mask_file($resource, $checksum, $i));
            if ($hist !== null) {
                $hists[] = $hist;
            }
        }
        # Every region needs a masked cutout, or a multi-bag photo would be scored on part of it.
        if ($hists === [] || count($hists) !== count($boxes)) {
            $stats['no_alpha_cutout']++;
            continue;
        }
        $library[] = [
            'resource' => $resource,
            'names' => $names,
            'colorway' => $photo['colorway'],
            'category' => $photo['category'],
            'hists' => $hists,
            'keys' => $keys,
        ];
    }

    foreach ($library as $item) {
        $profiles = pr_color_profiles($library, $item['names'], $item['keys'], $item['resource']);
        if ($profiles === []) {
            $stats['no_profile']++;
            continue;
        }
        $picks = array_map(static fn(array $h): array => pr_color_pick($h, $profiles), $item['hists']);
        $vote = pr_colorway_crop_photo_vote(array_column($picks, 'colorway'));
        $margin = $vote['colorway'] === '' ? 0.0 : min(array_column($picks, 'margin'));
        $row = [
            'resource' => $item['resource'],
            'truth' => $item['colorway'],
            'product' => implode('|', $item['names']),
            'category' => $item['category'],
            'regions' => count($item['hists']),
            'vote' => $vote,
            'margin' => $margin,
            'candidates' => max(array_column($picks, 'candidates')),
            'support' => $vote['colorway'] === '' ? 0 : min(array_column($picks, 'support')),
            'picks' => array_column($picks, 'colorway'),
        ];
        pr_color_tally($stats, $row);
        $on_row($row);
    }
    return $stats;
}

/** @return list<string> */
function pr_color_header(): array
{
    return ['resource', 'stored_colorway', 'stored_product', 'category', 'regions', 'voted',
            'margin', 'candidates', 'support', 'by_region'];
}

/** @return list<string> */
function pr_color_fields(array $row): array
{
    return [
        (string)$row['resource'], $row['truth'], $row['product'], $row['category'], (string)$row['regions'],
        $row['vote']['colorway'], sprintf('%.3f', $row['margin']), (string)$row['candidates'],
        (string)$row['support'], implode('|', $row['picks']),
    ];
}
