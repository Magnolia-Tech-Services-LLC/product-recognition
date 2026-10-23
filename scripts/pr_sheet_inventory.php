<?php
/**
 * Inventory all DAM SKU sheet PDFs: parse rows, compare sheets, list columns.
 * Read-only. CLI only.
 */
include_once dirname(__FILE__, 4) . '/include/boot.php';
command_line_only();
include_once dirname(__FILE__, 2) . '/include/product_recognition_functions.php';

$sheets = ps_query(
    "SELECT DISTINCT r.ref, r.file_extension, r.creation_date,
            (SELECT n.name FROM resource_node rn
               JOIN node n ON n.ref = rn.node AND n.resource_type_field = 8
              WHERE rn.resource = r.ref
              ORDER BY n.name LIMIT 1) AS title
       FROM resource r
       JOIN resource_node rn ON rn.resource = r.ref
       JOIN node n ON n.ref = rn.node AND n.resource_type_field = 8
      WHERE r.file_extension IN ('pdf', 'PDF')
        AND (n.name LIKE '%SKU%Sheet%' OR n.name LIKE '%SKU Sheet%')
      ORDER BY r.ref"
);

echo "=== SKU sheet resources (" . count($sheets) . ") ===\n";
$parsed = [];
foreach ($sheets as $s) {
    $ref = (int)$s['ref'];
    $title = (string)$s['title'];
    echo "\n--- #{$ref} {$title} created={$s['creation_date']} ---\n";
    $text = pr_pdf_text($ref);
    if ($text === null || trim($text) === '') {
        echo "  UNREADABLE\n";
        continue;
    }
    # Header lines that look like column definitions
    $headers = [];
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'SKU')) {
            $headers[$line] = ($headers[$line] ?? 0) + 1;
        }
    }
    echo "  header variants:\n";
    foreach ($headers as $h => $c) {
        echo "    [{$c}x] {$h}\n";
    }
    $rows = pr_parse_sku_sheet($text);
    echo "  parsed_skus=" . count($rows) . " text_chars=" . strlen($text) . "\n";
    $parsed[$ref] = [
        'title' => $title,
        'rows' => $rows,
        'headers' => array_keys($headers),
    ];
}

# Union + per-sheet membership
$by_sku = [];
foreach ($parsed as $ref => $info) {
    foreach ($info['rows'] as $sku => $row) {
        if (!isset($by_sku[$sku])) {
            $by_sku[$sku] = $row;
            $by_sku[$sku]['_sheets'] = [];
        }
        $by_sku[$sku]['_sheets'][] = $ref;
        # Prefer longer/non-empty fields from any sheet
        foreach (['upc', 'description', 'model_text', 'colorway_text', 'product', 'section'] as $col) {
            $cur = (string)($by_sku[$sku][$col] ?? '');
            $new = (string)($row[$col] ?? '');
            if ($cur === '' && $new !== '') {
                $by_sku[$sku][$col] = $new;
            }
        }
    }
}

ksort($by_sku);
echo "\n=== UNION unique SKUs: " . count($by_sku) . " ===\n";
echo "sku\tsection\tproduct\tmodel\tcolorway\tupc\tdescription\tsheets\n";
foreach ($by_sku as $sku => $row) {
    echo implode("\t", [
        $sku,
        $row['section'] ?? '',
        $row['product'] ?? '',
        $row['model_text'] ?? '',
        $row['colorway_text'] ?? '',
        $row['upc'] ?? '',
        $row['description'] ?? '',
        implode(',', $row['_sheets'] ?? []),
    ]) . "\n";
}

# Diff: SKUs only on one sheet vs another when multiple sheets
$sheet_ids = array_keys($parsed);
if (count($sheet_ids) >= 2) {
    echo "\n=== pairwise SKU set diffs ===\n";
    for ($i = 0; $i < count($sheet_ids); $i++) {
        for ($j = $i + 1; $j < count($sheet_ids); $j++) {
            $a = $sheet_ids[$i];
            $b = $sheet_ids[$j];
            $sa = array_keys($parsed[$a]['rows']);
            $sb = array_keys($parsed[$b]['rows']);
            $only_a = array_values(array_diff($sa, $sb));
            $only_b = array_values(array_diff($sb, $sa));
            echo "#{$a} vs #{$b}: only_a=" . count($only_a) . " only_b=" . count($only_b)
                . " shared=" . count(array_intersect($sa, $sb)) . "\n";
            if ($only_a !== [] && count($only_a) <= 40) {
                echo "  only #{$a}: " . implode(', ', $only_a) . "\n";
            }
            if ($only_b !== [] && count($only_b) <= 40) {
                echo "  only #{$b}: " . implode(', ', $only_b) . "\n";
            }
        }
    }
}

