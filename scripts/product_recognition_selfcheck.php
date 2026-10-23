<?php
/**
 * Assert-based self-check for the product recognition logic. No framework - this repo has
 * none, and the house idiom is a script that verifies itself against the database.
 *
 *   docker compose exec -T resourcespace php \
 *     /var/www/html/plugins/product_recognition/scripts/product_recognition_selfcheck.php
 *
 * Structural invariants only, plus fixed maths. Asserting on live counts would be brittle -
 * the CLI's --report mode prints those for a person to compare against the spec.
 *
 * One carve-out, at the end of the file: the orphaned-model checks assert a count is zero. That
 * is a referential-integrity invariant rather than a live volume, but unlike the rest it CAN be
 * transiently false - between a product node being deleted and the next --references pass. It is
 * placed last for that reason; see the note there.
 */
include_once "/var/www/html/include/boot.php";
include_once "/var/www/html/plugins/product_recognition/include/product_recognition_functions.php";
command_line_only();

$product_recognition_field = pr_product_field();

function pr_check(string $label, bool $ok): void
{
    printf("%-4s %s\n", $ok ? "ok" : "FAIL", $label);
    if (!$ok) {
        exit(1);
    }
}

# --- the field this plugin is tagged against ----------------------------------------------
# An admin picks the field on the plugin setup page. Unconfigured (0) or a missing ref
# would make every later join return zero rows and the structural assertions pass vacuously.

pr_check(
    "product field is configured and exists",
    pr_is_configured()
);

pr_check(
    "embedding table resource_dinov2_vector exists",
    (int)ps_value(
        "SELECT COUNT(*) AS value FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        array("s", "resource_dinov2_vector"),
        0
    ) === 1
);

# --- the reference rule -------------------------------------------------------------------

$rows = pr_reference_rows();
pr_check("reference set is non-empty", count($rows) > 0);

$by_resource = [];
foreach ($rows as $r) {
    $by_resource[(int)$r['resource']] = (int)$r['model_node'];
}
pr_check("one model per reference resource", count($by_resource) === count($rows));

# Every reference's node must be a leaf of the field.
$leaves = pr_leaf_models();
$non_leaf = array_filter($by_resource, fn($n) => !isset($leaves[$n]));
pr_check("every reference node is a leaf of the field (" . count($non_leaf) . " violations)",
         $non_leaf === []);

# Every reference's node must be the resource's MOST SPECIFIC tag: no other node on that
# resource may sit below it. This is the condition that recovers the parent+leaf resources.
$sample = array_slice($by_resource, 0, 200, true);
$bad = [];
foreach ($sample as $resource => $node) {
    $descendants = ps_value(
        "WITH RECURSIVE closure AS (
             SELECT ref AS ancestor, ref AS descendant FROM node WHERE ref = ?
             UNION ALL
             SELECT c.ancestor, n.ref FROM closure c JOIN node n ON n.parent = c.descendant
         )
         SELECT COUNT(*) AS value FROM resource_node rn
           JOIN closure c ON c.descendant = rn.node
          WHERE rn.resource = ? AND rn.node <> ?",
        array("i", $node, "i", $resource, "i", $node), 0
    );
    if ((int)$descendants > 0) {
        $bad[] = $resource;
    }
}
pr_check("no reference node has a descendant on its own resource (" . count($bad) . " violations)",
         $bad === []);

# The parent+leaf shape must actually be included - the whole point of the rule.
# COUNT=2 is the wrong proxy: sibling leaf pairs share that bucket. The check uses
# ancestor-of (one of the two nodes is an ancestor of the other).
$count2 = (int)ps_value("SELECT COUNT(*) AS value FROM (
     SELECT rn.resource FROM resource_node rn
       JOIN node nd ON nd.ref = rn.node AND nd.resource_type_field = ?
     GROUP BY rn.resource HAVING COUNT(*) = 2
 ) t", array("i", $product_recognition_field), 0);
$parent_ids = pr_parent_and_leaf_resources();
$parent_and_leaf = count($parent_ids);
$covered = 0;
foreach ($parent_ids as $resource) {
    if (isset($by_resource[$resource])) {
        $covered++;
    }
}
printf("     COUNT=2 (includes siblings): %d; true parent+leaf: %d, of which references: %d\n",
       $count2, $parent_and_leaf, $covered);
pr_check("parent+leaf resources are references, not discarded",
         $parent_and_leaf === 0 || $covered > 0.9 * $parent_and_leaf);

# --- idempotency --------------------------------------------------------------------------

$first = pr_sync_references(true);
pr_check("dry-run sync reports a derived set", $first['derived'] > 0);

# --- Wilson bound -------------------------------------------------------------------------
# The two numbers the spec's argument rests on.
pr_check("wilson(20,20) is 83.9%, so 20-of-20 does NOT clear a 95% target",
         abs(pr_wilson_lower(20, 20) - 0.8389) < 0.001);
pr_check("wilson(73,73) reaches 95%, the spec's 'about 73 observations'",
         abs(pr_wilson_lower(73, 73) - 0.9500) < 0.001);
pr_check("wilson(0,0) is 0", pr_wilson_lower(0, 0) === 0.0);
pr_check("wilson is monotonic in n for a clean record",
         pr_wilson_lower(50, 50) > pr_wilson_lower(20, 20));
pr_check("one error lowers the bound",
         pr_wilson_lower(72, 73) < pr_wilson_lower(73, 73));

# --- reference floor ----------------------------------------------------------------------
pr_check("floor at 0.95 is 20", pr_min_references(0.95) === 20);
pr_check("floor at 0.99 is 100", pr_min_references(0.99) === 100);
pr_check("floor at 0.90 is 10", pr_min_references(0.90) === 10);

# --- threshold sweep ----------------------------------------------------------------------
# 80 clean observations from 1.0 down to 0.3: the bound clears 95% well before the bottom, so
# the threshold lands low but not at zero.
$clean = [];
for ($i = 0; $i < 80; $i++) {
    $clean[] = ['confidence' => 1.0 - $i * 0.008, 'correct' => true];
}
$t = pr_threshold_for_model($clean, 0.95);
pr_check("a clean 80-observation record produces a threshold", $t !== null);
pr_check("that threshold uses every observation it can", $t['sample_size'] >= 73);

# A record that is wrong at every confidence never qualifies.
$dirty = [];
for ($i = 0; $i < 80; $i++) {
    $dirty[] = ['confidence' => 1.0 - $i * 0.008, 'correct' => ($i % 3 !== 0)];
}
pr_check("a 67%-correct record produces no threshold at a 95% target",
         pr_threshold_for_model($dirty, 0.95) === null);

# Errors only at LOW confidence must still allow a HIGH threshold - the whole point.
# The cutoff has to leave a clean prefix long enough to be MEASURABLE at the target: at 0.95
# that means >= 73 observations, so a cutoff of 0.75 (only 50 clean, Wilson 0.9286) asks for
# behaviour no correct implementation could produce. 0.6 leaves 80 clean, Wilson 0.9542.
$mixed = [];
for ($i = 0; $i < 100; $i++) {
    $conf = 1.0 - $i * 0.005;
    $mixed[] = ['confidence' => $conf, 'correct' => $conf > 0.6];
}
$t = pr_threshold_for_model($mixed, 0.95);
pr_check("errors below 0.6 still permit a threshold above it",
         $t !== null && $t['min_confidence'] > 0.6);

