<?php
/**
 * Family bind + sheet identity. The grammar lives in product-category-map.php
 * (SundayGolf/resourcespace, not this plugin). Load it; do not fork it.
 */

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
    return 'Loma XL, Big Rig S-Class, Ryder 14, Loma Lite, El Camino Superlite, Ryder Lefty, Storm Ryder, Loma S-Class, Loma XL S-Class, El Camino S-Class, Ryder S-Class';
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
 * Family field when leftover Model-tree product_reference rows are gone;
 * otherwise the field those leftover rows still point at (not setup).
 *
 * Setup may already say Family. Bind stays on the tree until --migrate-family
 * remaps those node ids. --references will not delete them while they remain.
 */
function pr_product_field(): int
{
    $tree = pr_pending_tree_field();
    if ($tree > 0) {
        return $tree;
    }
    $family = pr_field_ref_by_name('family');
    if ($family > 0) {
        return $family;
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
 * Field id of leftover live product_reference nodes that are not Family.
 * derived/pinned only — orphaned and excluded tree rows do not keep migrate open.
 * Independent of the setup dropdown. 0 = none, no Family, or no table.
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
    $family = pr_field_ref_by_name('family');
    if ($family <= 0 || !pr_product_reference_table_exists()) {
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
        ["i", $family],
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
    # Family is always flat (no ancestor copy). Other non-tree fields too.
    if ((string)$rows[0]['name'] === 'family') {
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
    $map = product_identity_by_model();
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
    foreach (product_identity_by_model() as $row) {
        if (pr_fold_label($row['family']) === $want) {
            $out[] = $row;
        }
    }
    return $out;
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
    $by_name = pr_identity_for_name($label);
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
    # Name only when this family is exactly one product (Beanie, not Loma / Loma XL).
    if ($unique_family && $identity !== null && $identity['name'] === $family_label && $edition === '') {
        $plan['modelkeyword'] = $identity['name'];
        if (function_exists('sg_collaboration_from_name')) {
            $plan['collaboration'] = sg_collaboration_from_name($identity['name']);
            $plan['clubtype'] = sg_clubtype_from_name($identity['name']);
            $plan['retailchannel'] = sg_retail_from_name($identity['name']);
        }
    }
    if ($by_name !== null && ($by_name['edition'] ?? '') === 'S-Class' && $by_name['name'] === $label) {
        $plan['edition'] = 'S-Class';
    } elseif ($edition === 'S-Class' && $unique_family) {
        $plan['edition'] = 'S-Class';
    }
    return $plan;
}

/**
 * Sheet model_text → Family label. Unknown and ignored names stay unbound.
 */
function pr_catalog_family_label(string $model): string
{
    $model = trim($model);
    if ($model === '') {
        return '';
    }
    $identity = pr_identity_for_name($model);
    if ($identity !== null) {
        $family = $identity['family'];
        return pr_is_ignored_product_label($family) ? '' : $family;
    }
    $rows = pr_identity_rows_for_family($model);
    if ($rows === [] || pr_is_ignored_product_label($model)) {
        return '';
    }
    return $rows[0]['family'];
}

function pr_nodes_to_apply(int $node): array
{
    if (pr_product_field_is_flat()) {
        return [$node];
    }
    return pr_model_nodes_with_ancestors($node);
}

/**
 * Auto-apply writes only when the setup flag is on. A 100% neighbour skips the
 * calibrated threshold. never-auto-apply and a prior rejection always refuse.
 * Issue 71 keeps the flag false, so classify still only suggests.
 */
function pr_classify_should_auto_apply(
    bool $auto_apply_flag,
    float $top_similarity,
    float $confidence,
    ?array $threshold,
    bool $rejected,
    bool $never
): bool {
    if ($rejected || $never) {
        return false;
    }
    if ($threshold !== null && (int)($threshold['never_auto_apply'] ?? 0) === 1) {
        return false;
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
 * Add-only identity writes after a Family accept. Existing values win.
 *
 * @return array<string,string> field => 'wrote'|'kept'|'skipped'
 */
function pr_write_accept_identity(int $resource, int $family_node): array
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
        if (pr_resource_field_nodes($resource, $field) !== []) {
            $out[$key] = 'kept';
            continue;
        }
        $node = pr_ensure_named_node($field, $value);
        if ($node <= 0) {
            continue;
        }
        add_resource_nodes($resource, [$node], false, false);
        $out[$key] = 'wrote';
    }

    return $out;
}

/**
 * Remap product_reference / product_suggestion from model-tree node ids to Family nodes.
 *
 * @return array{references:int, orphaned:int, suggestions_cleared:int, field:int}
 */
function pr_migrate_to_family(bool $dry_run): array
{
    $stats = [
        'references' => 0, 'orphaned' => 0, 'suggestions_cleared' => 0,
        'stamped' => 0, 'field' => 0, 'error' => null,
    ];
    $family_field = pr_field_ref_by_name('family');
    if ($family_field <= 0) {
        return $stats;
    }
    $stats['field'] = $family_field;
    if (!pr_load_identity_grammar()) {
        $stats['error'] = 'identity grammar not loaded';
        return $stats;
    }
    # Leftover Model-tree node ids, not the setup dropdown. Saving Family on
    # setup used to make this a no-op and the next --references deleted them.
    $tree_field = pr_pending_tree_field(true);
    if ($tree_field <= 0) {
        if (!$dry_run) {
            pr_store_product_field($family_field);
        }
        return $stats;
    }

    $family_nodes = pr_field_leaves($family_field);
    $rows = ps_query(
        "SELECT pr.ref, pr.resource, pr.model_node, pr.status, n.name
           FROM product_reference pr
           JOIN node n ON n.ref = pr.model_node
          WHERE n.resource_type_field = ?
            AND pr.status IN ('derived', 'pinned', 'excluded')",
        ["i", $tree_field]
    );

    foreach ($rows as $row) {
        $identity = pr_identity_for_name((string)$row['name']);
        if ($identity === null || pr_is_ignored_product_label($identity['family'])) {
            $family_id = null;
        } else {
            $family_id = pr_unique_folded_node($identity['family'], $family_nodes);
            if ($family_id === null && $dry_run) {
                $stats['references']++;
                continue;
            }
            if ($family_id === null) {
                $family_id = pr_ensure_named_node($family_field, $identity['family']);
                if ($family_id > 0) {
                    $family_nodes[$family_id] = $identity['family'];
                } else {
                    $family_id = null;
                }
            }
        }
        if ($family_id === null) {
            $stats['orphaned']++;
            if (!$dry_run) {
                ps_query(
                    "UPDATE product_reference SET status = 'orphaned' WHERE ref = ? AND status <> 'excluded'",
                    ["i", (int)$row['ref']]
                );
            }
            continue;
        }
        $stats['references']++;
        if (!$dry_run) {
            ps_query(
                "UPDATE product_reference SET model_node = ? WHERE ref = ?",
                ["i", $family_id, "i", (int)$row['ref']]
            );
            # Add-only Family stamp so the next --references pass keeps derived rows.
            # Remap pinned/excluded node ids; do not tag an excluded resource.
            $resource = (int)$row['resource'];
            if ((string)$row['status'] === 'derived'
                && pr_resource_field_nodes($resource, $family_field) === []) {
                add_resource_nodes($resource, [$family_id], false, false);
                $stats['stamped']++;
            }
        }
    }

    $open = (int)ps_value(
        "SELECT COUNT(*) AS value FROM product_suggestion s
          JOIN node n ON n.ref = s.model_node
         WHERE s.state = 'open' AND n.resource_type_field = ?",
        ["i", $tree_field],
        0
    );
    $stats['suggestions_cleared'] = $open;
    if (!$dry_run && $open > 0) {
        ps_query(
            "UPDATE product_suggestion s
               JOIN node n ON n.ref = s.model_node
                SET s.state = 'superseded', s.decided_at = NOW()
              WHERE s.state = 'open' AND n.resource_type_field = ?",
            ["i", $tree_field]
        );
    }

    if (!$dry_run) {
        ps_query(
            "DELETE t FROM product_threshold t
               JOIN node n ON n.ref = t.model_node
              WHERE n.resource_type_field = ?",
            ["i", $tree_field]
        );
        # Another non-Family field may still hold refs; bind stays there.
        if (pr_pending_tree_field(true) <= 0) {
            pr_store_product_field($family_field);
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
