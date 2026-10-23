<?php
/**
 * Family-bind / sheet-identity assertions. Loaded from product_recognition_selfcheck.php
 * so that file stays under 1k. Do not run this file on its own.
 */

# --- sheet identity grammar (load live product-category-map.php, do not fork) -------------
pr_check("identity grammar loaded from product-category-map.php",
         pr_load_identity_grammar());

$pr_id = static function (string $category, string $family, string $product, string $color = '') {
    return product_identity_from_sheet($category, $family, $product, $color);
};

$sclass_loma = $pr_id('Golf Bags', 'Loma', 'S-CLASS LOMA');
pr_check("S-CLASS LOMA is family Loma, edition S-Class",
         $sclass_loma !== null
         && $sclass_loma['family'] === 'Loma'
         && $sclass_loma['edition'] === 'S-Class'
         && $sclass_loma['name'] === 'Loma S-Class');

$sclass_ryder14 = $pr_id('Golf Bags', 'Ryder', 'S-CLASS RYDER 14');
pr_check("S-CLASS RYDER 14 keeps Ryder 14 in the name and edition S-Class",
         $sclass_ryder14 !== null
         && $sclass_ryder14['family'] === 'Ryder'
         && $sclass_ryder14['edition'] === 'S-Class'
         && str_starts_with($sclass_ryder14['name'], 'Ryder 14'));

pr_check("STORM RYDER / RYDER LEFTY / EL CAMINO SUPERLITE stay those names",
         $pr_id('Golf Bags', 'Ryder', 'STORM RYDER')['name'] === 'Storm Ryder'
         && $pr_id('Golf Bags', 'Ryder', 'RYDER LEFTY')['name'] === 'Ryder Lefty'
         && $pr_id('Golf Bags', 'El Camino', 'EL CAMINO SUPERLITE')['name'] === 'El Camino Superlite');

pr_check("PORTER LITE 5/6 HAT both become Porter Lite Hat",
         $pr_id('Headwear', 'Porter Lite', 'PORTER LITE 5 HAT')['name'] === 'Porter Lite Hat'
         && $pr_id('Headwear', 'Porter Lite', 'PORTER LITE 6 HAT')['name'] === 'Porter Lite Hat');
pr_check("CRUSHABLE ROPE HAT and ROPE HAT stay distinct",
         $pr_id('Headwear', 'Rope Hats', 'CRUSHABLE ROPE HAT')['name'] === 'Crushable Rope Hat'
         && $pr_id('Headwear', 'Rope Hats', 'ROPE HAT')['name'] === 'Rope Hat');
pr_check("MAGNETIC TOWEL and GAMEDAY GLOVE stay those names",
         $pr_id('Accessories', 'Towels', 'MAGNETIC TOWEL')['name'] === 'Magnetic Towel'
         && $pr_id('Accessories', 'Gloves', 'GAMEDAY GLOVE')['name'] === 'Gameday Glove');

$head250 = $pr_id('Accessories', 'Headcovers', '250 HEADCOVER');
$ranger_hc = $pr_id('Accessories', 'Headcovers', 'RANGER HEADCOVER');
pr_check("250 HEADCOVER is Headcover; RANGER HEADCOVER stays Ranger Headcover",
         $head250 !== null && $head250['name'] === 'Headcover'
         && $ranger_hc !== null && $ranger_hc['name'] === 'Ranger Headcover');

pr_check("Pacific cart/stand/youth stay specific",
         $pr_id('Golf Bags', 'Pacific', 'PACIFIC CART BAG')['name'] === 'Pacific Cart Bag'
         && $pr_id('Golf Bags', 'Pacific', 'PACIFIC STAND BAG')['name'] === 'Pacific Stand Bag'
         && $pr_id('Golf Bags', 'Pacific', 'PACIFIC YOUTH GOLF BAG')['name'] === 'Pacific Youth Bag');
pr_check("Coaster stays Coaster with family Travel Bag",
         $pr_id('Soft Goods', 'Travel Bag', 'COASTER')['name'] === 'Coaster'
         && $pr_id('Soft Goods', 'Travel Bag', 'COASTER')['family'] === 'Travel Bag');