pr_check("too few observations produce nothing", pr_threshold_for_model([
    ['confidence' => 0.9, 'correct' => true],
    ['confidence' => 0.8, 'correct' => true],
], 0.95) === null);

# --- catalogue ingest (#429) ---------------------------------------------------------------
# The parser reads a pdftotext -layout rendering, so its input is a formatting contract with
# poppler, not with us. The fixture pins the shapes the real sheet actually contains; the live
# check below catches a poppler or sheet change that silently reduces the yield.

$fixture = <<<'SHEET'
SKU      PRODUCT                       UPC CODE       Case QTY   Description (<55 Char)
SKU      Loma Bag                      UPC CODE
BAG101   LOMA-Matte Black              759687567473              A lightweight 2-way top, 6 club stand bag
BAG129   LOMA-Friar Brown/Yellow       735203473954              A lightweight 2-way top, 6 club stand bag
SKU      Ryder 23' Stand Bag           UPC CODE                  Description
BAG401   RYDER 23' STAND BAG - Matte Black   735203274728        Lightweight 5-way top, 14 club Stand Bag
SKU      Towels                        UPC                       DESCRIPTION
TWL101   Stars and Striped             759687690195              Microfiber Waffle golf towel
SKU      PRODUCT                       UPC CODE       Case QTY   Description (<55 Char)
SKU      Loma Bag                      UPC CODE
BAG109   LOMA-Sunset                   735203111111              A lightweight 2-way top, 6 club stand bag
SKU      Hats                          UPC
HAT101   Sundaze Crushable | BLACK SUNDAZE   657768314919        Crushable Rope Hat | Sunday Icon | Black
HAT123   Sunday Golf Dad Hat I BLACK   735203248118              Sunday Golf Dad Hat I BLACK
HAT140-712  Sunday Pro Fit Hat | NAVY  735203051541                 7 - 1/2 Sunday Pro Fit Hat | NAVY
SKU      Cooler Bag | Big Frosty       UPC
CLBAG101  Cooler Bag | Big Frosty | BLACK    657768237331        Cooler Bag | BLACK
ICEBRICK  Ice Brick | Cooler Pack      735203171577
SKU      Rainhood                      UPC                       Description
RH201    Loma / XL Rainhood | Black | Magnum                     Rainhood | BLACK
SKU      Double Straps                 UPC CODE
STRP101  Double Straps - Premium Backpack Straps
SHEET;

$parsed = pr_parse_sku_sheet($fixture);

pr_check("fixture parses every row and no header (" . count($parsed) . " of 12)",
         count($parsed) === 12);

# The master header repeats on page two and must not open a section called PRODUCT, and the
# second page's Loma Bag rows must land in the section the first page opened, not in limbo.
pr_check("master header never becomes a section",
         !in_array('PRODUCT', array_column($parsed, 'section'), true));
pr_check("page two rows keep their section",
         ($parsed['BAG109']['section'] ?? '') === 'Loma Bag');
pr_check("section count is 7 (" . count(array_unique(array_column($parsed, 'section'))) . ")",
         count(array_unique(array_column($parsed, 'section'))) === 7);

# A SKU need not contain digits, a UPC is sometimes absent, and a description often is.
pr_check("non-numeric SKU is read", isset($parsed['ICEBRICK']));
pr_check("size-suffixed SKU keeps its suffix", isset($parsed['HAT140-712']));
# `?? ` cannot be used to test these: it returns the default for a null value as well as for a
# missing key, so `$row['upc'] ?? 'x'` is 'x' exactly when the assertion wants to see null.
pr_check("absent UPC reads as null, not as the description",
         isset($parsed['RH201']) && $parsed['RH201']['upc'] === null
         && $parsed['RH201']['description'] === 'Rainhood | BLACK');
pr_check("row with neither UPC nor description parses",
         isset($parsed['STRP101']) && $parsed['STRP101']['upc'] === null
         && $parsed['STRP101']['description'] === null);
pr_check("UPC is not mistaken for a description",
         isset($parsed['ICEBRICK']) && $parsed['ICEBRICK']['upc'] === '735203171577'
         && $parsed['ICEBRICK']['description'] === null);

# The three separators, and the multi-part rows that decide split-on-last over split-on-first.
$splits = [
    'BAG101'   => ['LOMA', 'Matte Black'],
    'BAG129'   => ['LOMA', 'Friar Brown/Yellow'],
    'BAG401'   => ["RYDER 23' STAND BAG", 'Matte Black'],
    'HAT101'   => ['Sundaze Crushable', 'BLACK SUNDAZE'],
    'HAT123'   => ['Sunday Golf Dad Hat', 'BLACK'],
    'CLBAG101' => ['Cooler Bag | Big Frosty', 'BLACK'],
    'TWL101'   => ['', 'Stars and Striped'],   # no separator: the section is the model
];
foreach ($splits as $sku => $want) {
    pr_check("$sku splits to [{$want[0]}] [{$want[1]}]",
             ($parsed[$sku]['model_text'] ?? null) === $want[0]
             && ($parsed[$sku]['colorway_text'] ?? null) === $want[1]);
}

# Live: the sheet in the DAM must still yield at least what the catalogue already holds.
# pr_sync_catalog never deletes, so the stored count only grows - a parse that comes back
# smaller means poppler's -layout output or the sheet itself changed shape underneath us,
# which is the failure that would otherwise look like "nothing to update".
$stored = (int)ps_value("SELECT COUNT(*) AS value FROM product_catalog", array(), 0);
if ($stored > 0) {
    $source = pr_catalog_source();
    $yield = ($source === null) ? 0 : count($source['rows']);
    pr_check("live SKU sheet still parses to >= the $stored stored rows (got $yield)",
             $yield >= $stored);
} else {
    printf("%-4s %s\n", "skip", "live sheet yield (product_catalog is empty - run --catalog)");
}

# --- catalog pickers (#458): bind + add-only plan, no DB, no /identify --------------------
$pr_leaves = [6820 => 'Loma', 6842 => 'Loma XL', 6767 => 'Ryder', 6799 => 'Headcover'];
$pr_cways = [1 => 'Matte Black', 2 => 'Friar Brown', 3 => 'Cobalt Blue'];

pr_check("fold matches LOMA to Loma without matching Loma XL",
         pr_fold_label('LOMA') === 'loma'
         && pr_fold_label('Loma') === 'loma'
         && pr_fold_label('Loma XL') === 'loma xl'
         && pr_fold_label("RYDER 23' STAND BAG") === 'ryder 23 stand bag');

pr_check("LOMA binds to Loma, not Loma XL",
         pr_bind_catalog_model('LOMA', $pr_leaves) === 6820);
pr_check("LOMA XL binds to Loma XL",
         pr_bind_catalog_model('LOMA XL', $pr_leaves) === 6842);
