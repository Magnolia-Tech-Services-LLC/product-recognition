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
pr_check("accepting Loma fills Family and Product Category, leaves Name empty",
         $loma_plan['family'] === 'Loma'
         && $loma_plan['productcategory'] === 'Golf Bags'
         && $loma_plan['modelkeyword'] === ''
         && $loma_plan['edition'] === '');
$xl_plan = pr_accept_identity_plan('Loma XL');
pr_check("accepting Loma XL does not guess Name (family has S-Class variant)",
         $xl_plan['family'] === 'Loma XL'
         && $xl_plan['productcategory'] === 'Golf Bags'
         && $xl_plan['modelkeyword'] === '');
$sclass_plan = pr_accept_identity_plan('Loma S-Class');
pr_check("accepting Name Loma S-Class writes Family Loma, not a Family named Loma S-Class",
         $sclass_plan['family'] === 'Loma'
         && $sclass_plan['productcategory'] === 'Golf Bags'
         && $sclass_plan['modelkeyword'] === ''
         && $sclass_plan['edition'] === 'S-Class');
$pacific_plan = pr_accept_identity_plan('Pacific');
pr_check("accepting Pacific fills shared category and leaves Name empty",
         $pacific_plan['family'] === 'Pacific'
         && $pacific_plan['productcategory'] === 'Golf Bags'
         && $pacific_plan['modelkeyword'] === '');
pr_check("catalog model_text maps to Family, not a Name-grain leaf",
         pr_catalog_family_label('LOMA') === 'Loma'
         && pr_catalog_family_label('RYDER 14') === 'Ryder'
         && pr_catalog_family_label('Loma XL') === 'Loma XL'
         && pr_catalog_family_label('RECESS 5-8') === ''
         && pr_catalog_family_label('Sundaze Crushable') === ''
         && pr_catalog_family_label('') === '');
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
pr_check("setup allows Family dynamic keywords",
         str_contains((string)file_get_contents(dirname(__DIR__) . '/pages/setup.php'),
                      'FIELD_TYPE_DYNAMIC_KEYWORDS_LIST'));
$pr_cli_src = (string)file_get_contents(__DIR__ . '/product_recognition_cli.php');
pr_check("CLI has --migrate-family",
         str_contains($pr_cli_src, '--migrate-family'));
pr_check("CLI help lists --migrate-family",
         str_contains($pr_cli_src, 'case \'--help\'')
         && str_contains($pr_cli_src, '--migrate-family|--all'));
$pr_id_src = (string)file_get_contents(dirname(__DIR__) . '/include/product_recognition_identity.php');
pr_check("migrate refuses to run without identity grammar",
         str_contains($pr_id_src, 'identity grammar not loaded')
         && str_contains($pr_id_src, 'Add-only Family stamp'));
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
pr_check("this change does not load the Google Sheet",
         !str_contains($pr_cat_src, '1tqzCrB3sQ-bhSdnggF9nrvLv6V3N0uWfhli7jRQFv3M')
         && !str_contains($pr_cat_src, 'spreadsheets.google.com'));