# Classify colorway-looking vs partner-looking vs junk
$collabs = [
    'mossy', 'bob marley', 'whataburger', 'costco', 'masters', 'scheels', 'linksoul',
    'long drink', 'pins', 'ducks', 'brady', 'nwtf', 'turkey', '7-eleven', 'garage',
    'heineken', 'gecko',
];
$partnerish = [];
$channelish = [];
$noncolor = [];
foreach ($by_sku as $sku => $row) {
    $cw = strtolower((string)($row['colorway_text'] ?? ''));
    $prod = strtolower((string)($row['product'] ?? ''));
    $blob = $cw . ' ' . $prod . ' ' . strtolower((string)($row['model_text'] ?? ''));
    foreach ($collabs as $p) {
        if (str_contains($blob, $p)) {
            $partnerish[$sku] = $row['product'];
            break;
        }
    }
    if (preg_match('/strap|cooler pack|blank|universal|premium backpack|magnum|dsg only/i', $cw . ' ' . $prod)) {
        $noncolor[$sku] = $row['product'] . ' => cw=' . $row['colorway_text'];
    }
}

echo "\n=== partner/channel-ish products (" . count($partnerish) . ") ===\n";
foreach ($partnerish as $sku => $p) {
    echo "  {$sku}\t{$p}\n";
}
echo "\n=== likely non-colorway tails (" . count($noncolor) . ") ===\n";
foreach ($noncolor as $sku => $p) {
    echo "  {$sku}\t{$p}\n";
}

# Models / colorways / sections summary
$models = [];
$cws = [];
$sections = [];
foreach ($by_sku as $row) {
    $m = trim((string)($row['model_text'] ?? ''));
    if ($m === '') {
        $m = '(section) ' . ($row['section'] ?? '');
    }
    $models[$m] = ($models[$m] ?? 0) + 1;
    $cw = trim((string)($row['colorway_text'] ?? ''));
    if ($cw !== '') {
        $cws[$cw] = ($cws[$cw] ?? 0) + 1;
    }
    $sec = (string)($row['section'] ?? '');
    $sections[$sec] = ($sections[$sec] ?? 0) + 1;
}
ksort($models);
ksort($cws);
ksort($sections);
echo "\n=== sections ===\n";
foreach ($sections as $s => $n) {
    echo "  {$n}\t{$s}\n";
}
echo "\n=== models (" . count($models) . ") ===\n";
foreach ($models as $m => $n) {
    echo "  {$n}\t{$m}\n";
}
echo "\n=== colorways (" . count($cws) . ") ===\n";
foreach ($cws as $c => $n) {
    echo "  {$n}\t{$c}\n";
}

# Live DB catalog vs union
$db = [];
foreach (ps_query("SELECT sku FROM product_catalog") as $r) {
    $db[(string)$r['sku']] = true;
}
$union_skus = array_keys($by_sku);
$missing_db = array_values(array_diff($union_skus, array_keys($db)));
$extra_db = array_values(array_diff(array_keys($db), $union_skus));
echo "\n=== product_catalog vs sheet union ===\n";
echo "db=" . count($db) . " union=" . count($union_skus)
    . " in_sheets_not_db=" . count($missing_db)
    . " in_db_not_sheets=" . count($extra_db) . "\n";
if ($missing_db !== []) {
    echo "missing from db: " . implode(', ', array_slice($missing_db, 0, 50)) . "\n";
}

# Gap vs live Model leaves
$leaf_models = pr_field_leaves(88);
$fold = static function (string $s): string {
    return pr_fold_label($s);
};
$leaf_fold = [];
foreach ($leaf_models as $name) {
    $leaf_fold[$fold($name)] = $name;
}
$sheet_models_unbound = [];
foreach (array_keys($models) as $m) {
    if (str_starts_with($m, '(section)')) {
        continue;
    }
    if (!isset($leaf_fold[$fold($m)])) {
        $sheet_models_unbound[$m] = true;
    }
}
echo "\n=== sheet models not in field 88 leaves (" . count($sheet_models_unbound) . ") ===\n";
foreach (array_keys($sheet_models_unbound) as $m) {
    echo "  {$m}\n";
}

$live_not_sheet = [];
foreach ($leaf_models as $name) {
    $ok = false;
    foreach (array_keys($models) as $m) {
        if (!str_starts_with($m, '(section)') && $fold($m) === $fold($name)) {
            $ok = true;
            break;
        }
        # section proxy for Towels
        if ($fold($name) === 'towels' && str_contains($m, 'Towel')) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        $live_not_sheet[$name] = true;
    }
}
echo "\n=== field 88 leaves not represented on sheets (" . count($live_not_sheet) . ") ===\n";
foreach (array_keys($live_not_sheet) as $m) {
    echo "  {$m}\n";
}