pr_check("unknown sheet model is unbound",
         pr_bind_catalog_model('Sundaze Crushable', $pr_leaves) === null);
pr_check("empty model text is unbound",
         pr_bind_catalog_model('', $pr_leaves) === null);
pr_check("plugin does not ship a second identity grammar",
         !is_file(dirname(__DIR__) . '/include/product-category-map.php')
         && !is_file(dirname(__DIR__) . '/product-category-map.php'));
pr_check("empty sheet model uses section",
         pr_catalog_model_text(['model_text' => '', 'section' => 'Towels']) === 'Towels');
pr_check("non-empty sheet model wins over section",
         pr_catalog_model_text(['model_text' => 'LOMA', 'section' => 'Loma Bag']) === 'LOMA');

pr_check("Matte Black binds",
         pr_bind_catalog_colorway('Matte Black', $pr_cways) === 1);
pr_check("Friar Brown/Yellow stays unbound (slash)",
         pr_bind_catalog_colorway('Friar Brown/Yellow', $pr_cways) === null);
pr_check("Friar Brown without slash binds",
         pr_bind_catalog_colorway('Friar Brown', $pr_cways) === 2);

$pr_untagged = pr_catalog_apply_plan([], 6820, 1, [], null, [], 1);
pr_check("untagged resource with one cutout adds model without auto-pin",
         $pr_untagged === ['add_model' => true, 'replace_model' => false, 'skip_model' => false,
             'pin' => false, 'add_colorway' => true, 'replace_colorway' => false,
             'add_collab' => false, 'replace_collab' => false]);

$pr_same = pr_catalog_apply_plan([6820], 6820, 1, [1], null, [], 1);
pr_check("same model already present does not auto-pin, does not re-add colorway",
         $pr_same === ['add_model' => true, 'replace_model' => false, 'skip_model' => false,
             'pin' => false, 'add_colorway' => false, 'replace_colorway' => false,
             'add_collab' => false, 'replace_collab' => false]);

$pr_path = pr_catalog_apply_plan([6767], 6820, 1, [], null, [], 1);
pr_check("a different model is replaced; colorway is added; pin stays a button",
         $pr_path === ['add_model' => false, 'replace_model' => true, 'skip_model' => false,
             'pin' => false, 'add_colorway' => true, 'replace_colorway' => false,
             'add_collab' => false, 'replace_collab' => false]);

$pr_unbound = pr_catalog_apply_plan([], null, null, []);
pr_check("unbound pick writes nothing",
         $pr_unbound === ['add_model' => false, 'replace_model' => false, 'skip_model' => true,
             'pin' => false, 'add_colorway' => false, 'replace_colorway' => false,
             'add_collab' => false, 'replace_collab' => false]);

$pr_two = pr_catalog_apply_plan([6820, 6767], 6799, 1, [2]);
pr_check("two existing leaves are replaced by the chosen model",
         $pr_two['replace_model'] === true && $pr_two['pin'] === false
         && $pr_two['replace_colorway'] === true && $pr_two['add_colorway'] === false);

$pr_dual = pr_catalog_apply_plan([6820, 6767], 6820, 1, []);
pr_check("explicit apply of one of two leaves drops the other",
         $pr_dual['replace_model'] === true && $pr_dual['pin'] === false && $pr_dual['skip_model'] === false);

$pr_collab_plan = pr_catalog_apply_plan([], null, null, [], 99, []);
pr_check("collab-only pick adds collab",
         $pr_collab_plan['add_collab'] === true && $pr_collab_plan['add_model'] === false);

$pr_multi = pr_catalog_apply_plan([], 6820, 1, [], null, [], 2);
pr_check("two stored regions tag model without pin",
         $pr_multi['add_model'] === true && $pr_multi['pin'] === false);

$pr_fallback_plan = pr_catalog_apply_plan([], 6820, 1, [], null, [], 0);
pr_check("fallback with no stored region tags without pin",
         $pr_fallback_plan['add_model'] === true && $pr_fallback_plan['pin'] === false);

pr_check("operator pin needs a positive leaf model",
         pr_operator_pin(0, 6820, 1) === 'failed');
pr_check("operator exclude needs a resource",
         pr_operator_exclude(0, 1) === 'failed');
pr_check("operator unpin needs a resource",
         pr_operator_unpin(0, 1) === 'failed');

$pr_class_mossy = pr_classify_sheet_color('Mossy Oak', 'Ryder_Mossy_Oak');
pr_check("Mossy Oak sheet color is collab not colorway",
         $pr_class_mossy['collab'] === 'Mossy Oak' && $pr_class_mossy['colorway'] === '');
$pr_class_strap = pr_classify_sheet_color('Universal Backpack Straps', 'Double Straps');
pr_check("strap kit sheet color is junk",
         $pr_class_strap['junk'] === true && $pr_class_strap['colorway'] === '');
$pr_class_matte = pr_classify_sheet_color('Matte Black', 'LOMA-Matte Black');
pr_check("Matte Black sheet color stays colorway",
         $pr_class_matte['colorway'] === 'Matte Black' && $pr_class_matte['collab'] === '');
$pr_class_dsg = pr_classify_sheet_color('Americana (White) (DSG ONLY)', 'LOMA');
pr_check("DSG ONLY stripped from colorway display",
         $pr_class_dsg['colorway'] === 'Americana (White)');
$pr_class_masters = pr_classify_sheet_color('Camo Green/Yellow', 'Masters Camo - Camo Green/Yellow');
pr_check("Masters Camo product yields Masters collab; slash color skipped",
         $pr_class_masters['collab'] === 'Masters' && $pr_class_masters['colorway'] === '');

pr_check("!list token is colon-separated",
         pr_other_match_search_token([1334, 1335, 1336]) === '!list1334:1335:1336');
pr_check("empty match list is not a bare !list",
         pr_other_match_search_token([]) === '');
pr_check("zero and negative refs drop out of !list",
         pr_other_match_search_token([0, -1, 9]) === '!list9');

$pr_pick_cat = [
    ['sku' => 'BAG101', 'model_text' => 'LOMA', 'colorway_text' => 'Matte Black'],
    ['sku' => 'BAG102', 'model_text' => 'LOMA', 'colorway_text' => 'Cobalt Blue'],
    ['sku' => 'BAG201', 'model_text' => 'RYDER', 'colorway_text' => 'Matte Black'],
];
pr_check("preselect hits model+colorway SKU",
         pr_picker_preselect('Loma', 'Cobalt Blue', $pr_pick_cat)
         === ['sku' => 'BAG102', 'model_text' => 'LOMA', 'colorway_text' => 'Cobalt Blue']);
pr_check("preselect does not guess a colour when the model has several",
         pr_picker_preselect('Loma', '', $pr_pick_cat)
         === ['sku' => '', 'model_text' => 'LOMA', 'colorway_text' => '']);
pr_check("preselect fills colour only when the model has one SKU colour",
         pr_picker_preselect('Ryder', '', $pr_pick_cat)
         === ['sku' => 'BAG201', 'model_text' => 'RYDER', 'colorway_text' => 'Matte Black']);
