<?php
/**
 * Family bind + sheet identity. The grammar lives in product-category-map.php
 * (SundayGolf/resourcespace, not this plugin). Load it; do not fork it.
 */

include_once __DIR__ . '/product_recognition_sheet.php';

function pr_identity_grammar_ready(): bool
{
    return function_exists('product_identity_by_model')
        && function_exists('product_identity_from_sheet')
        && function_exists('product_family_values')
        && function_exists('sg_collaboration_from_name')
        && function_exists('sg_clubtype_from_name')
        && function_exists('sg_retail_from_name');
}

function pr_load_identity_grammar(): bool
{
    if (pr_identity_grammar_ready()) {
        return true;
    }
    $paths = ['/opt/rs-defaults/product-category-map.php'];
    $dir = dirname(__FILE__);
    for ($i = 0; $i < 8; $i++) {
        $dir = dirname($dir);
        $paths[] = $dir . '/resourcespace-product-identity/product-category-map.php';
        $paths[] = $dir . '/product-category-map.php';
    }
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        require_once $path;
        if (pr_identity_grammar_ready()) {
            return true;
        }
    }
    return pr_identity_grammar_ready();
}

/**
 * Category / branch words that are not products, plus Recess size bands
 * that must not split the existing Recess reference class.
 *
 * @return list<string>
 */
function pr_ignored_product_labels(): array
{
    return [
        'Golf Accessories', 'Travel Bags', 'Headwear', 'Soft Goods', 'Apparel',
        'Golf Clubs', 'Golf Set', 'Golf Bags', 'Accessories', 'Hats',
        'Recess 5-8', 'Recess 9-12',
    ];
}

function pr_is_ignored_product_label(string $name): bool
{
    $want = pr_fold_label($name);
    if ($want === '') {
        return false;
    }
    foreach (['Recess 5-8', 'Recess 9-12'] as $band) {
        if (pr_fold_label($band) === $want) {
            return true;
        }
    }
    if (pr_load_identity_grammar()) {
        foreach (product_family_values() as $family) {
            if (pr_fold_label((string)$family) === $want) {
                return false;
            }
        }
    }
    foreach (pr_ignored_product_labels() as $label) {
        if (pr_fold_label($label) === $want) {
            return true;
        }
    }
    return false;
}

/**
 * Invisible variants: pixels cannot tell these from the base product.
 * Keep the original three names first; grow, do not shorten.
 */
function pr_never_auto_apply_default(): string
{
    return 'Loma XL, Big Rig S-Class, Ryder 14, Loma Lite, El Camino Superlite, Ryder Lefty, Storm Ryder, Loma S-Class, Loma XL S-Class, El Camino S-Class, Ryder S-Class, Ryder 14 S-Class';
}

function pr_field_ref_by_name(string $name): int
{
    if ($name === '') {
        return 0;
    }
    return (int)ps_value(
        "SELECT ref AS value FROM resource_type_field WHERE name = ?",
        ["s", $name],
        0
    );
}

/**
 * Name field (modelkeyword) when leftover product_reference rows are gone;
 * otherwise the field those leftover rows still point at (not setup).
 *
 * Setup may already say Family or Name. Bind stays on the old nodes until
 * --migrate-family remaps them onto Name. --references will not delete them
 * while they remain. Family is not the reference label.
 */
function pr_product_field(): int
{
    $pending = pr_pending_tree_field();
    if ($pending > 0) {
        return $pending;
    }
    $name = pr_field_ref_by_name('modelkeyword');
    if ($name > 0) {
        return $name;
    }
    return (int)($GLOBALS['product_recognition_field'] ?? 0);
}

function pr_product_reference_table_exists(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $exists = (int)ps_value(
        "SELECT COUNT(*) AS value FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        ["s", "product_reference"],
        0
    ) === 1;
    return $exists;
}

/**
 * Field id of leftover live product_reference nodes that are not Name
 * (modelkeyword). Family rows are leftover: they collapsed the sub-class.
 * derived/pinned only — orphaned and excluded rows do not keep migrate open.
 * Independent of the setup dropdown. 0 = none, no Name field, or no table.
 */
function pr_pending_tree_field(bool $refresh = false): int
{
    static $cached = null;
    if ($refresh) {
        $cached = null;
    }
    if ($cached !== null) {
        return $cached;
    }
    $name_field = pr_field_ref_by_name('modelkeyword');
    if ($name_field <= 0 || !pr_product_reference_table_exists()) {
        $cached = 0;
        return 0;
    }
    $cached = (int)ps_value(
        "SELECT n.resource_type_field AS value
           FROM product_reference pr
           JOIN node n ON n.ref = pr.model_node
          WHERE n.resource_type_field <> ?
            AND pr.status IN ('derived', 'pinned')
          GROUP BY n.resource_type_field
          ORDER BY COUNT(*) DESC
          LIMIT 1",
        ["i", $name_field],
        0
    );
    return $cached;
}