$hook = $pr_id('Golf Bags', 'Loma', 'LOMA Wall Hook - Black', 'Black');
pr_check("LOMA Wall Hook is family Wall Hook, name Loma Wall Hook, category Accessories",
         $hook !== null
         && $hook['family'] === 'Wall Hook'
         && $hook['name'] === 'Loma Wall Hook'
         && $hook['category'] === 'Accessories');

$mystery = $pr_id('Golf Bags', 'Mystery', 'LOMA');
pr_check("Mystery family becomes {Bag} Mystery",
         $mystery !== null && $mystery['name'] === 'Loma Mystery');

$du = $pr_id('Golf Bags', 'Ryder', 'DUCKS UNLIMITED - RYDER - Mossy Oak', 'Mossy Oak');
pr_check("Ducks Unlimited collab; Mossy Oak color does not win collaboration",
         $du !== null
         && $du['name'] === 'Ryder'
         && $du['family'] === 'Ryder'
         && $du['collaboration'] === 'Ducks Unlimited'
         && $du['category'] === 'Golf Bags');

pr_check("Replacements row returns nothing",
         $pr_id('Replacements', 'Loma', 'LOMA PART') === null);

pr_check("Costco and Amazon set Retail Channel; other words do not",
         $pr_id('Golf Bags', 'Loma', 'LOMA Costco')['retailchannel'] === 'Costco'
         && $pr_id('Golf Bags', 'Loma', 'LOMA Amazon')['retailchannel'] === 'Amazon'
         && $pr_id('Golf Bags', 'Loma', 'LOMA TJ Maxx')['retailchannel'] === '');

$recess58 = $pr_id('Golf Bags', 'Recess 5-8', 'RECESS 5-8');
pr_check("RECESS 5-8 stays its own sheet family and is ignored as a recognition class",
         $recess58 !== null && $recess58['family'] === 'Recess 5-8'
         && pr_is_ignored_product_label('Recess 5-8')
         && pr_is_ignored_product_label('Recess 9-12')
         && !pr_is_ignored_product_label('Recess'));

$loma_plan = pr_accept_identity_plan('Loma');
pr_check("accepting plain Loma writes Family and Name Loma and leaves Edition empty",
         $loma_plan['family'] === 'Loma'
         && $loma_plan['productcategory'] === 'Golf Bags'
         && $loma_plan['modelkeyword'] === 'Loma'
         && $loma_plan['edition'] === '');
$xl_plan = pr_accept_identity_plan('Loma XL');
pr_check("accepting Loma XL writes that Family and Name, not Family Loma",
         $xl_plan['family'] === 'Loma XL'
         && $xl_plan['productcategory'] === 'Golf Bags'
         && $xl_plan['modelkeyword'] === 'Loma XL');
$sclass_plan = pr_accept_identity_plan('Loma S-Class');
pr_check("accepting Loma S-Class writes Family Loma, Name Loma S-Class, Edition S-Class",
         $sclass_plan['family'] === 'Loma'
         && $sclass_plan['productcategory'] === 'Golf Bags'
         && $sclass_plan['modelkeyword'] === 'Loma S-Class'
         && $sclass_plan['edition'] === 'S-Class');
$ryder_split = pr_aggregate_neighbors([
    ['name' => 'Ryder', 'similarity' => 0.5, 'colorway' => 'Black'],
    ['name' => 'Ryder S-Class', 'similarity' => 0.5, 'colorway' => 'Cream'],
]);
pr_check("a Ryder / Ryder S-Class split keeps Family and leaves sub-class and colorway empty",
         $ryder_split['suggest'] === true
         && $ryder_split['family'] === 'Ryder'
         && $ryder_split['name'] === ''
         && $ryder_split['subclass'] === ''
         && $ryder_split['colorway'] === '');