pr_check("preselect keeps a tagged colorway that is not a sheet row",
         pr_picker_preselect('Loma', 'Mindy Lemon', $pr_pick_cat)
         === ['sku' => '', 'model_text' => 'LOMA', 'colorway_text' => 'Mindy Lemon']);
pr_check("preselect picks Ryder from a dual-tagged comma string",
         pr_picker_preselect('Ryder, Ryder S-Class', 'Pink', $pr_pick_cat)
         === ['sku' => '', 'model_text' => 'RYDER', 'colorway_text' => 'Pink']);
pr_check("primary tagged model skips unbound dual tags",
         pr_primary_tagged_model(['Ryder S-Class', 'Ryder'], $pr_pick_cat) === 'Ryder');
pr_check("preselect misses unknown model",
         pr_picker_preselect('Patches', 'Red', $pr_pick_cat)
         === ['sku' => '', 'model_text' => '', 'colorway_text' => '']);
pr_check("several colour names collapse to blank",
         pr_unique_or_blank_colorway(['Matte Black', 'Cobalt Blue']) === '');
pr_check("one colour name is kept",
         pr_unique_or_blank_colorway(['Matte Black', 'matte-black']) === 'Matte Black');
pr_check("catalog with several colours never infers from a Matte Black reference",
         pr_infer_colorway(['Matte Black', 'Cobalt Blue'], ['Matte Black']) === '');
pr_check("catalog with one colour fills that colour",
         pr_infer_colorway(['Matte Black'], ['Cobalt Blue']) === 'Matte Black');
pr_check("no catalog row falls back to a unique reference colour",
         pr_infer_colorway([], ['Matte Black']) === 'Matte Black');
pr_check("Matte Black swatch is a dark colour",
         ($pr_mb = pr_colorway_swatch('Matte Black')) !== null && $pr_mb[0] < 40);
pr_check("slash colorway has no swatch",
         pr_colorway_swatch('Friar Brown/Yellow') === null);
pr_check("Sunset has no swatch (not a colour word)",
         pr_colorway_swatch('Sunset') === null);
pr_check("second pass picks Cobalt Blue from a blue cutout",
         pr_pick_named_colorway([0, 70, 170], ['Matte Black', 'Cobalt Blue']) === 'Cobalt Blue');
pr_check("second pass picks Matte Black from a black cutout",
         pr_pick_named_colorway([20, 20, 20], ['Matte Black', 'Cobalt Blue']) === 'Matte Black');
pr_check("two black names stay blank",
         pr_pick_named_colorway([20, 20, 20], ['Matte Black', 'BLACK']) === '');
pr_check("grey between navy and black stays blank",
         pr_pick_named_colorway([80, 80, 90], ['Matte Black', 'Navy']) === '');

pr_check("posted model+colorway resolves SKU",
         pr_catalog_sku_for_pick('LOMA', 'Cobalt Blue', $pr_pick_cat) === 'BAG102');
pr_check("empty model is nothing to apply",
         pr_catalog_sku_for_pick('', 'Matte Black', $pr_pick_cat) === '');
pr_check("mismatched colorway resolves nothing",
         pr_catalog_sku_for_pick('LOMA', 'Red', $pr_pick_cat) === '');

# --- review queue floor + pick (#504): parse + plan, no DB --------------------
pr_check("empty min-confidence is any",
         pr_review_min_confidence('') === 0.0
         && pr_review_min_confidence(null) === 0.0);
pr_check("percent 70 is 0.70, not 70",
         (int)round(100 * pr_review_min_confidence('70')) === 70);
pr_check("non-numeric and negative floors are any",
         pr_review_min_confidence('abc') === 0.0
         && pr_review_min_confidence('-10') === 0.0);
pr_check("percent 75 snaps down to the 70 floor",
         (int)round(100 * pr_review_min_confidence('75')) === 70);
pr_check("percent above 100 snaps to the 90 floor",
         (int)round(100 * pr_review_min_confidence('150')) === 90);
pr_check("floor keeps an exact-threshold row",
         pr_suggestions_at_or_above(
             [['confidence' => 0.4], ['confidence' => 0.7], ['confidence' => 0.8]],
             0.7
         ) === [['confidence' => 0.7], ['confidence' => 0.8]]);
pr_check("untagged pick with no matching suggestion tags",
         pr_review_pick_plan(null, [], 6820) === 'tag');
pr_check("untagged pick of an open suggestion accepts it",
         pr_review_pick_plan(12, [], 6820) === 'accept');
pr_check("path-derived other model is kept",
         pr_review_pick_plan(null, [6767], 6820) === 'keep');
pr_check("same leaf already present still tags (pin path)",
         pr_review_pick_plan(null, [6820], 6820) === 'tag');
pr_check("blank model pick writes nothing",
         pr_review_pick_plan(null, [], 0) === 'none');

$pr_lang_src = (string)file_get_contents(dirname(__DIR__) . '/languages/en.php');
$pr_setup_src = (string)file_get_contents(dirname(__DIR__) . '/pages/setup.php');
pr_check("setup intro tells the operator to leave defaults",
         str_contains($pr_lang_src, 'product_recognition_setup_intro_defaults')
         && str_contains($pr_setup_src, 'product_recognition_setup_intro_defaults'));
pr_check("API key tooltip says leave blank",
         str_contains($pr_lang_src, 'Leave blank. Only needed if the sidecar is published outside Docker'));
pr_check("setup intro does not claim every label has a hover tooltip",
         !str_contains($pr_lang_src, 'Hover a label for what it does')
         && str_contains($pr_lang_src, 'Text fields have a hover description'));
$pr_setup_form_src = (string)file_get_contents(dirname(__DIR__) . '/include/product_recognition_setup.php');
pr_check("auto-apply help is visible HTML, not a boolean title",
         str_contains($pr_setup_form_src, 'config_add_html')
         && str_contains($pr_setup_form_src, 'product_recognition_setup_auto_apply_help'));
include_once dirname(__DIR__) . '/include/product_recognition_setup.php';
$pr_setup_built = pr_setup_page_def();
$pr_setup_post = strpos($pr_setup_src, 'config_gen_setup_post($page_def, $plugin_name)');
$pr_setup_never_fn = strpos($pr_setup_src, 'pr_never_auto_apply_default()');
$pr_setup_def_call = strpos($pr_setup_src, 'pr_setup_page_def()');
pr_check("service URL stays outside Advanced and the knobs stay on the form",
         pr_setup_advanced_inputs_inside_details($pr_setup_built['page_def'])
         && $pr_setup_def_call !== false
         && $pr_setup_post !== false
         && $pr_setup_never_fn !== false
         && $pr_setup_def_call < $pr_setup_post
         && $pr_setup_post < $pr_setup_never_fn
         && str_contains($pr_setup_src, 'product_recognition_setup_never_list_label')
         && str_contains($pr_setup_src, 'product_recognition_setup_never_list_fixed')
         && str_contains($pr_setup_src, '$page_def[$never_list_at]'));

