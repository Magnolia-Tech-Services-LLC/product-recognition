<?php
/**
 * Faces-style panel on view.php: a masked cutout of each recognized product, not the
 * whole frame. Box + PNG come from studio trim, or from POST /segment on lifestyle
 * frames. No box means no panel — not a second copy of the thumbnail.
 *
 * @return bool false so later custom panels still render
 */
function HookProduct_recognitionViewCustompanels()
{
    global $lang, $ref, $baseurl, $k, $product_recognition_field;

    include_once dirname(__FILE__, 2) . '/include/product_recognition_functions.php';

    if (!pr_is_configured()) {
        return false;
    }

    $rows = pr_view_product_rows((int)$ref);
    if ($rows === []) {
        return false;
    }

    $resource = get_resource_data($ref);
    if ($resource === false) {
        return false;
    }
    $checksum = (string)$resource['file_checksum'];

    $can_decide = checkperm('product_recognition_accept') && empty($k);
    $review = $baseurl . '/plugins/product_recognition/pages/review.php';
    $apply = $baseurl . '/plugins/product_recognition/pages/apply.php';
    $find = $baseurl . '/plugins/product_recognition/pages/find.php';
    $catalog = $can_decide ? pr_catalog_picker_rows() : [];
    $catalog_empty = $can_decide && $catalog === [];
    $model_names = array_values(array_unique(array_column($catalog, 'model_text')));
    $tagged_model = implode(', ', pr_field_leaf_names((int)$ref, (int)$product_recognition_field));
    $apply_status = getval('pr_apply', '');
    $find_status = getval('pr_find', '');
    ?>
    <div class="RecordBox">
        <div class="RecordPanel">
            <div class="Title"><?php echo escape($lang['product_recognition_detected']); ?></div>
            <?php
            $notices = [
                'applied' => 'product_recognition_apply_applied',
                'model' => 'product_recognition_apply_model',
                'colorway' => 'product_recognition_apply_colorway',
                'kept' => 'product_recognition_apply_kept',
                'none' => 'product_recognition_apply_none',
            ];
            if (isset($notices[$apply_status])) {
                echo '<p>' . escape($lang[$notices[$apply_status]]) . '</p>';
            }
            if ($find_status === 'empty') {
                echo '<p>' . escape($lang['product_recognition_find_empty']) . '</p>';
            }
            if ($catalog_empty) {
                echo '<p>' . escape($lang['product_recognition_catalog_empty']) . '</p>';
            }
            if ($can_decide && $catalog !== [] && $tagged_model !== '') {
                echo '<p>' . escape(sprintf($lang['product_recognition_model_kept'], $tagged_model)) . '</p>';
            }
            ?>
            <div class="Listview">
                <table class="ListviewStyle">
                    <tr>
                        <th><?php echo escape($lang['product_recognition_detected_product']); ?></th>
                        <th><?php echo escape($lang['product_recognition_code']); ?></th>
                        <th><?php echo escape($lang['product_recognition_model']); ?></th>
                        <th><?php echo escape($lang['product_recognition_colorway']); ?></th>
                        <th><?php echo escape($lang['product_recognition_confidence']); ?></th>
                        <th><?php echo escape($lang['actions']); ?></th>
                    </tr>
                    <?php foreach ($rows as $row) {
                        $mask = pr_mask_file((int)$ref, $checksum, (int)$row['region']);
                        if (!file_exists($mask)) {
                            continue;
                        }
                        $mask_params = [
                            'ref' => (int)$ref,
                            'i' => (int)$row['region'],
                            'c' => substr($checksum, 0, 12),
                        ];
                        if (!empty($k)) {
                            $mask_params['k'] = $k;
                        }
                        $mask_url = generateURL(
                            $baseurl . '/plugins/product_recognition/pages/mask.php',
                            $mask_params
                        );
                        $pre = pr_picker_preselect(
                            (string)$row['model_name'],
                            (string)$row['colorway'],
                            $catalog
                        );
                    ?>
                    <tr>
                        <td>
                            <img src="<?php echo escape($mask_url); ?>"
                                 alt="<?php echo escape($row['product']); ?>"
                                 style="max-width:200px;max-height:200px;display:block;" />
                        </td>
                        <td><?php echo escape($row['product']); ?></td>
                        <?php if ($can_decide && $catalog !== []) { ?>
                        <td colspan="2">
                            <form method="post" action="<?php echo escape($apply); ?>" class="pr-picker"
                                  style="display:flex;gap:0.5em;flex-wrap:wrap;align-items:center;">
                                <?php generateFormToken('pr_catalog_apply'); ?>
                                <input type="hidden" name="resource" value="<?php echo (int)$ref; ?>" />
                                <label><?php echo escape($lang['product_recognition_model']); ?>
                                    <select name="model_text" style="max-width:12em;" title="<?php echo escape($lang['product_recognition_model']); ?>">
                                        <option value=""><?php echo escape($lang['product_recognition_pick_model']); ?></option>
                                        <?php foreach ($model_names as $name) { ?>
                                        <option value="<?php echo escape($name); ?>"
                                            <?php echo $pre['model_text'] === $name ? ' selected' : ''; ?>>
                                            <?php echo escape($name); ?>
                                        </option>
                                        <?php } ?>
                                    </select>
                                </label>
                                <label><?php echo escape($lang['product_recognition_colorway']); ?>
                                    <select name="colorway_text" style="max-width:12em;" title="<?php echo escape($lang['product_recognition_colorway']); ?>">
                                        <option value=""><?php echo escape($lang['product_recognition_pick_colorway']); ?></option>
                                        <?php
                                        $seen_cw = [];
                                        foreach ($catalog as $c) {
                                            if ($c['colorway_text'] === '' || isset($seen_cw[$c['colorway_text']])) {
                                                continue;
                                            }
                                            $seen_cw[$c['colorway_text']] = true;
                                            ?>
                                        <option value="<?php echo escape($c['colorway_text']); ?>"
                                            <?php echo $pre['colorway_text'] === $c['colorway_text'] ? ' selected' : ''; ?>>
                                            <?php echo escape($c['colorway_text']); ?>
                                        </option>
                                            <?php
                                        }
                                        ?>
                                    </select>
                                </label>
                                <button type="submit"><?php echo escape($lang['product_recognition_apply']); ?></button>
                                <button type="submit" formmethod="get" formaction="<?php echo escape($find); ?>">
                                    <?php echo escape($lang['product_recognition_find_other']); ?>
                                </button>
                            </form>
                        </td>
                        <?php } else { ?>
                        <td><?php echo escape($row['model_name']); ?></td>
                        <td><?php echo escape($row['colorway']); ?></td>
                        <?php } ?>
                        <td><?php
                            if ($row['confidence'] !== null) {
                                echo escape(sprintf('%.0f%%', 100 * (float)$row['confidence']));
                            } else {
                                echo escape($lang['product_recognition_from_tag']);
                            }
                        ?></td>
                        <td>
                            <?php if ($can_decide && $row['suggestion'] > 0) { ?>
                            <form method="post" action="<?php echo escape($review); ?>" style="display:inline;">
                                <?php generateFormToken('pr_review'); ?>
                                <input type="hidden" name="suggestion" value="<?php echo (int)$row['suggestion']; ?>" />
                                <input type="hidden" name="from" value="view" />
                                <input type="hidden" name="resource" value="<?php echo (int)$ref; ?>" />
                                <button type="submit" name="action" value="accept"><?php echo escape($lang['product_recognition_accept']); ?></button>
                                <button type="submit" name="action" value="reject"><?php echo escape($lang['product_recognition_reject']); ?></button>
                            </form>
                            <?php } ?>
                        </td>
                    </tr>
                    <?php } ?>
                </table>
            </div>
        </div>
    </div>
    <?php if ($can_decide && $catalog !== []) { ?>
    <script type="application/json" id="pr-catalog-json"><?php
        echo json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
    ?></script>
    <script>
    (function () {
        var el = document.getElementById('pr-catalog-json');
        if (!el) {
            return;
        }
        var catalog = JSON.parse(el.textContent);
        function fillColorway(form) {
            var modelSel = form.querySelector('[name=model_text]');
            var colorSel = form.querySelector('[name=colorway_text]');
            var keep = colorSel.value;
            var model = modelSel.value;
            colorSel.innerHTML = '';
            var blank = document.createElement('option');
            blank.value = '';
            blank.textContent = <?php echo json_encode($lang['product_recognition_pick_colorway']); ?>;
            colorSel.appendChild(blank);
            var seen = {};
            catalog.forEach(function (row) {
                if (row.model_text !== model || row.colorway_text === '' || seen[row.colorway_text]) {
                    return;
                }
                seen[row.colorway_text] = true;
                var o = document.createElement('option');
                o.value = row.colorway_text;
                o.textContent = row.colorway_text;
                colorSel.appendChild(o);
            });
            if (keep !== '' && seen[keep]) {
                colorSel.value = keep;
            } else if (colorSel.options.length > 1) {
                colorSel.selectedIndex = 1;
            }
        }
        document.querySelectorAll('form.pr-picker').forEach(function (form) {
            form.querySelector('[name=model_text]').addEventListener('change', function () {
                fillColorway(form);
            });
            fillColorway(form);
        });
    })();
    </script>
    <?php } ?>
    <?php
    return false;
}
