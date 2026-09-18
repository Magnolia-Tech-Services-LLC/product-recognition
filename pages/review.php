<?php
include "../../../include/boot.php";
include "../../../include/authenticate.php";
include_once dirname(__FILE__, 2) . "/include/product_recognition_functions.php";

if (!checkperm('product_recognition_accept')) {
    exit($lang["error-permissiondenied"]);
}

$action = getval('action', '');
$suggestion = (int)getval('suggestion', 0);

if ($action !== '' && enforcePostRequest(false)) {
    if ($action === 'accept') {
        pr_accept_suggestion($suggestion, $userref);
    } elseif ($action === 'reject') {
        pr_reject_suggestion($suggestion, $userref);
    }
    $from = getval('from', '');
    $resource = (int)getval('resource', 0);
    if ($from === 'view' && $resource > 0) {
        redirect($baseurl . '/pages/view.php?ref=' . $resource);
    }
    redirect($baseurl . '/plugins/product_recognition/pages/review.php');
}

include "../../../include/header.php";

$suggestions = pr_open_suggestions(300);
?>
<div class="BasicsBox">
<h1><?php echo escape($lang["product_recognition_review"]); ?></h1>
<p><a href="<?php echo escape(generateURL($baseurl . "/plugins/product_recognition/pages/catalog.php")); ?>"><?php echo escape($lang["product_recognition_catalog"]); ?></a></p>
<?php if ($suggestions === []) { ?>
    <p><?php echo escape($lang["product_recognition_no_suggestions"]); ?></p>
<?php } else {
    $current = null;
    foreach ($suggestions as $s) {
        if ((int)$s['resource'] !== $current) {
            if ($current !== null) { echo "</div><hr />"; }
            $current = (int)$s['resource'];
            $preview = get_resource_path($current, false, 'thm', false, 'jpg', true);
            ?>
            <div class="ListviewStyle">
            <a href="<?php echo $baseurl; ?>/pages/view.php?ref=<?php echo $current; ?>" target="_blank">
                <img src="<?php echo escape($preview); ?>" style="max-height:120px" alt="" />
            </a>
            <?php
        }
        ?>
        <form method="post" style="display:inline-block; margin-right:1em;">
            <?php generateFormToken("pr_review"); ?>
            <input type="hidden" name="suggestion" value="<?php echo (int)$s['ref']; ?>" />
            <span><?php echo escape($s['model_name']); ?></span>
            <span>&nbsp;<?php echo escape($lang["product_recognition_confidence"]); ?>
                  <?php echo sprintf('%.0f%%', 100 * (float)$s['confidence']); ?></span>
            <button type="submit" name="action" value="accept"><?php echo escape($lang["product_recognition_accept"]); ?></button>
            <button type="submit" name="action" value="reject"><?php echo escape($lang["product_recognition_reject"]); ?></button>
        </form>
        <?php
    }
    echo "</div>";
} ?>
</div>
<?php include "../../../include/footer.php";
