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

global $product_recognition_field;

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
SKU      North Bag                     UPC CODE
BAG101   NORTH-Matte Black             100000000001              A lightweight stand bag
BAG129   NORTH-Olive/Yellow            100000000002              A lightweight stand bag
SKU      South 23 Stand Bag            UPC CODE                  Description
BAG401   SOUTH 23 STAND BAG - Matte Black   100000000003         Lightweight stand bag
SKU      Towels                        UPC                       DESCRIPTION
TWL101   Stars and Striped             100000000004              Microfiber waffle towel
SKU      PRODUCT                       UPC CODE       Case QTY   Description (<55 Char)
SKU      North Bag                     UPC CODE
BAG109   NORTH-Sunset                  100000000005              A lightweight stand bag
SKU      Hats                          UPC
HAT101   Ridge Crushable | BLACK RIDGE   100000000006            Crushable rope hat | Icon | Black
HAT123   Example Cap I BLACK           100000000007              Example Cap I BLACK
HAT140-712  Example Pro Fit Hat | NAVY  100000000008                 7 - 1/2 Example Pro Fit Hat | NAVY
SKU      Cooler Bag | Frost Box        UPC
CLBAG101  Cooler Bag | Frost Box | BLACK    100000000009         Cooler Bag | BLACK
ICEBRICK  Ice Brick | Cooler Pack      100000000010
SKU      Rainhood                      UPC                       Description
RH201    North / XL Rainhood | Black | Magnum                    Rainhood | BLACK
SKU      Double Straps                 UPC CODE
STRP101  Double Straps - Premium Backpack Straps
SHEET;

$parsed = pr_parse_sku_sheet($fixture);

pr_check("fixture parses every row and no header (" . count($parsed) . " of 12)",
         count($parsed) === 12);

# The master header repeats on page two and must not open a section called PRODUCT, and the
# second page's North Bag rows must land in the section the first page opened, not in limbo.
pr_check("master header never becomes a section",
         !in_array('PRODUCT', array_column($parsed, 'section'), true));
pr_check("page two rows keep their section",
         ($parsed['BAG109']['section'] ?? '') === 'North Bag');
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
         isset($parsed['ICEBRICK']) && $parsed['ICEBRICK']['upc'] === '100000000010'
         && $parsed['ICEBRICK']['description'] === null);