$ryder_sclass = pr_aggregate_neighbors([
    ['name' => 'Ryder S-Class', 'similarity' => 0.8, 'colorway' => 'Cream', 'resource' => 14],
    ['name' => 'Ryder', 'similarity' => 0.2, 'colorway' => 'Black', 'resource' => 15],
]);
pr_check("mostly Ryder S-Class sets the sub-class and votes that reference color",
         $ryder_sclass['family'] === 'Ryder'
         && $ryder_sclass['name'] === 'Ryder S-Class'
         && $ryder_sclass['subclass'] === 'S-Class'
         && $ryder_sclass['colorway'] === 'Cream'
         && $ryder_sclass['top_reference'] === 14);
$cw_split = pr_aggregate_neighbors([
    ['name' => 'Ryder', 'similarity' => 0.4, 'colorway' => 'Black'],
    ['name' => 'Ryder', 'similarity' => 0.4, 'colorway' => 'Cream'],
]);
pr_check("a colorway tie on one Name stays blank",
         $cw_split['name'] === 'Ryder' && $cw_split['colorway'] === '');
$cw_majority = pr_aggregate_neighbors([
    ['name' => 'Ryder', 'similarity' => 0.6, 'colorway' => 'Black'],
    ['name' => 'Ryder', 'similarity' => 0.4, 'colorway' => 'Cream'],
]);
pr_check("a colorway above half the Name weight sticks",
         $cw_majority['colorway'] === 'Black');
$cw_sparse = pr_aggregate_neighbors([
    ['name' => 'Ryder', 'similarity' => 0.6, 'colorway' => ''],
    ['name' => 'Ryder', 'similarity' => 0.4, 'colorway' => 'Cream'],
]);
pr_check("an untagged majority keeps a tagged minority from winning colorway",
         $cw_sparse['name'] === 'Ryder' && $cw_sparse['colorway'] === '');
$cw_fold = pr_aggregate_neighbors([
    ['name' => 'Ryder', 'similarity' => 0.3, 'colorway' => 'matte black'],
    ['name' => 'Ryder', 'similarity' => 0.5, 'colorway' => 'Matte Black'],
]);
pr_check("folded colorway spellings vote as one and keep the heavier label",
         $cw_fold['colorway'] === 'Matte Black');
$cw_raw = pr_aggregate_neighbors([
    ['name' => 'Ryder', 'similarity' => 0.8, 'colorway' => 'Raw'],
    ['name' => 'Ryder', 'similarity' => 0.2, 'colorway' => 'Black'],
]);
pr_check("Raw, Media, and Photo cannot win a colorway vote",
         $cw_raw['colorway'] === ''
         && pr_aggregate_neighbors([
             ['name' => 'Ryder', 'similarity' => 0.9, 'colorway' => 'Media'],
             ['name' => 'Ryder', 'similarity' => 0.1, 'colorway' => 'Black'],
         ])['colorway'] === ''
         && pr_aggregate_neighbors([
             ['name' => 'Ryder', 'similarity' => 1.0, 'colorway' => 'Photo'],
         ])['colorway'] === '');
$cw_raw_minority = pr_aggregate_neighbors([
    ['name' => 'Ryder', 'similarity' => 0.4, 'colorway' => 'Raw'],
    ['name' => 'Ryder', 'similarity' => 0.6, 'colorway' => 'Black'],
]);
pr_check("a junk leaf stays in the denominator and a real majority still sticks",
         $cw_raw_minority['colorway'] === 'Black');
pr_check("a product name stored as a colorway cannot win",
         pr_aggregate_neighbors([
             ['name' => 'Ryder', 'similarity' => 0.9, 'colorway' => 'Loma'],
             ['name' => 'Ryder', 'similarity' => 0.1, 'colorway' => 'Cream'],
         ])['colorway'] === '');
$no_family = pr_aggregate_neighbors([
    ['name' => 'Loma', 'similarity' => 0.4],
    ['name' => 'Ryder', 'similarity' => 0.4],
    ['name' => 'El Camino', 'similarity' => 0.4],
]);
pr_check("a family share under 0.5 yields no suggestion",
         $no_family['suggest'] === false);