function pr_product_field_is_flat(): bool
{
    $field = pr_product_field();
    if ($field <= 0) {
        return false;
    }
    $rows = ps_query(
        "SELECT name, type FROM resource_type_field WHERE ref = ?",
        ["i", $field]
    );
    if ($rows === []) {
        return false;
    }
    # Name and Family are flat (no ancestor copy). Other non-tree fields too.
    if (in_array((string)$rows[0]['name'], ['modelkeyword', 'family'], true)) {
        return true;
    }
    return defined('FIELD_TYPE_CATEGORY_TREE')
        && (int)$rows[0]['type'] !== (int)FIELD_TYPE_CATEGORY_TREE;
}

/**
 * Identity row for a Name string (modelkeyword / tree leaf name).
 *
 * @return array{category:string, family:string, name:string, edition:string}|null
 */
function pr_identity_for_name(string $name): ?array
{
    if (!pr_load_identity_grammar()) {
        return null;
    }
    $map = pr_identity_catalog();
    if (isset($map[$name])) {
        return $map[$name];
    }
    $want = pr_fold_label($name);
    $hits = [];
    foreach ($map as $key => $row) {
        if (pr_fold_label((string)$key) === $want) {
            $hits[] = $row;
        }
    }
    return count($hits) === 1 ? $hits[0] : null;
}

/**
 * Identity rows whose family equals $label (folded).
 *
 * @return list<array{category:string, family:string, name:string, edition:string}>
 */