# The three separators, and the multi-part rows that decide split-on-last over split-on-first.
$splits = [
    'BAG101'   => ['NORTH', 'Matte Black'],
    'BAG129'   => ['NORTH', 'Olive/Yellow'],
    'BAG401'   => ['SOUTH 23 STAND BAG', 'Matte Black'],
    'HAT101'   => ['Ridge Crushable', 'BLACK RIDGE'],
    'HAT123'   => ['Example Cap', 'BLACK'],
    'CLBAG101' => ['Cooler Bag | Frost Box', 'BLACK'],
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
$pr_leaves = [6820 => 'North', 6842 => 'North XL', 6767 => 'South', 6799 => 'Headcover'];
$pr_cways = [1 => 'Matte Black', 2 => 'Olive', 3 => 'Cobalt Blue'];

pr_check("fold matches NORTH to North without matching North XL",
         pr_fold_label('NORTH') === 'north'
         && pr_fold_label('North') === 'north'
         && pr_fold_label('North XL') === 'north xl'
         && pr_fold_label('SOUTH 23 STAND BAG') === 'south 23 stand bag');

pr_check("NORTH binds to North, not North XL",
         pr_bind_catalog_model('NORTH', $pr_leaves) === 6820);
pr_check("NORTH XL binds to North XL",
         pr_bind_catalog_model('NORTH XL', $pr_leaves) === 6842);
pr_check("unknown sheet model is unbound",
         pr_bind_catalog_model('Ridge Crushable', $pr_leaves) === null);
pr_check("empty model text is unbound",
         pr_bind_catalog_model('', $pr_leaves) === null);
pr_check("empty sheet model uses section",
         pr_catalog_model_text(['model_text' => '', 'section' => 'Towels']) === 'Towels');
pr_check("non-empty sheet model wins over section",
         pr_catalog_model_text(['model_text' => 'NORTH', 'section' => 'North Bag']) === 'NORTH');

pr_check("Matte Black binds",
         pr_bind_catalog_colorway('Matte Black', $pr_cways) === 1);
pr_check("Olive/Yellow stays unbound (slash)",
         pr_bind_catalog_colorway('Olive/Yellow', $pr_cways) === null);
pr_check("Olive without slash binds",
         pr_bind_catalog_colorway('Olive', $pr_cways) === 2);

$pr_untagged = pr_catalog_apply_plan([], 6820, 1, []);
pr_check("untagged resource adds model, pins, adds colorway",
         $pr_untagged === ['add_model' => true, 'skip_model' => false, 'pin' => true, 'add_colorway' => true]);

$pr_same = pr_catalog_apply_plan([6820], 6820, 1, [1]);
pr_check("same model already present still pins, does not re-add colorway",
         $pr_same === ['add_model' => true, 'skip_model' => false, 'pin' => true, 'add_colorway' => false]);

$pr_path = pr_catalog_apply_plan([6767], 6820, 1, []);
pr_check("path-derived different model is kept; colorway still added",
         $pr_path === ['add_model' => false, 'skip_model' => true, 'pin' => false, 'add_colorway' => true]);

$pr_unbound = pr_catalog_apply_plan([], null, null, []);
pr_check("unbound pick writes nothing",
         $pr_unbound === ['add_model' => false, 'skip_model' => true, 'pin' => false, 'add_colorway' => false]);

$pr_two = pr_catalog_apply_plan([6820, 6767], 6799, 1, []);
pr_check("two existing leaves still skip a third model",
         $pr_two['skip_model'] === true && $pr_two['pin'] === false && $pr_two['add_colorway'] === true);

$pr_dual = pr_catalog_apply_plan([6820, 6767], 6820, 1, []);
pr_check("dual-tagged resource is not pinned for either leaf",
         $pr_dual['skip_model'] === true && $pr_dual['pin'] === false);

pr_check("!list token is colon-separated",
         pr_other_match_search_token([1334, 1335, 1336]) === '!list1334:1335:1336');
pr_check("empty match list is not a bare !list",
         pr_other_match_search_token([]) === '');
pr_check("zero and negative refs drop out of !list",
         pr_other_match_search_token([0, -1, 9]) === '!list9');

$pr_pick_cat = [
    ['sku' => 'BAG101', 'model_text' => 'NORTH', 'colorway_text' => 'Matte Black'],
    ['sku' => 'BAG102', 'model_text' => 'NORTH', 'colorway_text' => 'Cobalt Blue'],
    ['sku' => 'BAG201', 'model_text' => 'SOUTH', 'colorway_text' => 'Matte Black'],
];
pr_check("preselect hits model+colorway SKU",
         pr_picker_preselect('North', 'Cobalt Blue', $pr_pick_cat)
         === ['sku' => 'BAG102', 'model_text' => 'NORTH', 'colorway_text' => 'Cobalt Blue']);
pr_check("preselect falls back to first SKU of the model",
         pr_picker_preselect('North', '', $pr_pick_cat)
         === ['sku' => 'BAG101', 'model_text' => 'NORTH', 'colorway_text' => 'Matte Black']);
pr_check("preselect misses unknown model",
         pr_picker_preselect('Patches', 'Red', $pr_pick_cat)
         === ['sku' => '', 'model_text' => '', 'colorway_text' => '']);

pr_check("posted model+colorway resolves SKU",
         pr_catalog_sku_for_pick('NORTH', 'Cobalt Blue', $pr_pick_cat) === 'BAG102');
pr_check("empty model is nothing to apply",
         pr_catalog_sku_for_pick('', 'Matte Black', $pr_pick_cat) === '');
pr_check("mismatched colorway resolves nothing",
         pr_catalog_sku_for_pick('NORTH', 'Red', $pr_pick_cat) === '');

# --- never auto-apply list (#464): parse + unique leaf bind, no DB --------------------
$pr_never_raw = "North XL, Cargo S-Class, South 14";
$pr_never_src = (string)file_get_contents(dirname(__DIR__) . '/config/config.php');
pr_check("config.php default never-auto-apply list is empty",
         (bool)preg_match(
             '/\$product_recognition_never_auto_apply\s*=\s*""\s*;/',
             $pr_never_src
         ));
pr_check("sample never-auto-apply list folds to 3 names",
         pr_never_auto_apply_names($pr_never_raw) === ['north xl', 'cargo s class', 'south 14']);

$pr_leaves = [6820 => 'North', 6842 => 'North XL', 6767 => 'South', 6800 => 'South 14',
              6900 => 'Cargo S-Class', 6910 => 'South 15'];
pr_check("never-auto-apply nodes follow list order and skip misses",
         pr_never_auto_apply_nodes($pr_leaves, $pr_never_raw) === [6842, 6900, 6800]);
pr_check("North unique-matches the parent node, not North XL",
         pr_never_auto_apply_nodes($pr_leaves, "North") === [6820]);
pr_check("empty never-auto-apply list matches no nodes",
         pr_never_auto_apply_nodes($pr_leaves, "") === []);
pr_check("ambiguous folded leaves are skipped",
         pr_never_auto_apply_nodes([1 => 'North XL', 2 => 'North-XL'], $pr_never_raw) === []);
pr_check("unmatched names are the folded misses",
         pr_never_auto_apply_unmatched($pr_leaves, "North XL, unknown product") === ['unknown product']);

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

# --- disagreement outcome, every kind (no DB, no /identify) ------------------------------
# pr_classify() never sees a tagged resource, so this comparison has to be its own function
# and these checks cannot go through the service. The report counts outcome['kind']
# directly; do not re-test a second if-chain.

$pr_leaves = [10 => 'North', 20 => 'South'];
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
         && $pr_row['tagged_name'] === 'North' && $pr_row['recognised_name'] === 'South'
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

pr_check("tagged leaf CTE has two placeholders",
         substr_count(pr_tagged_leaf_cte(), '?') === 2);

$GLOBALS['product_recognition_field'] = $GLOBALS['product_recognition_field'] ?? 88;
[$pr_sql0] = pr_tagged_leaf_with_vector_sql(0);
pr_check("limit 0 does not emit LIMIT",
         substr_count($pr_sql0, '?') === 2 && stripos($pr_sql0, 'LIMIT') === false);
[$pr_sql5, $pr_p5] = pr_tagged_leaf_with_vector_sql(5);
pr_check("limit 5 binds LIMIT ?",
         substr_count($pr_sql5, '?') === 3 && end($pr_p5) === 5);

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
    ], [42 => 'West']);
    pr_check('identify result carries winning model name', $ident['model_name'] === 'West');
    pr_check('colorway lookup uses the winning model\'s nearest reference',
             $ident['top_reference'] === 57);
    $tagged = pr_identity_from_identify_result([
        'candidates' => [
            ['model_node' => 1, 'confidence' => 0.50, 'top_reference' => 10],
            ['model_node' => 42, 'confidence' => 0.30, 'top_reference' => 57],
        ],
        'top_reference' => 10,
    ], [1 => 'East', 42 => 'West'], ['West']);
    pr_check('tagged model beats a higher-vote rival for colorway',
             $tagged['model_name'] === 'West' && $tagged['top_reference'] === 57);
    pr_check('tagged model absent from the vote stays empty',
             pr_identity_from_identify_result([
                 'candidates' => [[
                     'model_node' => 42,
                     'confidence' => 0.81,
                     'top_reference' => 57,
                 ]],
             ], [42 => 'West'], ['South'])['model_name'] === '');
    pr_check('null identify is an empty identity',
             pr_identity_from_identify_result(null)['top_reference'] === null);
    pr_check('identify cache sits next to the mask PNG',
             str_ends_with(pr_identify_cache_file(3022, 'abc', 1), '3022_abc_1.identify.json'));

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
    @unlink($studio2);
    @unlink($cut);
} else {
    printf("%-4s %s\n", 'skip', 'bbox trim (GD not loaded)');
}

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