# --- never auto-apply list (#464): parse + unique leaf bind, no DB --------------------
$pr_never_grown = pr_never_auto_apply_default();
$pr_never_raw = "Loma XL, Big Rig S-Class, Ryder 14";
$pr_never_src = (string)file_get_contents(dirname(__DIR__) . '/config/config.php');
pr_check("config.php default never-auto-apply list is the grown default",
         str_contains(
             $pr_never_src,
             '$product_recognition_never_auto_apply = "' . $pr_never_grown . '";'
         )
         && str_contains($pr_never_grown, 'Loma XL, Big Rig S-Class, Ryder 14')
         && str_contains($pr_never_grown, 'Loma Lite')
         && str_contains($pr_never_grown, 'El Camino Superlite')
         && str_contains($pr_never_grown, 'Ryder Lefty')
         && str_contains($pr_never_grown, 'Storm Ryder')
         && str_contains($pr_never_grown, 'Loma S-Class')
         && str_contains($pr_never_grown, 'Loma XL S-Class')
         && str_contains($pr_never_grown, 'El Camino S-Class')
         && str_contains($pr_never_grown, 'Ryder S-Class')
         && str_contains($pr_never_grown, 'Ryder 14 S-Class'));
pr_check("grown never-auto-apply default folds to 12 names",
         pr_never_auto_apply_names($pr_never_grown) === [
             'loma xl', 'big rig s class', 'ryder 14', 'loma lite', 'el camino superlite',
             'ryder lefty', 'storm ryder', 'loma s class', 'loma xl s class',
             'el camino s class', 'ryder s class', 'ryder 14 s class',
         ]);
pr_check("three-name never-auto-apply list still folds to 3 names",
         pr_never_auto_apply_names($pr_never_raw) === ['loma xl', 'big rig s class', 'ryder 14']);

$pr_family_leaves = [6820 => 'Loma', 6842 => 'Loma XL', 6767 => 'Ryder',
                     6901 => 'Big Rig', 6911 => 'El Camino', 6912 => 'Loma Lite'];
pr_check("never-auto-apply does not bind Ryder 14 or Big Rig S-Class onto the Family leaf",
         pr_load_identity_grammar()
         && pr_never_auto_apply_nodes($pr_family_leaves, $pr_never_raw) === [6842]
         && pr_never_auto_apply_unmatched($pr_family_leaves, $pr_never_raw) === [
             'big rig s class', 'ryder 14',
         ]);
pr_check("Loma unique-matches the parent node, not Loma XL",
         pr_never_auto_apply_nodes($pr_family_leaves, "Loma") === [6820]);
pr_check("empty never-auto-apply list matches no nodes",
         pr_never_auto_apply_nodes($pr_family_leaves, "") === []);
pr_check("ambiguous folded leaves are skipped",
         pr_never_auto_apply_nodes([1 => 'Loma XL', 2 => 'Loma-XL'], $pr_never_raw) === []);
pr_check("unmatched names are the folded misses",
         pr_never_auto_apply_unmatched($pr_family_leaves, "Loma XL, unknown product") === ['unknown product']);
pr_check("grown never-auto-apply misses stay visible against family leaves",
         in_array('ryder 16', pr_never_auto_apply_unmatched(
             $pr_family_leaves,
             $pr_never_grown . ', Ryder 16'
         ), true));
pr_check("grown never-auto-apply list binds Name leaves that are also families",
         pr_never_auto_apply_nodes($pr_family_leaves, $pr_never_grown) === [
             6842, 6912,
         ]);
$pr_name_leaves = [
    4 => 'Ryder', 5 => 'Ryder 14', 6 => 'Ryder S-Class', 7 => 'Ryder 14 S-Class',
    8 => 'Big Rig', 9 => 'Big Rig S-Class',
];
pr_check("never-auto-apply binds Ryder 14 and Ryder 14 S-Class on their own Name nodes",
         pr_never_auto_apply_nodes($pr_name_leaves, "Ryder 14, Ryder 14 S-Class, Ryder S-Class") === [5, 7, 6]
         && pr_never_auto_apply_nodes($pr_name_leaves, "Ryder 14") === [5]);

pr_check("auto-apply setup flag stays false",
         (bool)$GLOBALS['product_recognition_auto_apply'] === false);
$pr_ok_threshold = ['status' => 'ok', 'min_confidence' => 0.8, 'never_auto_apply' => 0];
pr_check("review page 100% is confidence rounded like the page",
         pr_confidence_displays_as_100(1.0)
         && pr_confidence_displays_as_100(0.995)
         && !pr_confidence_displays_as_100(0.994));
pr_check("review 100% auto-applies while the flag is off",
         pr_classify_should_auto_apply(false, 0.8, 1.0, $pr_ok_threshold, false, false) === true
         && pr_classify_should_auto_apply(false, 0.8, 0.995, null, false, false) === true);
pr_check("99% does not auto-apply while the flag is off",
         pr_classify_should_auto_apply(false, 1.0, 0.994, $pr_ok_threshold, false, false) === false);
pr_check("100% match auto-applies when the flag is on",
         pr_classify_should_auto_apply(true, 1.0, 1.0, null, false, false) === true);
pr_check("100% on the never-auto-apply list still refuses",
         pr_classify_should_auto_apply(false, 1.0, 1.0, $pr_ok_threshold, false, true) === false
         && pr_classify_should_auto_apply(false, 1.0, 1.0, ['status' => 'ok', 'min_confidence' => 0.8, 'never_auto_apply' => 1], false, false) === false);
pr_check("below 100% the never-auto-apply list still refuses",
         pr_classify_should_auto_apply(true, 0.9, 0.9, $pr_ok_threshold, false, true) === false);
pr_check("a prior rejection refuses even at 100% on the never-auto-apply list",
         pr_classify_should_auto_apply(false, 1.0, 1.0, $pr_ok_threshold, true, true) === false);
pr_check("flag on still needs threshold when similarity is not 100%",
         pr_classify_should_auto_apply(true, 0.9, 0.85, $pr_ok_threshold, false, false) === true
         && pr_classify_should_auto_apply(true, 0.9, 0.7, $pr_ok_threshold, false, false) === false);
pr_check("prior rejection never auto-applies",
         pr_classify_should_auto_apply(true, 1.0, 1.0, $pr_ok_threshold, true, false) === false);

# --- the reference decision, every branch ------------------------------------------------
# Pure and DB-free, and the reason it was extracted: this decision is where a dry run and a live
# run once disagreed, classifying the same stale pin as a CONFLICT in preview and a REVIVAL for
# real. It takes no $dry_run argument now, so the two cannot diverge again.

pr_check("no existing row means add",
         pr_reference_action(null, 5) === 'add');
pr_check("a pinned row is the admin's, not ours",
         pr_reference_action(['model_node' => 5, 'status' => 'pinned'], 9) === 'defer');
pr_check("an excluded row is the admin's, not ours",
         pr_reference_action(['model_node' => 5, 'status' => 'excluded'], 9) === 'defer');
pr_check("an orphaned row whose resource is tagged again revives",
         pr_reference_action(['model_node' => 5, 'status' => 'orphaned'], 9) === 'revive');