$loma_facets = pr_facets_from_identity(pr_identity_for_name('Loma S-Class'));
$xl_facets = pr_facets_from_identity(pr_identity_for_name('Loma XL S-Class'));
$r14_facets = pr_facets_from_identity([
    'family' => 'Ryder', 'name' => 'Ryder 14 S-Class', 'edition' => 'S-Class', 'clubtype' => '',
]);
pr_check("facets put S-Class on Loma, on Loma XL, and as sub-class 2 on Ryder 14",
         $loma_facets['family'] === 'Loma' && $loma_facets['subclass'] === 'S-Class' && $loma_facets['subclass2'] === ''
         && $xl_facets['family'] === 'Loma XL' && $xl_facets['subclass'] === 'S-Class' && $xl_facets['subclass2'] === ''
         && $r14_facets['subclass'] === '14' && $r14_facets['subclass2'] === 'S-Class');
$pacific_plan = pr_accept_identity_plan('Pacific');
pr_check("accepting Pacific fills shared category and leaves Name empty",
         $pacific_plan['family'] === 'Pacific'
         && $pacific_plan['productcategory'] === 'Golf Bags'
         && $pacific_plan['modelkeyword'] === '');
pr_check("reference labels stay at Name grain",
         pr_reference_label('LOMA') === 'Loma'
         && pr_reference_label('RYDER 14') === 'Ryder 14'
         && pr_reference_label('Loma XL') === 'Loma XL'
         && pr_reference_label('Ryder S-Class') === 'Ryder S-Class'
         && pr_reference_label('Ryder 14 S-Class') === 'Ryder 14 S-Class'
         && pr_reference_label('Ryder 14 S-Class') !== 'Ryder'
         && pr_reference_label('Ryder 14 S-Class') !== 'Ryder 14'
         && pr_reference_label('Pacific') === ''
         && pr_reference_label('RECESS 5-8') === ''
         && pr_reference_label('Sundaze Crushable') === ''
         && pr_reference_label('') === '');
$r14_label = pr_aggregate_neighbors([
    ['name' => 'Ryder 14 S-Class', 'similarity' => 0.9, 'colorway' => 'Black', 'resource' => 21],
    ['name' => 'Ryder', 'similarity' => 0.1, 'colorway' => 'Navy', 'resource' => 22],
]);
pr_check("Ryder 14 S-Class neighbors do not collapse onto Ryder",
         $r14_label['suggest'] === true
         && $r14_label['family'] === 'Ryder'
         && $r14_label['name'] === 'Ryder 14 S-Class'
         && $r14_label['subclass'] === '14'
         && $r14_label['subclass2'] === 'S-Class'
         && $r14_label['colorway'] === 'Black');
$family_ryder = pr_migrate_reference_decision('Ryder', true, ['Ryder S-Class']);
$family_split = pr_migrate_reference_decision('Ryder', true, ['Ryder', 'Ryder S-Class']);
$leaf_r14s = pr_migrate_reference_decision('Ryder 14 S-Class', false, []);
$leaf_disagree = pr_migrate_reference_decision('Ryder 14 S-Class', false, ['Ryder']);
$family_junk = pr_migrate_reference_decision('Ryder', true, ['Ryder', 'Not A Product']);
pr_check("migrate keeps a Family Ryder pin on the Name tag, and does not collapse Ryder 14 S-Class",
         $family_ryder === ['action' => 'retarget', 'label' => 'Ryder S-Class']
         && $family_split['action'] === 'orphan'
         && $leaf_r14s === ['action' => 'retarget', 'label' => 'Ryder 14 S-Class']
         && $leaf_disagree['action'] === 'orphan'
         && $family_junk['action'] === 'orphan');
pr_check("a Name-bound derived row is not relabeled back to Family",
         pr_sync_keeps_name_binding('relabel', true)
         && !pr_sync_keeps_name_binding('relabel', false)
         && !pr_sync_keeps_name_binding('add', true)
         && !pr_sync_keeps_name_binding('revive', true));