function pr_identity_rows_for_family(string $label): array
{
    if (!pr_load_identity_grammar()) {
        return [];
    }
    $want = pr_fold_label($label);
    if ($want === '') {
        return [];
    }
    $out = [];
    foreach (pr_identity_catalog() as $row) {
        if (pr_fold_label($row['family']) === $want) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Identity for a reference label. Map hit first. A longer S-Class name the
 * grammar emits (Ryder 14 S-Class) stays that name. It does not fold onto
 * Ryder or onto Ryder 14.
 *
 * @return array{category:string, family:string, name:string, edition:string, collaboration?:string, clubtype?:string, retailchannel?:string}|null
 */
function pr_identity_for_reference_label(string $label): ?array
{
    $direct = pr_identity_for_name($label);
    if ($direct !== null) {
        return $direct;
    }
    if (!pr_load_identity_grammar()) {
        return null;
    }
    $want = pr_fold_label($label);
    if ($want === '' || !str_ends_with($want, ' s class')) {
        return null;
    }
    $best = null;
    $best_len = -1;
    $tie = false;
    foreach (pr_identity_catalog() as $row) {
        $base = pr_fold_label((string)$row['name']);
        if ($base === '' || !str_starts_with($want, $base . ' ')) {
            continue;
        }
        $sheet = product_identity_from_sheet(
            (string)$row['category'],
            (string)$row['family'],
            $label
        );
        if ($sheet === null) {
            continue;
        }
        if (pr_fold_label((string)$sheet['name']) !== $want) {
            continue;
        }
        if ((string)($sheet['edition'] ?? '') !== 'S-Class') {
            continue;
        }
        if (pr_fold_label((string)$sheet['family']) !== pr_fold_label((string)$row['family'])) {
            continue;
        }
        $len = strlen($base);
        if ($len > $best_len) {
            $best = $sheet;
            $best_len = $len;
            $tie = false;
        } elseif ($len === $best_len) {
            $tie = true;
        }
    }
    if ($tie || $best === null) {
        return null;
    }
    return $best;
}

/**
 * Reference label at Name grain. Empty means unbound.
 * Ryder 14 S-Class stays Ryder 14 S-Class. Pacific (a family, not a name)
 * stays unbound. Recess 5-8 stays unbound.
 */
function pr_reference_label(string $label): string
{
    $label = trim($label);
    if ($label === '' || pr_is_ignored_product_label($label)) {
        return '';
    }
    $identity = pr_identity_for_reference_label($label);
    if ($identity === null || pr_is_ignored_product_label((string)$identity['family'])) {
        return '';
    }
    $name = trim((string)$identity['name']);
    if ($name === '' || pr_fold_label($name) !== pr_fold_label($label)) {
        return '';
    }
    return $name;
}

/**
 * One product_reference row → Name label, or orphan.
 * A Family node is not a label. One Name tag on that resource is.
 * Two Name tags, or a Name that disagrees with the leaf, orphan.
 * Ryder 14 S-Class does not become Ryder.
 *
 * @param list<string> $name_tags
 * @return array{action:string, label:string}
 */
function pr_migrate_reference_decision(string $node_name, bool $node_is_family, array $name_tags): array
{
    $raw = [];
    foreach ($name_tags as $tag) {
        $tag = trim((string)$tag);
        if ($tag !== '') {
            $raw[] = $tag;
        }
    }
    # Two Name nodes stay out, even when one of them is not a product name.
    # Folding first would hide "Ryder" + "Not A Product" as a single Ryder.
    if (count($raw) > 1) {
        return ['action' => 'orphan', 'label' => ''];
    }
    $only = $raw[0] ?? '';
    $only_label = $only === '' ? '' : pr_reference_label($only);
    if ($node_is_family) {
        if ($only_label === '') {
            return ['action' => 'orphan', 'label' => ''];
        }
        return ['action' => 'retarget', 'label' => $only_label];
    }
    $leaf = pr_reference_label($node_name);
    if ($leaf === '') {
        return ['action' => 'orphan', 'label' => ''];
    }
    if ($only !== '' && ($only_label === '' || pr_fold_label($only_label) !== pr_fold_label($leaf))) {
        return ['action' => 'orphan', 'label' => ''];
    }
    return ['action' => 'retarget', 'label' => $leaf];
}

/**
 * Add-only writes after accepting one Family label. Pure so the selfcheck can lock it.
 *
 * @return array{family:string, productcategory:string, modelkeyword:string, edition:string, collaboration:string, clubtype:string, retailchannel:string}
 */
function pr_accept_identity_plan(string $label): array
{
    $plan = [
        'family' => '',
        'productcategory' => '',
        'modelkeyword' => '',
        'edition' => '',
        'collaboration' => '',
        'clubtype' => '',
        'retailchannel' => '',
    ];
    $label = trim($label);
    if ($label === '' || pr_is_ignored_product_label($label)) {
        return $plan;
    }
    $by_name = pr_identity_for_reference_label($label);
    $family_label = $by_name !== null ? $by_name['family'] : $label;
    if (pr_is_ignored_product_label($family_label)) {
        return $plan;
    }
    $plan['family'] = $family_label;
    $rows = pr_identity_rows_for_family($family_label);
    $unique_family = count($rows) === 1;
    $identity = $unique_family ? $rows[0] : $by_name;
    if ($identity !== null) {
        $plan['productcategory'] = $identity['category'];
    } elseif ($rows !== []) {
        $cats = array_unique(array_column($rows, 'category'));
        if (count($cats) === 1) {
            $plan['productcategory'] = $cats[0];
        }
    }
    $edition = (string)($identity['edition'] ?? '');
    # A resolved Name writes that Name. A family label with several names does not
    # guess one, except the base row whose name equals the family (plain Loma).
    $resolved = ($by_name !== null && $by_name['name'] === $label) ? $by_name : null;
    if ($resolved === null && $unique_family && $identity !== null && $identity['name'] === $family_label && $edition === '') {
        $resolved = $identity;
    }
    if ($resolved !== null && ($resolved['name'] === $family_label || $resolved['name'] === $label)) {
        $write_name = $resolved['name'] !== $family_label
            || $unique_family
            || count($rows) === 1
            || ($by_name !== null && $by_name['name'] === $family_label && $label === $family_label);
        # Plain Loma is a real Name. Pacific is not: no row is named Pacific.
        if ($by_name !== null && $by_name['name'] === $label) {
            $write_name = true;
        }
        if ($write_name) {
            $plan['modelkeyword'] = $resolved['name'];
            $plan['edition'] = (string)($resolved['edition'] ?? '');
            if (function_exists('sg_collaboration_from_name')) {
                $plan['collaboration'] = sg_collaboration_from_name($resolved['name']);
                $plan['clubtype'] = sg_clubtype_from_name($resolved['name']);
                $plan['retailchannel'] = sg_retail_from_name($resolved['name']);
            }
        }
    }
    if ($plan['edition'] === '' && $edition === 'S-Class' && $unique_family) {
        $plan['edition'] = 'S-Class';
    }
    return $plan;
}

/**
 * Product fields for Find other matches.
 * An empty sub-class is family-only: keep Family and a shared category, and do not
 * bind the family string as the base Name (Loma must not drop Loma S-Class).
 * A resolved sub-class uses every field that name writes. Colorway is the picker value.
 *
 * @return array{family:string, productcategory:string, modelkeyword:string, edition:string, collaboration:string, clubtype:string, retailchannel:string, colorway:string}
 */
function pr_find_field_plan(
    string $family,
    string $subclass,
    string $subclass2,
    string $colorway,
    string $model_text
): array {
    $family = trim($family);
    $subclass = trim($subclass);
    $subclass2 = trim($subclass2);
    $colorway = trim($colorway);
    $blank = [
        'family' => '',
        'productcategory' => '',
        'modelkeyword' => '',
        'edition' => '',
        'collaboration' => '',
        'clubtype' => '',
        'retailchannel' => '',
        'colorway' => $colorway,
    ];
    $matched_name = '';
    if ($family !== '' && pr_load_identity_grammar()) {
        foreach (pr_facet_catalog() as $row) {
            if ($row['family'] !== $family) {
                continue;
            }
            if (($row['subclass'] ?? '') === $subclass && ($row['subclass2'] ?? '') === $subclass2) {
                $matched_name = (string)$row['name'];
                break;
            }
        }
    }
    if ($family !== '' && $subclass === '' && $subclass2 === '') {
        $plan = pr_accept_identity_plan($family);
        $plan['modelkeyword'] = '';
        $plan['edition'] = '';
        $plan['collaboration'] = '';
        $plan['clubtype'] = '';
        $plan['retailchannel'] = '';
    } elseif ($matched_name !== '') {
        $plan = pr_accept_identity_plan($matched_name);
    } else {
        $plan = pr_accept_identity_plan(trim($model_text));
    }
    $plan['colorway'] = $colorway;
    foreach ($blank as $key => $value) {
        if (!array_key_exists($key, $plan)) {
            $plan[$key] = $value;
        }
    }
    return $plan;
}

/**
 * Split one identity row into the three image-view controls.
 * Sub-class 2 is S-Class on a name that already has a qualifier, or the headcover club.
 *
 * @param array{family:string, name:string, edition?:string, clubtype?:string, category?:string} $row
 * @return array{family:string, subclass:string, subclass2:string, name:string, category:string}
 */
function pr_facets_from_identity(array $row): array
{
    $family = trim((string)($row['family'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));
    $edition = trim((string)($row['edition'] ?? ''));
    $club = trim((string)($row['clubtype'] ?? ''));
    $category = trim((string)($row['category'] ?? ''));
    $empty = [
        'family' => $family,
        'subclass' => '',
        'subclass2' => '',
        'name' => $name,
        'category' => $category,
    ];
    if ($family === '' || $name === '') {
        return $empty;
    }
    $base = $name;
    if ($edition === 'S-Class' && str_ends_with($name, ' S-Class')) {
        $base = substr($name, 0, -strlen(' S-Class'));
    }
    $fold_family = pr_fold_label($family);
    $fold_base = pr_fold_label($base);
    $fold_name = pr_fold_label($name);
    if ($club !== '' && $fold_name === pr_fold_label('Headcover')) {
        $empty['subclass2'] = $club;
        return $empty;
    }
    $qualifiers = ['s-class' => 'S-Class', 'xl' => 'XL', 'lite' => 'Lite', 'lefty' => 'Lefty', '14' => '14', 'storm' => 'Storm', 'superlite' => 'Superlite'];
    $subclass = '';
    $remainder = '';
    if ($fold_base === $fold_family) {
        $subclass = $edition === 'S-Class' ? 'S-Class' : '';
    } else {
        if ($fold_family !== '' && str_starts_with($fold_base, $fold_family . ' ')) {
            $remainder = trim(substr($base, strlen($family)));
        } elseif ($fold_family !== '' && str_ends_with($fold_base, ' ' . $fold_family)) {
            $remainder = trim(substr($base, 0, -strlen($family)));
        }
        $remainder_fold = pr_fold_label($remainder);
        if ($remainder !== '' && isset($qualifiers[$remainder_fold])) {
            $subclass = $qualifiers[$remainder_fold];
        } else {
            $subclass = $base;
        }
    }
    $subclass2 = '';
    if ($edition === 'S-Class' && $subclass !== '' && $subclass !== 'S-Class') {
        $subclass2 = 'S-Class';
    }
    if ($club !== '') {
        $subclass2 = $club;
    }
    $empty['subclass'] = $subclass;
    $empty['subclass2'] = $subclass2;
    return $empty;
}

/**
 * Folder tokens and campaign labels that are stored as colorway leaves.
 * A product Name, Family, or collaboration is also not a color. Those stay
 * in the vote denominator and cannot win.
 *
 * @return array<string,true>
 */
function pr_colorway_junk_folds(): array
{
    static $folds = null;
    if ($folds !== null) {
        return $folds;
    }
    $folds = [];
    foreach ([
        'Raw', 'Media', 'Photo', 'PNG WEB', 'JPEG WEB', 'HR Raw', 'Web Raw',
        'JPG HR', 'JPG WEB', 'archived', 'Raw Photos', '03 ASSETS', 'Sell Sheets',
        'Jack Murphy', 'Loma Magnetic Towel',
    ] as $label) {
        $fold = pr_fold_label($label);
        if ($fold !== '') {
            $folds[$fold] = true;
        }
    }
    if (pr_load_identity_grammar() && function_exists('product_identity_by_model')) {
        foreach (product_identity_by_model() as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ([$key, $row['name'] ?? '', $row['family'] ?? ''] as $label) {
                $fold = pr_fold_label((string)$label);
                if ($fold !== '') {
                    $folds[$fold] = true;
                }
            }
        }
    }
    if (function_exists('sg_collaboration_from_name')) {
        foreach ([
            'Bob Marley', 'Mossy Oak', 'Whataburger', '7-Eleven', 'Long Drink',
            'Ducks Unlimited', 'Scheels', 'Sweetens Cove', 'Linksoul',
            'Pins & Aces', 'Tom Brady', 'Masters',
        ] as $label) {
            $collab = sg_collaboration_from_name($label);
            $fold = pr_fold_label($collab !== '' ? $collab : $label);
            if ($fold !== '') {
                $folds[$fold] = true;
            }
        }
    }
    return $folds;
}

function pr_colorway_label_counts(string $raw): bool
{
    $fold = pr_fold_label($raw);
    if ($fold === '') {
        return false;
    }
    return !isset(pr_colorway_junk_folds()[$fold]);
}

/**
 * Weighted colorway among neighbors of one Name. Untagged neighbors and
 * non-color leaves stay in the denominator, so a sparse tag cannot win.
 * A colorway sticks only above half that weight. A tie stays blank.
 *
 * @param list<array{similarity?:float|int, colorway?:string}> $neighbors
 */
function pr_vote_colorway(array $neighbors): string
{
    $total = 0.0;
    $weight = [];
    $label = [];
    $label_sim = [];
    foreach ($neighbors as $neighbor) {
        if (!is_array($neighbor)) {
            continue;
        }
        $similarity = max(0.0, (float)($neighbor['similarity'] ?? 0));
        if ($similarity <= 0.0) {
            continue;
        }
        $total += $similarity;
        $raw = trim((string)($neighbor['colorway'] ?? ''));
        if (!pr_colorway_label_counts($raw)) {
            continue;
        }
        $fold = pr_fold_label($raw);
        $weight[$fold] = ($weight[$fold] ?? 0.0) + $similarity;
        if (!isset($label_sim[$fold]) || $similarity > $label_sim[$fold]) {
            $label[$fold] = $raw;
            $label_sim[$fold] = $similarity;
        }
    }
    if ($total <= 0.0 || $weight === []) {
        return '';
    }
    arsort($weight);
    $fold = (string)array_key_first($weight);
    if (($weight[$fold] / $total) <= 0.5) {
        return '';
    }
    return $label[$fold] ?? '';
}

/**
 * One stored colorway leaf on this resource. Zero or several leaves vote as untagged.
 */
function pr_neighbor_stored_colorway(int $resource): string
{
    if ($resource <= 0 || !function_exists('ps_query') || !function_exists('pr_sheet_field')) {
        return '';
    }
    $field = pr_sheet_field('colorway');
    if ($field <= 0) {
        return '';
    }
    if (function_exists('pr_field_leaf_names')) {
        $names = pr_field_leaf_names($resource, $field);
    } else {
        $rows = ps_query(
            "SELECT n.name
               FROM resource_node rn
               JOIN node n ON n.ref = rn.node AND n.resource_type_field = ?
              WHERE rn.resource = ?
                AND NOT EXISTS (SELECT 1 FROM node ch WHERE ch.parent = n.ref)
              ORDER BY n.name",
            ['i', $field, 'i', $resource]
        );
        $names = [];
        foreach ($rows as $row) {
            $names[] = (string)$row['name'];
        }
    }
    return count($names) === 1 ? (string)$names[0] : '';
}

/**
 * Two sums over one neighbor list. Share below 0.5 withholds that pass.
 * Colorway is voted only after a Name wins. Neighbors whose name is not in
 * the identity map do not vote.
 *
 * @param list<array{name:string, similarity:float|int, colorway?:string}> $neighbors
 * @return array{suggest:bool, family:string, family_share:float, name:string, name_share:float, subclass:string, subclass2:string, colorway:string, category:string, confidence:float}
 */
function pr_aggregate_neighbors(array $neighbors): array
{
    $empty = [
        'suggest' => false,
        'family' => '',
        'family_share' => 0.0,
        'name' => '',
        'name_share' => 0.0,
        'subclass' => '',
        'subclass2' => '',
        'colorway' => '',
        'category' => '',
        'confidence' => 0.0,
        'top_reference' => 0,
    ];
    $family_weight = [];
    $total = 0.0;
    $valid = [];
    foreach ($neighbors as $neighbor) {
        if (!is_array($neighbor)) {
            continue;
        }
        $similarity = max(0.0, (float)($neighbor['similarity'] ?? 0));
        if ($similarity <= 0) {
            continue;
        }
        $identity = pr_identity_for_reference_label((string)($neighbor['name'] ?? ''));
        if ($identity === null || pr_is_ignored_product_label((string)$identity['family'])) {
            continue;
        }
        $family = (string)$identity['family'];
        $family_weight[$family] = ($family_weight[$family] ?? 0.0) + $similarity;
        $total += $similarity;
        $valid[] = [
            'identity' => $identity,
            'similarity' => $similarity,
            'colorway' => trim((string)($neighbor['colorway'] ?? '')),
            'resource' => (int)($neighbor['resource'] ?? 0),
        ];
    }
    if ($total <= 0.0) {
        return $empty;
    }
    arsort($family_weight);
    $family = (string)array_key_first($family_weight);
    $family_share = $family_weight[$family] / $total;
    if ($family_share < 0.5) {
        return $empty;
    }
    $name_weight = [];
    $nearest_similarity = [];
    $nearest_resource = [];
    foreach ($valid as $neighbor) {
        if ((string)$neighbor['identity']['family'] !== $family) {
            continue;
        }
        $name = (string)$neighbor['identity']['name'];
        $name_weight[$name] = ($name_weight[$name] ?? 0.0) + $neighbor['similarity'];
        if (!isset($nearest_similarity[$name]) || $neighbor['similarity'] > $nearest_similarity[$name]) {
            $nearest_similarity[$name] = $neighbor['similarity'];
            $nearest_resource[$name] = $neighbor['resource'];
        }
    }
    if ($name_weight === []) {
        return $empty;
    }
    arsort($name_weight);
    $name = (string)array_key_first($name_weight);
    $name_share = $name_weight[$name] / $family_weight[$family];
    $out = $empty;
    $out['suggest'] = true;
    $out['family'] = $family;
    $out['family_share'] = $family_share;
    $out['confidence'] = $family_share;
    # A tie is not a sub-class. Half the family weight still leaves the name empty.
    if ($name_share <= 0.5) {
        return $out;
    }
    $identity = pr_identity_for_reference_label($name);
    if ($identity === null) {
        return $out;
    }
    $facets = pr_facets_from_identity($identity);
    $out['name'] = $name;
    $out['name_share'] = $name_share;
    $out['subclass'] = $facets['subclass'];
    $out['subclass2'] = $facets['subclass2'];
    $out['category'] = (string)$identity['category'];
    $same_name = [];
    foreach ($valid as $neighbor) {
        if ((string)$neighbor['identity']['name'] === $name) {
            $same_name[] = $neighbor;
        }
    }
    $out['colorway'] = pr_vote_colorway($same_name);
    $out['top_reference'] = (int)($nearest_resource[$name] ?? 0);
    $out['confidence'] = $name_share;
    return $out;
}

/**
 * @return list<array{family:string, subclass:string, subclass2:string, name:string, category:string}>
 */
function pr_facet_catalog(): array
{
    if (!pr_load_identity_grammar()) {
        return [];
    }
    $out = [];
    foreach (pr_identity_catalog() as $row) {
        $facets = pr_facets_from_identity($row);
        $out[] = [
            'family' => $facets['family'],
            'subclass' => $facets['subclass'],
            'subclass2' => $facets['subclass2'],
            'name' => (string)$row['name'],
            'category' => $facets['category'],
        ];
    }
    return $out;
}

/**
 * Map /identify neighbors (model node ids) through the leaf names.
 * A missing colorway is the resource's single stored colorway leaf.
 *
 * @param list<array{model_node?:int, similarity?:float, resource?:int, colorway?:string, name?:string}> $neighbors
 * @param array<int,string> $leaf_models
 * @return list<array{name:string, similarity:float, colorway:string, resource:int}>
 */
function pr_named_service_neighbors(array $neighbors, array $leaf_models): array
{
    $named = [];
    foreach ($neighbors as $neighbor) {
        if (!is_array($neighbor)) {
            continue;
        }
        $node = (int)($neighbor['model_node'] ?? 0);
        $name = ($node > 0 && isset($leaf_models[$node])) ? (string)$leaf_models[$node] : (string)($neighbor['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $colorway = trim((string)($neighbor['colorway'] ?? ''));
        $resource = (int)($neighbor['resource'] ?? 0);
        if ($colorway === '' && $resource > 0) {
            $colorway = pr_neighbor_stored_colorway($resource);
        }
        $named[] = [
            'name' => $name,
            'similarity' => (float)($neighbor['similarity'] ?? 0),
            'colorway' => $colorway,
            'resource' => $resource,
        ];
    }
    return $named;
}

/**
 * Map /identify neighbors (model node ids) through the leaf names, then aggregate.
 *
 * @param list<array{model_node?:int, similarity?:float, resource?:int, colorway?:string}> $neighbors
 * @param array<int,string> $leaf_models
 */
function pr_aggregate_service_neighbors(array $neighbors, array $leaf_models): array
{
    return pr_aggregate_neighbors(pr_named_service_neighbors($neighbors, $leaf_models));
}

/**
 * Colorway vote restricted to neighbors of the given Names. Used when the
 * resource is already tagged, so the Name is not chosen by the family vote.
 *
 * @param list<array{model_node?:int, similarity?:float, resource?:int, colorway?:string}> $neighbors
 * @param array<int,string> $leaf_models
 * @param list<string> $names
 */
function pr_voted_colorway_for_names(array $neighbors, array $leaf_models, array $names): string
{
    $want = [];
    foreach ($names as $name) {
        $fold = pr_fold_label((string)$name);
        if ($fold !== '') {
            $want[$fold] = true;
        }
    }
    if ($want === []) {
        return '';
    }
    $rows = [];
    foreach (pr_named_service_neighbors($neighbors, $leaf_models) as $row) {
        if (isset($want[pr_fold_label($row['name'])])) {
            $rows[] = $row;
        }
    }
    return pr_vote_colorway($rows);
}

function pr_nodes_to_apply(int $node): array
{
    if (pr_product_field_is_flat()) {
        return [$node];
    }
    return pr_model_nodes_with_ancestors($node);
}

/**
 * Same rounding the review page prints. 0.995 is "100%". 0.994 is "99%".
 */
function pr_confidence_displays_as_100(float $confidence): bool
{
    return sprintf('%.0f', 100 * $confidence) === '100';
}

/**
 * A review-page 100% is accepted while the setup flag is off, except a
 * never-auto-apply name. Those rows stay in Product suggestions so a person
 * can Reject them. A prior rejection still refuses. Below 100%, the setup
 * flag and the calibrated threshold decide.
 * Issue 71 keeps the flag false.
 */
function pr_classify_should_auto_apply(
    bool $auto_apply_flag,
    float $top_similarity,
    float $confidence,
    ?array $threshold,
    bool $rejected,
    bool $never
): bool {
    if ($rejected) {
        return false;
    }
    if ($never) {
        return false;
    }
    if ($threshold !== null && (int)($threshold['never_auto_apply'] ?? 0) === 1) {
        return false;
    }
    if (pr_confidence_displays_as_100($confidence)) {
        return true;
    }
    if (!$auto_apply_flag) {
        return false;
    }
    if ($top_similarity >= 1.0 - 1e-12) {
        return true;
    }
    return $threshold !== null
        && ($threshold['status'] ?? '') === 'ok'
        && $threshold['min_confidence'] !== null
        && $confidence >= (float)$threshold['min_confidence'];
}

/**
 * Auto-apply only a leaf of the field classify is bound to.
 * While leftover tree references remain, do not auto-apply anything, including
 * a displayed 100%. pr_is_leaf_model stays true for a childless Name so a
 * person can still Accept. A never-auto-apply name stays in the queue at
 * every confidence, including a displayed 100%.
 */
function pr_classify_may_auto_apply(
    int $node,
    bool $auto_apply_flag,
    float $top_similarity,
    float $confidence,
    ?array $threshold,
    bool $rejected,
    bool $never
): bool {
    if (pr_pending_tree_field() > 0) {
        return false;
    }
    return $node > 0
        && pr_is_leaf_model($node)
        && pr_classify_should_auto_apply(
            $auto_apply_flag,
            $top_similarity,
            $confidence,
            $threshold,
            $rejected,
            $never
        );
}

function pr_ensure_named_node(int $field, string $name): int
{
    if ($field <= 0 || trim($name) === '') {
        return 0;
    }
    $existing = pr_unique_folded_node($name, pr_field_leaves($field));
    if ($existing !== null) {
        return $existing;
    }
    if (!function_exists('set_node')) {
        return 0;
    }
    $ref = set_node(null, $field, $name, null, 0);
    return (int)$ref;
}

/**
 * Identity writes after a product accept. Add-only unless $replace is set.
 * $replace is the panel Apply path: the chosen product replaces Family, Name,
 * and the other identity fields the grammar names.
 *
 * @return array<string,string> field => 'wrote'|'kept'|'skipped'
 */
function pr_write_accept_identity(int $resource, int $family_node, string $label_override = '', bool $replace = false): array
{
    $out = [
        'family' => 'skipped',
        'productcategory' => 'skipped',
        'modelkeyword' => 'skipped',
        'edition' => 'skipped',
        'collaboration' => 'skipped',
        'clubtype' => 'skipped',
        'retailchannel' => 'skipped',
    ];
    $family_field = pr_field_ref_by_name('family');
    if ($family_field <= 0 || $family_node <= 0) {
        return $out;
    }
    $lookups = [$family_field];
    $active = pr_product_field();
    if ($active > 0 && $active !== $family_field) {
        $lookups[] = $active;
    }
    $label = '';
    if ($label_override !== '' && pr_identity_for_reference_label($label_override) !== null) {
        $label = $label_override;
    }
    if ($label === '') {
        foreach ($lookups as $lookup) {
            $label = (string)ps_value(
                "SELECT name AS value FROM node WHERE ref = ? AND resource_type_field = ?",
                ["i", $family_node, "i", $lookup],
                ''
            );
            if ($label !== '') {
                break;
            }
        }
    }
    $plan = pr_accept_identity_plan($label);
    if ($plan['family'] === '') {
        return $out;
    }

    $fields = [
        'family' => $family_field,
        'productcategory' => pr_field_ref_by_name('productcategory'),
        'modelkeyword' => pr_field_ref_by_name('modelkeyword'),
        'edition' => pr_field_ref_by_name('edition'),
        'collaboration' => pr_field_ref_by_name('collaboration'),
        'clubtype' => pr_field_ref_by_name('clubtype') ?: pr_field_ref_by_name('club_type'),
        'retailchannel' => pr_field_ref_by_name('retailchannel') ?: pr_field_ref_by_name('retail_channel'),
    ];
    foreach ($fields as $key => $field) {
        $value = $plan[$key];
        if ($field <= 0 || $value === '') {
            continue;
        }
        if (!$replace && pr_resource_field_nodes($resource, $field) !== []) {
            $out[$key] = 'kept';
            continue;
        }
        $node = pr_ensure_named_node($field, $value);
        if ($node <= 0) {
            continue;
        }
        $existing = pr_resource_field_nodes($resource, $field);
        if (!$replace && $existing !== []) {
            $out[$key] = 'kept';
            continue;
        }
        if ($replace) {
            $remove = array_values(array_diff($existing, [$node]));
            if ($remove !== []) {
                delete_resource_nodes($resource, $remove, false);
            }
            if (!in_array($node, $existing, true)) {
                add_resource_nodes($resource, [$node], false, false);
            }
            $out[$key] = ($remove !== [] || !in_array($node, $existing, true)) ? 'wrote' : 'kept';
            continue;
        }
        add_resource_nodes($resource, [$node], false, false);
        $out[$key] = 'wrote';
    }

    return $out;
}

/**
 * Remap product_reference / product_suggestion onto Name nodes (modelkeyword).
 * Family nodes are not labels. Ryder 14 S-Class stays that Name.
 *
 * @return array{references:int, orphaned:int, suggestions_cleared:int, field:int, stamped:int, error:?string}
 */
function pr_migrate_to_family(bool $dry_run): array
{
    $stats = [
        'references' => 0, 'orphaned' => 0, 'suggestions_cleared' => 0,
        'stamped' => 0, 'field' => 0, 'error' => null,
    ];
    $name_field = pr_field_ref_by_name('modelkeyword');
    if ($name_field <= 0) {
        $stats['error'] = 'no field named modelkeyword';
        return $stats;
    }
    $stats['field'] = $name_field;
    if (!pr_load_identity_grammar()) {
        $stats['error'] = 'identity grammar not loaded';
        return $stats;
    }
    # Leftover Model-tree and Family node ids, not the setup dropdown.
    # Saving Name on setup must not remap those rows.
    $family_field = pr_field_ref_by_name('family');
    $name_nodes = pr_field_leaves($name_field);
    $rows = ps_query(
        "SELECT pr.ref, pr.resource, pr.model_node, pr.status, n.name, n.resource_type_field
           FROM product_reference pr
           JOIN node n ON n.ref = pr.model_node
          WHERE n.resource_type_field <> ?
            AND pr.status IN ('derived', 'pinned', 'excluded')",
        ["i", $name_field]
    );
    if ($rows === []) {
        if (!$dry_run) {
            pr_store_product_field($name_field);
        }
        return $stats;
    }

    foreach ($rows as $row) {
        $resource = (int)$row['resource'];
        $tags = [];
        foreach (pr_resource_field_nodes($resource, $name_field) as $nid) {
            if (isset($name_nodes[$nid])) {
                $tags[] = (string)$name_nodes[$nid];
            }
        }
        $decision = pr_migrate_reference_decision(
            (string)$row['name'],
            $family_field > 0 && (int)$row['resource_type_field'] === $family_field,
            $tags
        );
        $target = null;
        if ($decision['action'] === 'retarget') {
            $target = pr_unique_folded_node($decision['label'], $name_nodes);
            if ($target === null && !$dry_run) {
                $target = pr_ensure_named_node($name_field, $decision['label']);
                if ($target > 0) {
                    $name_nodes[$target] = $decision['label'];
                } else {
                    $target = null;
                }
            }
        }
        if ($target === null && $decision['action'] === 'retarget' && $dry_run) {
            $stats['references']++;
            continue;
        }
        if ($target === null) {
            $stats['orphaned']++;
            if (!$dry_run && (string)$row['status'] !== 'excluded') {
                ps_query(
                    "UPDATE product_reference SET status = 'orphaned' WHERE ref = ? AND status <> 'excluded'",
                    ["i", (int)$row['ref']]
                );
            }
            continue;
        }
        $stats['references']++;
        if ($dry_run) {
            continue;
        }
        ps_query(
            "UPDATE product_reference SET model_node = ? WHERE ref = ?",
            ["i", $target, "i", (int)$row['ref']]
        );
        # Add-only Name stamp so the next --references pass keeps derived rows.
        # Remap pinned/excluded node ids; do not tag an excluded resource.
        $existing = pr_resource_field_nodes($resource, $name_field);
        if ((string)$row['status'] === 'derived' && $existing === []) {
            add_resource_nodes($resource, [$target], false, false);
            $stats['stamped']++;
        }
    }

    $open = (int)ps_value(
        "SELECT COUNT(*) AS value FROM product_suggestion s
          JOIN node n ON n.ref = s.model_node
         WHERE s.state = 'open' AND n.resource_type_field <> ?",
        ["i", $name_field],
        0
    );
    $stats['suggestions_cleared'] = $open;
    if (!$dry_run && $open > 0) {
        ps_query(
            "UPDATE product_suggestion s
               JOIN node n ON n.ref = s.model_node
                SET s.state = 'superseded', s.decided_at = NOW()
              WHERE s.state = 'open' AND n.resource_type_field <> ?",
            ["i", $name_field]
        );
    }

    if (!$dry_run) {
        ps_query(
            "DELETE t FROM product_threshold t
               JOIN node n ON n.ref = t.model_node
              WHERE n.resource_type_field <> ?",
            ["i", $name_field]
        );
        if (pr_pending_tree_field(true) <= 0) {
            pr_store_product_field($name_field);
        }
    }

    return $stats;
}

function pr_store_product_field(int $field): void
{
    if ($field <= 0 || !function_exists('set_plugin_config')) {
        return;
    }
    $cfg = [];
    $raw = ps_value(
        "SELECT config_json AS value FROM plugins WHERE name = ?",
        ["s", "product_recognition"],
        ''
    );
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $cfg = $decoded;
        }
    }
    $cfg['product_recognition_field'] = $field;
    $GLOBALS['product_recognition_field'] = $field;
    set_plugin_config('product_recognition', $cfg);
}