pr_check("an orphaned row revives even to the same node it was pinned to",
         pr_reference_action(['model_node' => 5, 'status' => 'orphaned'], 5) === 'revive');
pr_check("a derived row under a new model is relabelled",
         pr_reference_action(['model_node' => 5, 'status' => 'derived'], 9) === 'relabel');
pr_check("a derived row under the same model is left alone",
         pr_reference_action(['model_node' => 5, 'status' => 'derived'], 5) === 'keep');

pr_check("pin with no row adds",
         pr_pin_action(null, 5) === 'add');
pr_check("pin leaves excluded alone",
         pr_pin_action(['model_node' => 5, 'status' => 'excluded'], 9) === 'defer');
pr_check("pin keeps a same-model pin",
         pr_pin_action(['model_node' => 5, 'status' => 'pinned'], 5) === 'keep');
pr_check("pin does not retarget a different-model pin",
         pr_pin_action(['model_node' => 5, 'status' => 'pinned'], 9) === 'defer');
pr_check("pin upgrades derived to the named model",
         pr_pin_action(['model_node' => 5, 'status' => 'derived'], 9) === 'upgrade');
pr_check("pin upgrades orphaned to the named model",
         pr_pin_action(['model_node' => 5, 'status' => 'orphaned'], 9) === 'upgrade');
pr_check("cron backs off 20 hours after a successful pass",
         pr_cron_interval_seconds() === 20 * 3600);

$pr_review_src = (string)file_get_contents(dirname(__DIR__) . '/pages/review.php');
pr_check("review picker ships the leaf list once as JSON",
         str_contains($pr_review_src, 'id="pr-leaf-json"')
         && str_contains($pr_review_src, 'select.pr-review-leaf'));

# --- disagreement outcome, every kind (no DB, no /identify) ------------------------------
# pr_classify() never sees a tagged resource, so this comparison has to be its own function
# and these checks cannot go through the service. The report counts outcome['kind']
# directly; do not re-test a second if-chain.

$pr_leaves = [10 => 'Loma', 20 => 'Ryder'];
$pr_thresholds = [20 => ['wilson_lower' => 0.95]];
$pr_min = 0.5;
$pr_agree = [
    'candidates' => [['model_node' => 10, 'confidence' => 0.9, 'top_reference' => 5]],
    'top_similarity' => 0.8,
    'top_reference' => 5,
];
$pr_differ = [
    'candidates' => [['model_node' => 20, 'confidence' => 0.81, 'top_reference' => 57]],
    'top_similarity' => 0.7,
    'top_reference' => 99,
];

$pr_ok_agree = pr_disagreement_outcome(1, 10, $pr_agree, $pr_leaves, $pr_thresholds, $pr_min);
pr_check("agreement kind", $pr_ok_agree['kind'] === 'agreed' && $pr_ok_agree['row'] === null);

$pr_ok_diff = pr_disagreement_outcome(1, 10, $pr_differ, $pr_leaves, $pr_thresholds, $pr_min);
$pr_row = $pr_ok_diff['row'];
pr_check("disagreement kind", $pr_ok_diff['kind'] === 'disagreed');
pr_check("disagreement names both models",
         $pr_row !== null && $pr_row['tagged_node'] === 10 && $pr_row['recognised_node'] === 20
         && $pr_row['tagged_name'] === 'Loma' && $pr_row['recognised_name'] === 'Ryder'
         && $pr_row['resource'] === 1);
pr_check("disagreement carries wilson from the recognised model's threshold",
         $pr_row !== null && $pr_row['wilson_lower'] === 0.95);
pr_check("disagreement uses the candidate's own top_reference, not the query's",
         $pr_row !== null && $pr_row['top_reference'] === 57);
pr_check("disagreement confidence and similarity are rank-1",
         $pr_row !== null && $pr_row['confidence'] === 0.81 && $pr_row['top_similarity'] === 0.7);

pr_check("empty candidates is no_candidates",
         pr_disagreement_outcome(1, 10, ['candidates' => [], 'top_similarity' => 0.9],
                                 $pr_leaves, $pr_thresholds, $pr_min)['kind'] === 'no_candidates');
pr_check("null identify is no_candidates (report throws before this on a down sidecar)",
         pr_disagreement_outcome(1, 10, null, $pr_leaves, $pr_thresholds, $pr_min)['kind'] === 'no_candidates');
pr_check("non-array candidates is no_candidates",
         pr_disagreement_outcome(1, 10, ['candidates' => 'x', 'top_similarity' => 0.9],
                                 $pr_leaves, $pr_thresholds, $pr_min)['kind'] === 'no_candidates');
pr_check("non-array rank-1 is service_empty",
         pr_disagreement_outcome(1, 10, ['candidates' => ['x'], 'top_similarity' => 0.9],
                                 $pr_leaves, $pr_thresholds, $pr_min)['kind'] === 'service_empty');

$pr_low = [
    'candidates' => [['model_node' => 20, 'confidence' => 0.9, 'top_reference' => 57]],
    'top_similarity' => 0.4,
];
pr_check("below min similarity is skipped_low_similarity even when nodes differ",
         pr_disagreement_outcome(1, 10, $pr_low, $pr_leaves, $pr_thresholds, $pr_min)['kind']
         === 'skipped_low_similarity');

$pr_branch = [
    'candidates' => [['model_node' => 99, 'confidence' => 0.9, 'top_reference' => 57]],
    'top_similarity' => 0.8,
];
pr_check("recognised non-leaf is service_empty",
         pr_disagreement_outcome(1, 10, $pr_branch, $pr_leaves, $pr_thresholds, $pr_min)['kind']
         === 'service_empty');

$pr_nowilson = pr_disagreement_outcome(1, 10, $pr_differ, $pr_leaves, [], $pr_min)['row'];
pr_check("missing threshold yields null wilson_lower",
         $pr_nowilson !== null && $pr_nowilson['wilson_lower'] === null);
pr_check("empty-string wilson_lower is null",
         pr_disagreement_outcome(1, 10, $pr_differ, $pr_leaves, [20 => ['wilson_lower' => '']], $pr_min)['row']['wilson_lower'] === null);

$pr_fallback = pr_disagreement_outcome(1, 15, $pr_differ, $pr_leaves, $pr_thresholds, $pr_min)['row'];
pr_check("tagged name falls back to node N when missing from leaves",
         $pr_fallback !== null && $pr_fallback['tagged_name'] === 'node 15');

$pr_tsv = pr_disagreement_tsv_fields($pr_nowilson);
pr_check("TSV prints - for null wilson", $pr_tsv[6] === '-');
pr_check("TSV formats confidence to three decimals", $pr_tsv[5] === '0.810');
$pr_nullref = pr_disagreement_outcome(
    1, 10,
    ['candidates' => [['model_node' => 20, 'confidence' => 0.81]], 'top_similarity' => 0.7],
    $pr_leaves, $pr_thresholds, $pr_min
)['row'];
pr_check("TSV prints - for null top_reference",
         $pr_nullref !== null && pr_disagreement_tsv_fields($pr_nullref)[8] === '-');