$r14_plan = pr_accept_identity_plan('Ryder 14 S-Class');
pr_check("accepting Ryder 14 S-Class writes Family Ryder and that Name, not Family Ryder alone",
         $r14_plan['family'] === 'Ryder'
         && $r14_plan['modelkeyword'] === 'Ryder 14 S-Class'
         && $r14_plan['edition'] === 'S-Class');
$beanie_plan = pr_accept_identity_plan('Beanie');
pr_check("accepting a one-name family writes that Name",
         $beanie_plan['family'] === 'Beanie'
         && $beanie_plan['modelkeyword'] === 'Beanie'
         && $beanie_plan['productcategory'] === 'Headwear');
pr_check("ignored branch words are not products",
         pr_accept_identity_plan('Golf Bags')['family'] === ''
         && pr_accept_identity_plan('Travel Bags')['family'] === ''
         && pr_is_ignored_product_label('Hats')
         && pr_is_ignored_product_label('Golf Accessories')
         && !pr_is_ignored_product_label('Towels')
         && !pr_is_ignored_product_label('Sunday Snow'));
pr_check("Ryder 15 is not collapsed into Ryder 14",
         pr_identity_for_name('Ryder 15') === null
         && pr_identity_for_name('Ryder 16') === null
         && pr_identity_for_name('Ryder 17') === null
         && pr_identity_for_name('Ryder 14')['name'] === 'Ryder 14');
pr_check("setup has no product field and names the Master Product List",
         !str_contains((string)file_get_contents(dirname(__DIR__) . '/pages/setup.php'),
                      'product_recognition_field')
         && str_contains((string)file_get_contents(dirname(__DIR__) . '/languages/en.php'),
                         'Master Product List'));
$pr_cli_src = (string)file_get_contents(__DIR__ . '/product_recognition_cli.php');
pr_check("CLI has --migrate-family",
         str_contains($pr_cli_src, '--migrate-family'));
pr_check("CLI help lists --migrate-family",
         str_contains($pr_cli_src, 'case \'--help\'')
         && str_contains($pr_cli_src, '--migrate-family|--all'));
$pr_id_src = (string)file_get_contents(dirname(__DIR__) . '/include/product_recognition_identity.php');
pr_check("migrate refuses to run without identity grammar",
         str_contains($pr_id_src, 'identity grammar not loaded')
         && str_contains($pr_id_src, 'Add-only Name stamp'));
pr_check("migrate retargets references to modelkeyword, not Family",
         str_contains($pr_id_src, "pr_field_ref_by_name('modelkeyword')")
         && str_contains($pr_id_src, 'pr_migrate_reference_decision')
         && !str_contains($pr_id_src, "pr_unique_folded_node(\$identity['family']"));
pr_check("pending leftover SQL is live derived/pinned refs, not setup field equality",
         str_contains($pr_id_src, 'n.resource_type_field <>')
         && str_contains($pr_id_src, "pr.status IN ('derived', 'pinned')")
         && !str_contains($pr_id_src, '$family_field === $stored_field')
         && str_contains($pr_id_src, 'pr_pending_tree_field(true)')
         && str_contains($pr_id_src, "\$row['status'] === 'derived'"));
$pr_sync_src = (string)file_get_contents(dirname(__DIR__) . '/include/product_recognition_functions.php');
$pr_fence = strpos($pr_sync_src, 'if (pr_pending_tree_field() <= 0)');
$pr_derived_del = strpos($pr_sync_src, "DELETE FROM product_reference WHERE resource = ? AND status = 'derived'");
pr_check("derived deletes are fenced while tree refs remain",
         $pr_fence !== false && $pr_derived_del !== false && $pr_fence < $pr_derived_del);
pr_check("sync does not revive a migrate orphan whose node still exists",
         str_contains($pr_sync_src, '$live_orphan_nodes')
         && str_contains($pr_sync_src, 'isset($live_orphan_nodes[(int)$current[\'model_node\']])'));
