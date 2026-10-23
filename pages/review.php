<?php
include "../../../include/boot.php";
include "../../../include/authenticate.php";
include_once dirname(__FILE__, 2) . "/include/product_recognition_functions.php";

if (!checkperm('product_recognition_accept')) {
    exit($lang["error-permissiondenied"]);
}

$action = getval('action', '');
$suggestion = (int)getval('suggestion', 0);
$min_confidence = pr_review_min_confidence(getval('min_confidence', ''));
$min_percent = (int)round($min_confidence * 100);
$floors = pr_review_confidence_floors();

if ($action !== '' && enforcePostRequest(false)) {
    $status = 'failed';
    if ($action === 'accept') {
        $status = pr_accept_suggestion($suggestion, $userref);
    } elseif ($action === 'reject') {
        $status = pr_reject_suggestion($suggestion, $userref);
    } elseif ($action === 'pick') {
        $status = pr_apply_review_pick(
            (int)getval('resource', 0),
            (int)getval('model_node', 0),
            (int)$userref
        );
    }
    $from = getval('from', '');
    $resource = (int)getval('resource', 0);
    if ($from === 'view' && $resource > 0) {
        $view_params = [
            'ref' => $resource,
            'pr_review' => $status,
        ];
        if (getval('modal', '') === 'true') {
            $view_params['modal'] = 'true';
        }
        redirect(generateURL($baseurl . '/pages/view.php', $view_params));
    }
    $review_params = ['pr_review' => $status];
    if ($min_percent > 0) {
        $review_params['min_confidence'] = (string)$min_percent;
    }
    redirect(generateURL($baseurl . '/plugins/product_recognition/pages/review.php', $review_params));
}

include "../../../include/header.php";

$suggestions = pr_open_suggestions(100);
$leaves = pr_leaf_models();
natcasesort($leaves);
$leaf_options = [];
foreach ($leaves as $node_id => $name) {
    $leaf_options[] = ['id' => (int)$node_id, 'name' => (string)$name];
}
$by_resource = [];
foreach ($suggestions as $s) {
    $by_resource[(int)$s['resource']][] = $s;
}
$filter_url = $baseurl . '/plugins/product_recognition/pages/review.php';
$review_status = getval('pr_review', '');
$review_notices = [
    'accepted' => 'product_recognition_review_accepted',
    'rejected' => 'product_recognition_review_rejected',
    'tagged' => 'product_recognition_review_tagged',
    'keep' => 'product_recognition_review_keep',
    'denied' => 'product_recognition_review_denied',
    'none' => 'product_recognition_review_none',
    'gone' => 'product_recognition_review_gone',
    'failed' => 'product_recognition_review_failed',
];
?>
<div class="BasicsBox">
<h1><?php echo escape($lang["product_recognition_review"]); ?></h1>
<p><a href="<?php echo escape(generateURL($baseurl . "/plugins/product_recognition/pages/catalog.php")); ?>"><?php echo escape($lang["product_recognition_catalog"]); ?></a></p>
<?php
if (isset($review_notices[$review_status])) {
    echo "<p>" . escape($lang[$review_notices[$review_status]]) . "</p>";
}
?>
<form method="get" action="<?php echo escape(generateURL($filter_url)); ?>" style="margin:1em 0;">
    <label><?php echo escape($lang["product_recognition_min_confidence"]); ?>
        <select name="min_confidence" onchange="this.form.submit()">
            <?php foreach ($floors as $pct) { ?>
            <option value="<?php echo (int)$pct; ?>"<?php echo $min_percent === $pct ? ' selected' : ''; ?>>
                <?php echo $pct === 0
                    ? escape($lang["product_recognition_min_confidence_any"])
                    : escape((string)$pct) . '%'; ?>
            </option>
            <?php } ?>
        </select>
    </label>
    <input type="submit" value="<?php echo escape($lang["product_recognition_filter"]); ?>" />
</form>
<?php if ($by_resource === []) { ?>
    <p><?php echo escape($lang["product_recognition_no_suggestions"]); ?></p>
<?php } else {
    $first = true;
    foreach ($by_resource as $resource_ref => $rows) {
        if (!$first) {
            echo "<hr />";
        }
        $first = false;
        $shown = pr_suggestions_at_or_above($rows, $min_confidence);
        $hidden = count($rows) - count($shown);
        $preview = get_resource_path($resource_ref, false, 'thm', false, 'jpg', true);
        ?>
        <div class="ListviewStyle">
        <a href="<?php echo $baseurl; ?>/pages/view.php?ref=<?php echo (int)$resource_ref; ?>" target="_blank">
            <img src="<?php echo escape($preview); ?>" style="max-height:120px" alt="" loading="lazy" />
        </a>
        <?php
        if ($shown === []) {
            echo "<p>" . escape($lang["product_recognition_no_matches_at_floor"]) . "</p>";
        }
        foreach ($shown as $s) {
            ?>
        <form method="post" style="display:inline-block; margin-right:1em;">
            <?php generateFormToken("pr_review"); ?>
            <input type="hidden" name="min_confidence" value="<?php echo (int)$min_percent; ?>" />
            <input type="hidden" name="suggestion" value="<?php echo (int)$s['ref']; ?>" />
            <span><?php echo escape($s['model_name']); ?></span>
            <span>&nbsp;<?php echo escape($lang["product_recognition_confidence"]); ?>
                  <?php echo sprintf('%.0f%%', 100 * (float)$s['confidence']); ?></span>
            <button type="submit" name="action" value="accept"><?php echo escape($lang["product_recognition_accept"]); ?></button>
            <button type="submit" name="action" value="reject"><?php echo escape($lang["product_recognition_reject"]); ?></button>
        </form>
            <?php
        }
        if ($shown !== [] && $hidden > 0) {
            echo "<p>" . escape(sprintf($lang["product_recognition_hidden_below"], $hidden, $min_percent)) . "</p>";
        }
        if ($leaves !== []) {
            ?>
        <form method="post" style="margin-top:0.5em;">
            <?php generateFormToken("pr_review"); ?>
            <input type="hidden" name="min_confidence" value="<?php echo (int)$min_percent; ?>" />
            <input type="hidden" name="resource" value="<?php echo (int)$resource_ref; ?>" />
            <label><?php echo escape($lang["product_recognition_pick_other"]); ?>
                <select name="model_node" class="pr-review-leaf" style="max-width:16em;">
                    <option value=""><?php echo escape($lang["product_recognition_pick_model"]); ?></option>
                </select>
            </label>
            <button type="submit" name="action" value="pick"><?php echo escape($lang["product_recognition_apply"]); ?></button>
        </form>
            <?php
        }
        echo "</div>";
    }
    if ($leaf_options !== []) {
        ?>
<script type="application/json" id="pr-leaf-json"><?php
    echo json_encode($leaf_options, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
?></script>
<script>
(function () {
    var el = document.getElementById('pr-leaf-json');
    if (!el) {
        return;
    }
    var leaves = JSON.parse(el.textContent);
    document.querySelectorAll('select.pr-review-leaf').forEach(function (sel) {
        leaves.forEach(function (row) {
            var o = document.createElement('option');
            o.value = String(row.id);
            o.textContent = row.name;
            sel.appendChild(o);
        });
    });
})();
</script>
        <?php
    }
} ?>
</div>
<?php include "../../../include/footer.php";