$pr_cte_ph = pr_product_field_is_flat() ? 1 : 2;
pr_check("tagged leaf CTE has $pr_cte_ph field placeholder(s)",
         substr_count(pr_tagged_leaf_cte(), '?') === $pr_cte_ph);

$GLOBALS['product_recognition_field'] = $GLOBALS['product_recognition_field'] ?? 88;
[$pr_sql0] = pr_tagged_leaf_with_vector_sql(0);
pr_check("limit 0 does not emit LIMIT",
         substr_count($pr_sql0, '?') === $pr_cte_ph && stripos($pr_sql0, 'LIMIT') === false);
[$pr_sql5, $pr_p5] = pr_tagged_leaf_with_vector_sql(5);
pr_check("limit 5 binds LIMIT ?",
         substr_count($pr_sql5, '?') === $pr_cte_ph + 1 && end($pr_p5) === 5);

# --- studio trim for the view-page crop (no DB) ------------------------------------------
# The faces-style panel must not render the whole frame. A white canvas with a coloured
# rectangle is the ecomm case; a four-colour canvas is a full-bleed frame (no crop).

if (function_exists('imagecreatetruecolor')) {
    $tmp = sys_get_temp_dir();
    $studio = $tmp . '/pr_bbox_studio.jpg';
    $im = imagecreatetruecolor(200, 200);
    $white = imagecolorallocate($im, 255, 255, 255);
    $red = imagecolorallocate($im, 200, 20, 20);
    imagefilledrectangle($im, 0, 0, 199, 199, $white);
    imagefilledrectangle($im, 50, 40, 89, 99, $red);
    imagejpeg($im, $studio, 95);
    $box = pr_bbox_from_image($studio);
    @unlink($studio);
    pr_check('studio trim returns a box', $box !== null);
    pr_check('studio box covers the product, not the left margin', $box !== null && $box[0] <= 0.25 && $box[2] >= 0.45);
    pr_check('studio box is not the whole frame',
             $box !== null && ($box[2] - $box[0]) * ($box[3] - $box[1]) < 0.50);

    $bleed = $tmp . '/pr_bbox_bleed.jpg';
    $im = imagecreatetruecolor(200, 200);
    imagefilledrectangle($im, 0, 0, 99, 99, imagecolorallocate($im, 200, 20, 20));
    imagefilledrectangle($im, 100, 0, 199, 99, imagecolorallocate($im, 20, 200, 20));
    imagefilledrectangle($im, 0, 100, 99, 199, imagecolorallocate($im, 20, 20, 200));
    imagefilledrectangle($im, 100, 100, 199, 199, imagecolorallocate($im, 200, 200, 20));
    imagejpeg($im, $bleed, 95);
    $nobox = pr_bbox_from_image($bleed);
    @unlink($bleed);
    pr_check('full-bleed frame returns no box (would be the whole image)', $nobox === null);

    $pair = $tmp . '/pr_bbox_two.jpg';
    $im = imagecreatetruecolor(200, 200);
    imagefilledrectangle($im, 0, 0, 199, 199, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 15, 30, 55, 140, imagecolorallocate($im, 200, 20, 20));
    imagefilledrectangle($im, 140, 30, 185, 140, imagecolorallocate($im, 20, 20, 200));
    imagejpeg($im, $pair, 95);
    $two = pr_bboxes_from_image($pair);
    @unlink($pair);
    pr_check('two products on white become two boxes', count($two) === 2);

    $split = $tmp . '/pr_bbox_split.jpg';
    $im = imagecreatetruecolor(200, 200);
    imagefilledrectangle($im, 0, 0, 199, 199, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 40, 30, 90, 150, imagecolorallocate($im, 200, 20, 20));
    imagefilledrectangle($im, 95, 50, 120, 90, imagecolorallocate($im, 200, 20, 20));
    imagejpeg($im, $split, 95);
    $joined = pr_bboxes_from_image($split);
    @unlink($split);
    pr_check('nearby fragments of one product become one box', count($joined) === 1);

    pr_check('overlapping padded boxes coalesce',
             count(pr_coalesce_boxes([[0.2, 0.2, 0.6, 0.8], [0.45, 0.3, 0.7, 0.7]])) === 1);
    pr_check('far-apart boxes stay two products',
             count(pr_coalesce_boxes([[0.05, 0.15, 0.3, 0.7], [0.7, 0.15, 0.95, 0.7]])) === 2);
    pr_check('resource 350 lifestyle fragments coalesce to one product',
             count(pr_coalesce_boxes([[0.5583, 0.6722, 0.7111, 0.8667], [0.6556, 0.6167, 0.8306, 0.8481]])) === 1);
    $padded = pr_expand_boxes([[0.5583, 0.6167, 0.8306, 0.8667]], 0.08);
    pr_check('expand pads a tight lifestyle box',
             $padded[0][0] < 0.5583 && $padded[0][2] > 0.8306
             && ($padded[0][2] - $padded[0][0]) * ($padded[0][3] - $padded[0][1]) > 0.15);
    pr_check('bare checksum is the same file as a stamped region',
             pr_region_is_current_file('abc', 'abc')
             && pr_region_is_current_file('abc:cw4', 'abc')
             && !pr_region_is_current_file('def:cw4', 'abc'));
    pr_check('three overlapping segment fragments become two products after coalesce',
             count(pr_coalesce_boxes([
                 [0.3272, 0.4389, 0.4951, 0.663],
                 [0.5877, 0.3296, 0.7407, 0.8204],
                 [0.6617, 0.3407, 0.821, 0.8204],
             ])) === 2);
    # 35062: four /segment fragments of two adjacent bags — pad-touch used to glue all four.
    pr_check('adjacent bag fragment pairs stay two products',
             count(pr_coalesce_boxes([
                 [0.2296, 0.2352, 0.4481, 0.8796],
                 [0.3537, 0.2296, 0.5333, 0.8204],
                 [0.5148, 0.2278, 0.6685, 0.8167],
                 [0.6056, 0.25, 0.7833, 0.8685],
             ])) === 2);
    pr_check('resource 356 two-bag lifestyle stays two products',
             count(pr_coalesce_boxes([
                 [0.5077, 0.2496, 0.901, 0.9004],
                 [0.2472, 0.3589, 0.5751, 0.743],
             ])) === 2);

    $two_json = '[[0.3,0.1852,0.6167,0.8148],[0.525,0.1981,0.7917,0.8222]]';
    $decoded_two = pr_decode_bboxes($two_json);
    pr_check('two lifestyle boxes decode from JSON', count($decoded_two) === 2);
    pr_check('two-box JSON fits the widened bbox column', strlen($two_json) < 1024);
    pr_check('empty bbox string is no regions', pr_decode_bboxes('') === []);

    pr_check('png cutout posts as image/png', pr_upload_mime('/tmp/cutout.png') === 'image/png');
    pr_check('jpeg preview posts as image/jpeg', pr_upload_mime('/tmp/pre.jpg') === 'image/jpeg');
    $ident = pr_identity_from_identify_result([
        'candidates' => [[
            'model_node' => 42,
            'confidence' => 0.81,
            'top_reference' => 57,
        ]],
        'top_reference' => 99,
    ], [42 => 'El Camino']);
    pr_check('identify result carries winning model name', $ident['model_name'] === 'El Camino');
    pr_check('identify result keeps the winning model\'s nearest reference id',
             $ident['top_reference'] === 57);
    pr_check('identify result does not copy a colour from the nearest reference',
             $ident['colorway'] === '');
    $tagged = pr_identity_from_identify_result([
        'candidates' => [
            ['model_node' => 1, 'confidence' => 0.50, 'top_reference' => 10],
            ['model_node' => 42, 'confidence' => 0.30, 'top_reference' => 57],
        ],
        'top_reference' => 10,
    ], [1 => 'Cruiser', 42 => 'El Camino'], ['El Camino']);
    pr_check('tagged model beats a higher-vote rival for colorway',
             $tagged['model_name'] === 'El Camino' && $tagged['top_reference'] === 57);
    $tagged_cw = pr_identity_from_identify_result([
        'candidates' => [
            ['model_node' => 1, 'confidence' => 0.90, 'top_reference' => 10],
            ['model_node' => 42, 'confidence' => 0.30, 'top_reference' => 57],
        ],
        'neighbors' => [
            ['model_node' => 42, 'similarity' => 0.7, 'resource' => 1, 'colorway' => 'Cream'],
            ['model_node' => 42, 'similarity' => 0.2, 'resource' => 2, 'colorway' => 'Black'],
            ['model_node' => 1, 'similarity' => 0.9, 'resource' => 3, 'colorway' => 'Navy'],
        ],
    ], [1 => 'Cruiser', 42 => 'El Camino'], ['El Camino']);
    pr_check('a tagged model votes colorway from its own neighbors',
             $tagged_cw['model_name'] === 'El Camino'
             && $tagged_cw['colorway'] === 'Cream'
             && $tagged_cw['top_reference'] === 57);
    pr_check('tagged model absent from the vote stays empty',
             pr_identity_from_identify_result([
                 'candidates' => [[
                     'model_node' => 42,
                     'confidence' => 0.81,
                     'top_reference' => 57,
                 ]],
             ], [42 => 'El Camino'], ['Ryder'])['model_name'] === '');
    pr_check('null identify is an empty identity',
             pr_identity_from_identify_result(null)['top_reference'] === null);
    pr_check('identify cache sits next to the mask PNG',
             str_ends_with(pr_identify_cache_file(3022, 'abc', 1), '3022_abc_1.identify.json'));

    # Fallback picker row shape (no DB): region 0 + fallback flag when boxes are absent.
    # Live path is pr_view_product_rows; here we only assert the expand helper still pads.

    $cut = $tmp . '/pr_bbox_mask.png';
    $studio2 = $tmp . '/pr_bbox_studio2.jpg';
    $im = imagecreatetruecolor(200, 200);
    imagefilledrectangle($im, 0, 0, 199, 199, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 50, 40, 89, 99, imagecolorallocate($im, 200, 20, 20));
    imagejpeg($im, $studio2, 95);
    $box2 = pr_bbox_from_image($studio2);
    $wrote = $box2 !== null && pr_write_product_mask($studio2, $box2, $cut);
    pr_check('studio trim writes a mask PNG', $wrote && is_file($cut));
    if ($wrote) {
        $png = imagecreatefrompng($cut);
        imagesavealpha($png, true);
        $pw = imagesx($png);
        $ph = imagesy($png);
        $tl = imagecolorat($png, 0, 0);
        $mid = imagecolorat($png, (int)($pw / 2), (int)($ph / 2));
        pr_check('mask top-left is transparent (background punched out)',
                 (($tl >> 24) & 0x7F) >= 64);
        pr_check('mask centre is opaque product pixels',
                 (($mid >> 24) & 0x7F) === 0);
    }
    $bleed_crop = $tmp . '/pr_bbox_crop.png';
    $bleed2 = $tmp . '/pr_bbox_bleed2.jpg';
    $im = imagecreatetruecolor(200, 200);
    imagefilledrectangle($im, 0, 0, 99, 99, imagecolorallocate($im, 200, 20, 20));
    imagefilledrectangle($im, 100, 0, 199, 99, imagecolorallocate($im, 20, 200, 20));
    imagefilledrectangle($im, 0, 100, 99, 199, imagecolorallocate($im, 20, 20, 200));
    imagefilledrectangle($im, 100, 100, 199, 199, imagecolorallocate($im, 200, 200, 20));
    imagejpeg($im, $bleed2, 95);
    pr_check('lifestyle frame has no studio box', pr_bbox_from_image($bleed2) === null);
    $cropped = pr_write_product_mask($bleed2, [0.1, 0.1, 0.4, 0.4], $bleed_crop);
    pr_check('lifestyle crop still writes a PNG', $cropped && is_file($bleed_crop));
    @unlink($bleed2);
    @unlink($bleed_crop);
    @unlink($studio2);
    @unlink($cut);
} else {
    printf("%-4s %s\n", 'skip', 'bbox trim (GD not loaded)');
}