$pr_install_src = (string)file_get_contents(dirname(__DIR__) . '/install.txt');
$pr_dry = strpos($pr_install_src, '--migrate-family --dry-run');
$pr_setup_step = strpos($pr_install_src, 'Open the plugin setup page only after migrate');
pr_check("install.txt migrates before setup and does not tell you to pick Family first",
         $pr_dry !== false && $pr_setup_step !== false && $pr_dry < $pr_setup_step
         && str_contains($pr_install_src, 'Do not pick Family before migrate'));
$pr_hook_src = (string)file_get_contents(dirname(__DIR__) . '/hooks/all.php');
pr_check("extra_checks FAILs on leftover tree refs without setup equality",
         str_contains($pr_hook_src, 'pr_pending_tree_field() > 0')
         && !str_contains($pr_hook_src, 'pr_tree_refs_need_migrate'));
$pr_cat_src = (string)file_get_contents(dirname(__DIR__) . '/include/product_recognition_functions.php');
pr_check("PDF catalog ingest still calls pr_parse_sku_sheet",
         str_contains($pr_cat_src, 'function pr_parse_sku_sheet')
         && str_contains($pr_cat_src, 'function pr_sync_catalog'));
$pr_sheet_src = (string)file_get_contents(dirname(__DIR__) . '/include/product_recognition_sheet.php');
$pr_view_src = (string)file_get_contents(dirname(__DIR__) . '/hooks/view.php');
pr_check("sheet loader reads the Master Product List and the resource view does not",
         str_contains($pr_sheet_src, '1tqzCrB3sQ-bhSdnggF9nrvLv6V3N0uWfhli7jRQFv3M')
         && str_contains($pr_sheet_src, 'sheets.googleapis.com')
         && str_contains($pr_sheet_src, 'function pr_import_master_sheet')
         && str_contains($pr_sheet_src, 'function pr_studio_seed')
         && !str_contains($pr_cat_src, '1tqzCrB3sQ-bhSdnggF9nrvLv6V3N0uWfhli7jRQFv3M')
         && !str_contains($pr_view_src, 'pr_fetch_master_sheet')
         && !str_contains($pr_view_src, 'pr_import_master_sheet')
         && !str_contains($pr_view_src, 'pr_studio_seed'));
$pr_sheet_fail = pr_sheet_fixture_failure();
pr_check($pr_sheet_fail === '' ? 'sheet import and studio path fixtures' : $pr_sheet_fail,
         $pr_sheet_fail === '');
pr_check("name references wait while leftover tree refs remain",
         pr_studio_reference_decision(true, true, false) === 'hold'
         && pr_studio_reference_decision(false, true, false) === 'insert'
         && pr_studio_reference_decision(false, true, true) === 'skip'
         && pr_studio_reference_decision(true, false, false) === 'skip'
         && str_contains($pr_sheet_src, 'references_held')
         && str_contains($pr_sheet_src, 'dropbox_location table missing')
         && str_contains($pr_sheet_src, 'function pr_sheet_ensure_tables')
         && str_contains($pr_sheet_src, "db_begin_transaction('pr_sheet_replace')")
         && str_contains($pr_sheet_src, 'db_rollback_transaction'));
$pr_write_fn = strpos($pr_sheet_src, 'function pr_sheet_write_empty');
$pr_write_body = $pr_write_fn === false ? '' : substr($pr_sheet_src, $pr_write_fn, 2500);
$pr_write_dry = strpos($pr_write_body, 'if ($dry_run)');
$pr_write_flag = strpos($pr_write_body, '$wrote = true');
$pr_write_node = strpos($pr_write_body, 'pr_ensure_named_node');
$pr_write_flag_after = $pr_write_node === false ? false : strpos($pr_write_body, '$wrote = true', $pr_write_node);
pr_check("empty-field tag counts only after the node exists",
         $pr_write_dry !== false
         && $pr_write_flag !== false
         && $pr_write_node !== false
         && $pr_write_flag_after !== false
         && $pr_write_dry < $pr_write_flag
         && $pr_write_flag < $pr_write_node
         && $pr_write_node < $pr_write_flag_after);
pr_check("classify auto-apply requires a leaf of the bound field",
         str_contains($pr_id_src, 'function pr_classify_may_auto_apply')
         && str_contains($pr_id_src, 'pr_is_leaf_model($node)')
         && str_contains($pr_sync_src, 'pr_classify_may_auto_apply('));
pr_check("unattended 100% waits while leftover tree refs remain",
         str_contains($pr_id_src, 'if (pr_pending_tree_field() > 0)')
         && str_contains($pr_sync_src, 'if (pr_pending_tree_field() > 0)')
         && str_contains($pr_hook_src, 'product-recognition.timer')
         && !str_contains($pr_hook_src, 'pr_run_all('));
$pr_cli_msg = (string)file_get_contents(dirname(__DIR__) . '/scripts/product_recognition_cli.php');
pr_check("CLI does not tell you to configure a product field that setup no longer has",
         str_contains($pr_cli_msg, 'Name field is not available and no recognition field is bound')
         && !str_contains($pr_cli_msg, 'configure the product field'));
$pr_lang_now = (string)file_get_contents(dirname(__DIR__) . '/languages/en.php');
pr_check("setup copy matches the sheet credential and the fixed never-list",
         str_contains($pr_lang_now, 'Master Product List')
         && str_contains($pr_lang_now, 'only when a service-account JSON file is configured')
         && str_contains($pr_lang_now, 'The code default is product.')
         && str_contains($pr_lang_now, 'Twelve names are fixed in code.'));
pr_check("nightly comments include the sheet and the studio seed",
         str_contains($pr_sync_src, 'sheet import, studio seed')
         && str_contains($pr_hook_src, 'sheet import, studio seed')
         && !str_contains($pr_sync_src, 'One lock across all four'));
$pr_backfill_fn = strpos($pr_sync_src, 'function pr_backfill(');
$pr_backfill_body = $pr_backfill_fn === false ? '' : substr($pr_sync_src, $pr_backfill_fn, 4500);
$pr_started = strpos($pr_backfill_body, '$started_at = $resumed_from');
$pr_wrap = strpos($pr_backfill_body, '$wrapped = true');
$pr_loma_family = pr_find_field_plan('Loma', '', '', '', 'Loma');
$pr_loma_sclass = pr_find_field_plan('Loma', 'S-Class', '', 'Matte Black', '');
pr_check("family-only find keeps every product field and does not bind the base name",
         $pr_loma_family['family'] === 'Loma'
         && $pr_loma_family['productcategory'] === 'Golf Bags'
         && $pr_loma_family['modelkeyword'] === ''
         && $pr_loma_family['edition'] === ''
         && $pr_loma_sclass['modelkeyword'] === 'Loma S-Class'
         && $pr_loma_sclass['edition'] === 'S-Class'
         && $pr_loma_sclass['family'] === 'Loma'
         && $pr_loma_sclass['colorway'] === 'Matte Black'
         && str_contains($pr_sync_src, 'function pr_product_field_search'));
pr_check("backfill log keeps the pre-wrap cursor and names an empty slice",
         $pr_started !== false
         && $pr_wrap !== false
         && $pr_started < $pr_wrap
         && str_contains($pr_sync_src, 'function pr_backfill_stored_nothing')
         && str_contains($pr_sync_src, 'function pr_report_backfill_slice')
         && str_contains($pr_sync_src, 'skipped no preview')
         && str_contains($pr_sync_src, 'stored no vectors')
         && str_contains($pr_sync_src, 'Forward skips and failures wait until the cursor wraps.')
         && str_contains($pr_sync_src, 'pr_report_backfill_slice($backfill)')
         && str_contains($pr_cli_msg, 'pr_format_backfill_summary')
         && str_contains($pr_cli_msg, 'pr_report_backfill_slice')
         && str_contains($pr_cli_msg, 'tagged fills empty fields')
         && str_contains($pr_cli_msg, 'does not classify'));