require __DIR__ . '/product_recognition_selfcheck_identity.php';

# --- no live row points at a deleted product node -----------------------------------------
# The failure this guards is silent by construction: pr_open_suggestions() INNER JOINs `node`,
# so an orphaned suggestion leaves the review queue without erroring. pr_sync_references() calls
# pr_reconcile_vocabulary() on every run, so a non-zero count here means either that has not run
# since the vocabulary changed, or something is creating rows it does not cover.
#
# Last, deliberately: this is the one condition here that is expected to be transient - it
# self-heals on the next --references pass - and pr_check() exits on the first failure. Placed
# earlier, a node deleted an hour ago would abort the run before the leaf rule, the
# most-specific-tag rule and the threshold maths were checked at all.
#
# The three predicates below deliberately restate pr_reconcile_vocabulary()'s rather than calling
# it. A check fed by the function it is checking cannot fail when that function's predicate is
# wrong - it would report zero orphans and pass green while real ones sat in the table. The cost
# of the duplication is drift: widen the reconciler's coverage and these must be widened too.

foreach ([
    'product_threshold has no rows for deleted nodes'
        => "SELECT COUNT(*) AS value FROM product_threshold t
             WHERE NOT EXISTS (SELECT 1 FROM node n WHERE n.ref = t.model_node)",
    'no pinned reference points at a deleted node'
        => "SELECT COUNT(*) AS value FROM product_reference r WHERE r.status = 'pinned'
             AND NOT EXISTS (SELECT 1 FROM node n WHERE n.ref = r.model_node)",
    'no open suggestion points at a deleted node'
        => "SELECT COUNT(*) AS value FROM product_suggestion s WHERE s.state = 'open'
             AND NOT EXISTS (SELECT 1 FROM node n WHERE n.ref = s.model_node)",
] as $label => $sql) {
    $n = (int)ps_value($sql, [], 0);
    pr_check("$label ($n found)", $n === 0);
}

echo "\nall checks passed\n";
