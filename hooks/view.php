<?php
/**
 * Faces-style panel on view.php: a masked cutout when we have a region, otherwise
 * the preview plus Model / Colorway / Collaboration pickers.
 *
 * @return bool false so later custom panels still render
 */
function HookProduct_recognitionViewCustompanels()
{
    global $lang, $ref, $baseurl, $k, $product_recognition_colorway_field, $modal;

    include_once dirname(__FILE__, 2) . '/include/product_recognition_functions.php';
    $product_recognition_field = pr_product_field();

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
    $in_modal = !empty($modal) || IsModal();
    $post_js = $in_modal ? 'ModalPost' : 'CentralSpacePost';

    $can_decide = checkperm('product_recognition_accept') && empty($k);
    $review = $baseurl . '/plugins/product_recognition/pages/review.php';
    $apply = $baseurl . '/plugins/product_recognition/pages/apply.php';
    $pin = $baseurl . '/plugins/product_recognition/pages/pin.php';
    $find = $baseurl . '/plugins/product_recognition/pages/find.php';
    $catalog = $can_decide ? pr_catalog_picker_rows() : [];
    $catalog_empty = $can_decide && $catalog === [];
    # Model options = field leaves (native vocabulary), not sheet-literal catalog strings.
    $model_names = $can_decide ? array_values(pr_leaf_models()) : [];
    natcasesort($model_names);
    $model_names = array_values($model_names);
    $family_options = ($can_decide && pr_load_identity_grammar() && function_exists('product_family_values'))
        ? product_family_values()
        : $model_names;
    $colorway_field = (int)($product_recognition_colorway_field ?? 0);
    $colorway_leaves = ($can_decide && $colorway_field > 0)
        ? array_values(pr_field_leaves($colorway_field))
        : [];
    natcasesort($colorway_leaves);
    $colorway_leaves = array_values($colorway_leaves);
    $tagged_model = implode(', ', pr_field_leaf_names((int)$ref, (int)$product_recognition_field));
    $tagged_colorway = $colorway_field > 0
        ? implode(', ', pr_field_leaf_names((int)$ref, $colorway_field))
        : '';
    $ref_row = pr_reference_row((int)$ref);
    $ref_status = $ref_row['status'] ?? '';
    $region_count = count($rows);
    $can_pin_cutout = $region_count === 1 && empty($rows[0]['fallback']);
    $apply_status = getval('pr_apply', '');
    $find_status = getval('pr_find', '');
    $review_status = getval('pr_review', '');
    $ref_action_status = getval('pr_ref', '');
    ?>
    <div class="RecordBox">
        <div class="RecordPanel">
            <div class="Title"><?php echo escape($lang['product_recognition_detected']); ?></div>
            <?php
            $notices = [
                'applied' => 'product_recognition_apply_applied',
                'model' => 'product_recognition_apply_model',
                'colorway' => 'product_recognition_apply_colorway',
                'collab' => 'product_recognition_apply_collab',
                'kept' => 'product_recognition_apply_kept',
                'none' => 'product_recognition_apply_none',
                'multi' => 'product_recognition_apply_multi',
            ];
            $review_notices = [
                'accepted' => 'product_recognition_review_accepted',
                'rejected' => 'product_recognition_review_rejected',
                'tagged' => 'product_recognition_review_tagged',
                'keep' => 'product_recognition_review_keep',
                'denied' => 'product_recognition_review_denied',
                'gone' => 'product_recognition_review_gone',
                'failed' => 'product_recognition_review_failed',
            ];
            $ref_notices = [
                'pinned' => 'product_recognition_ref_pinned_ok',
                'kept' => 'product_recognition_ref_kept',
                'excluded' => 'product_recognition_ref_excluded_ok',
                'unpinned' => 'product_recognition_ref_unpinned_ok',
                'need_cutout' => 'product_recognition_ref_need_cutout',
                'need_model' => 'product_recognition_ref_need_model',
                'denied' => 'product_recognition_ref_denied',
                'gone' => 'product_recognition_ref_gone',
                'failed' => 'product_recognition_ref_failed',
            ];
            if (isset($notices[$apply_status])) {
                echo '<p>' . escape($lang[$notices[$apply_status]]) . '</p>';
            }
            if (isset($review_notices[$review_status])) {
                echo '<p>' . escape($lang[$review_notices[$review_status]]) . '</p>';
            }
            if (isset($ref_notices[$ref_action_status])) {
                echo '<p>' . escape($lang[$ref_notices[$ref_action_status]]) . '</p>';
            }
            if ($find_status === 'empty') {
                echo '<p>' . escape($lang['product_recognition_find_empty']) . '</p>';
            }
            if ($catalog_empty) {
                echo '<p>' . escape($lang['product_recognition_catalog_empty']) . '</p>';
            }
            $ref_name = '';
            if ($ref_row !== null) {
                $ref_name = (string)ps_value(
                    "SELECT name AS value FROM node WHERE ref = ?",
                    array("i", (int)$ref_row['model_node']),
                    ''
                );
            }
            $ref_label = match ($ref_status) {
                'pinned' => $ref_name !== ''
                    ? sprintf($lang['product_recognition_ref_pinned_as'], $ref_name)
                    : $lang['product_recognition_ref_pinned'],
                'derived' => $ref_name !== ''
                    ? sprintf($lang['product_recognition_ref_derived_as'], $ref_name)
                    : $lang['product_recognition_ref_derived'],
                'excluded' => $lang['product_recognition_ref_excluded'],
                'orphaned' => $lang['product_recognition_ref_orphaned'],
                default => $lang['product_recognition_ref_none'],
            };
            echo '<p><strong>' . escape($lang['product_recognition_ref_status']) . ':</strong> '
                . escape($ref_label) . '</p>';
            ?>
            <div class="Listview">
                <table class="ListviewStyle">
                    <tr>
                        <th><?php echo escape($lang['product_recognition_detected_product']); ?></th>
                        <th><?php echo escape($lang['product_recognition_family']); ?></th>
                        <th><?php echo escape($lang['product_recognition_subclass']); ?></th>
                        <th><?php echo escape($lang['product_recognition_subclass2']); ?></th>
                    </tr>
                    <?php foreach ($rows as $row) {
                        $mask = pr_mask_file((int)$ref, $checksum, (int)$row['region']);
                        $has_mask = file_exists($mask);
                        if (!$has_mask && empty($row['fallback'])) {
                            continue;
                        }
                        if ($has_mask) {
                            $mask_params = [
                                'ref' => (int)$ref,
                                'i' => (int)$row['region'],
                                'c' => substr($checksum, 0, 12),
                            ];
                            if (!empty($k)) {
                                $mask_params['k'] = $k;
                            }
                            $thumb_url = generateURL(
                                $baseurl . '/plugins/product_recognition/pages/mask.php',
                                $mask_params
                            );
                        } else {
                            $thumb_url = get_resource_path((int)$ref, false, 'pre', false, 'jpg');
                        }
                        $pre = pr_picker_preselect(
                            (string)$row['model_name'],
                            (string)$row['colorway'] !== '' ? (string)$row['colorway'] : $tagged_colorway,
                            $catalog
                        );
                        if ($pre['colorway_text'] === '' && $tagged_colorway !== '') {
                            $pre['colorway_text'] = $tagged_colorway;
                        }
                        # Prefer a colorway leaf when tagged is comma-joined.
                        if (str_contains($pre['colorway_text'], ',')) {
                            $cw_parts = preg_split('/\s*,\s*/', $pre['colorway_text']) ?: [];
                            $pre['colorway_text'] = (string)($cw_parts[0] ?? '');
                        }
                        $picker_id = 'pr-picker-' . (int)$ref . '-' . (int)$row['region'];
                        $confidence_html = $row['confidence'] !== null
                            ? escape(sprintf('%.0f%%', 100 * (float)$row['confidence']))
                            : escape($lang['product_recognition_from_tag']);
                        $facet_identity = pr_identity_for_reference_label((string)$row['model_name']);
                        $facets = $facet_identity !== null
                            ? pr_facets_from_identity($facet_identity)
                            : [
                                'family' => (string)($row['family'] ?? ''),
                                'subclass' => (string)($row['subclass'] ?? ''),
                                'subclass2' => (string)($row['subclass2'] ?? ''),
                                'category' => '',
                            ];
                        if ($facets['family'] === '' && !empty($row['family'])) {
                            $facets['family'] = (string)$row['family'];
                            $facets['subclass'] = (string)($row['subclass'] ?? '');
                            $facets['subclass2'] = (string)($row['subclass2'] ?? '');
                        }
                    ?>
                    <tr>
                        <td rowspan="2">
                            <img src="<?php echo escape($thumb_url); ?>"
                                 alt="<?php echo escape($row['product']); ?>"
                                 style="max-width:200px;max-height:200px;display:block;" />
                            <?php if ($facets['category'] !== '') { ?>
                            <div><?php echo escape($facets['category']); ?></div>
                            <?php } ?>
                        </td>
                        <?php if ($can_decide && $model_names !== []) { ?>
                        <td>
                            <form id="<?php echo escape($picker_id); ?>" method="post"
                                  action="<?php echo escape($apply); ?>" class="pr-picker"
                                  onsubmit="return <?php echo $post_js; ?>(this, true);">
                                <?php generateFormToken('pr_catalog_apply'); ?>
                                <input type="hidden" name="resource" value="<?php echo (int)$ref; ?>" />
                                <input type="hidden" name="region" value="<?php echo (int)$row['region']; ?>" />
                                <input type="hidden" name="model_text"
                                       value="<?php echo escape((string)$row['model_name']); ?>" />
                                <?php if ($in_modal) { ?>
                                <input type="hidden" name="modal" value="true" />
                                <?php } ?>
                                <select name="family" style="max-width:12em;"
                                        title="<?php echo escape($lang['product_recognition_family']); ?>">
                                    <option value=""><?php echo escape($lang['product_recognition_pick_family']); ?></option>
                                    <?php foreach ($family_options as $family_name) { ?>
                                    <option value="<?php echo escape($family_name); ?>"
                                        <?php echo pr_fold_label($facets['family']) === pr_fold_label($family_name) ? ' selected' : ''; ?>>
                                        <?php echo escape($family_name); ?>
                                    </option>
                                    <?php } ?>
                                </select>
                            </form>
                        </td>
                        <td>
                            <select form="<?php echo escape($picker_id); ?>" name="subclass" style="max-width:12em;"
                                    title="<?php echo escape($lang['product_recognition_subclass']); ?>">
                                <option value=""><?php echo escape($lang['product_recognition_pick_subclass']); ?></option>
                                <?php if ($facets['subclass'] !== '') { ?>
                                <option value="<?php echo escape($facets['subclass']); ?>" selected>
                                    <?php echo escape($facets['subclass']); ?>
                                </option>
                                <?php } ?>
                            </select>
                        </td>
                        <td>
                            <select form="<?php echo escape($picker_id); ?>" name="subclass2" style="max-width:12em;"
                                    title="<?php echo escape($lang['product_recognition_subclass2']); ?>">
                                <option value=""><?php echo escape($lang['product_recognition_pick_subclass2']); ?></option>
                                <?php if ($facets['subclass2'] !== '') { ?>
                                <option value="<?php echo escape($facets['subclass2']); ?>" selected>
                                    <?php echo escape($facets['subclass2']); ?>
                                </option>
                                <?php } ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <select form="<?php echo escape($picker_id); ?>" name="colorway_text"
                                    style="max-width:12em;"
                                    title="<?php echo escape($lang['product_recognition_colorway']); ?>">
                                <option value=""><?php echo escape($lang['product_recognition_pick_colorway']); ?></option>
                                <?php
                                $seen_cw = [];
                                foreach ($colorway_leaves as $cw_name) {
                                    $cw_name = trim((string)$cw_name);
                                    if ($cw_name === '' || isset($seen_cw[$cw_name])) {
                                        continue;
                                    }
                                    $seen_cw[$cw_name] = true;
                                    ?>
                                <option value="<?php echo escape($cw_name); ?>"
                                    <?php echo $pre['colorway_text'] === $cw_name ? ' selected' : ''; ?>>
                                    <?php echo escape($cw_name); ?>
                                </option>
                                    <?php
                                }
                                if ($pre['colorway_text'] !== '' && !isset($seen_cw[$pre['colorway_text']])) {
                                    ?>
                                <option value="<?php echo escape($pre['colorway_text']); ?>" selected>
                                    <?php echo escape($pre['colorway_text']); ?>
                                </option>
                                    <?php
                                }
                                ?>
                            </select>
                        </td>
                        <td><?php echo $confidence_html; ?></td>
                        <td>
                            <button form="<?php echo escape($picker_id); ?>" type="submit">
                                <?php echo escape($lang['product_recognition_apply']); ?>
                            </button>
                            <?php if ($can_pin_cutout) { ?>
                            <form method="post" action="<?php echo escape($pin); ?>" class="pr-pin"
                                  style="display:inline;"
                                  onsubmit="return <?php echo $post_js; ?>(this, true);">
                                <?php generateFormToken('pr_reference'); ?>
                                <input type="hidden" name="resource" value="<?php echo (int)$ref; ?>" />
                                <input type="hidden" name="region" value="<?php echo (int)$row['region']; ?>" />
                                <input type="hidden" name="action" value="pin" />
                                <input type="hidden" name="model_text"
                                       id="<?php echo escape($picker_id); ?>-pin-model"
                                       value="<?php echo escape($pre['model_text'] !== '' ? $pre['model_text'] : $tagged_model); ?>" />
                                <?php if ($in_modal) { ?>
                                <input type="hidden" name="modal" value="true" />
                                <?php } ?>
                                <button type="submit"><?php echo escape($lang['product_recognition_pin']); ?></button>
                            </form>
                            <?php } ?>
                            <?php if ($ref_status === 'pinned') { ?>
                            <form method="post" action="<?php echo escape($pin); ?>" style="display:inline;"
                                  onsubmit="return <?php echo $post_js; ?>(this, true);">
                                <?php generateFormToken('pr_reference'); ?>
                                <input type="hidden" name="resource" value="<?php echo (int)$ref; ?>" />
                                <input type="hidden" name="action" value="unpin" />
                                <?php if ($in_modal) { ?>
                                <input type="hidden" name="modal" value="true" />
                                <?php } ?>
                                <button type="submit"><?php echo escape($lang['product_recognition_unpin']); ?></button>
                            </form>
                            <?php } ?>
                            <?php if ($ref_status === 'pinned' || $ref_status === 'derived') { ?>
                            <form method="post" action="<?php echo escape($pin); ?>" style="display:inline;"
                                  onsubmit="return <?php echo $post_js; ?>(this, true);">
                                <?php generateFormToken('pr_reference'); ?>
                                <input type="hidden" name="resource" value="<?php echo (int)$ref; ?>" />
                                <input type="hidden" name="action" value="exclude" />
                                <?php if ($in_modal) { ?>
                                <input type="hidden" name="modal" value="true" />
                                <?php } ?>
                                <button type="submit"><?php echo escape($lang['product_recognition_exclude']); ?></button>
                            </form>
                            <?php } ?>
                            <form method="get" action="<?php echo escape($find); ?>" class="pr-find"
                                  style="display:inline;">
                                <input type="hidden" name="resource" value="<?php echo (int)$ref; ?>" />
                                <input type="hidden" name="family"
                                       value="<?php echo escape($facets['family']); ?>" />
                                <input type="hidden" name="subclass"
                                       value="<?php echo escape($facets['subclass']); ?>" />
                                <input type="hidden" name="subclass2"
                                       value="<?php echo escape($facets['subclass2']); ?>" />
                                <input type="hidden" name="colorway_text"
                                       value="<?php echo escape($pre['colorway_text']); ?>" />
                                <input type="hidden" name="model_text"
                                       id="<?php echo escape($picker_id); ?>-find-model"
                                       value="<?php echo escape($pre['model_text']); ?>" />
                                <?php if ($in_modal) { ?>
                                <input type="hidden" name="modal" value="true" />
                                <?php } ?>
                                <button type="submit"><?php echo escape($lang['product_recognition_find_other']); ?></button>
                            </form>
                            <?php if ($row['suggestion'] > 0) { ?>
                            <form method="post" action="<?php echo escape($review); ?>" style="display:inline;"
                                  onsubmit="return <?php echo $post_js; ?>(this, true);">
                                <?php generateFormToken('pr_review'); ?>
                                <input type="hidden" name="suggestion" value="<?php echo (int)$row['suggestion']; ?>" />
                                <input type="hidden" name="from" value="view" />
                                <input type="hidden" name="resource" value="<?php echo (int)$ref; ?>" />
                                <?php if ($in_modal) { ?>
                                <input type="hidden" name="modal" value="true" />
                                <?php } ?>
                                <button type="submit" name="action" value="accept"><?php echo escape($lang['product_recognition_accept']); ?></button>
                                <button type="submit" name="action" value="reject"><?php echo escape($lang['product_recognition_reject']); ?></button>
                            </form>
                            <?php } ?>
                        </td>
                    </tr>
                        <?php } else { ?>
                        <td><?php echo escape($facets['family']); ?></td>
                        <td><?php echo escape($facets['subclass']); ?></td>
                        <td><?php echo escape($facets['subclass2']); ?></td>
                    </tr>
                    <tr>
                        <td><?php echo escape($row['colorway'] !== '' ? $row['colorway'] : $tagged_colorway); ?></td>
                        <td><?php echo $confidence_html; ?></td>
                        <td>
                            <?php if ($can_decide) { ?>
                            <?php if ($can_pin_cutout && ($pre['model_text'] !== '' || $tagged_model !== '')) { ?>
                            <form method="post" action="<?php echo escape($pin); ?>" style="display:inline;"
                                  onsubmit="return <?php echo $post_js; ?>(this, true);">
                                <?php generateFormToken('pr_reference'); ?>
                                <input type="hidden" name="resource" value="<?php echo (int)$ref; ?>" />
                                <input type="hidden" name="region" value="<?php echo (int)$row['region']; ?>" />
                                <input type="hidden" name="action" value="pin" />
                                <input type="hidden" name="model_text"
                                       value="<?php echo escape($pre['model_text'] !== '' ? $pre['model_text'] : $tagged_model); ?>" />
                                <?php if ($in_modal) { ?>
                                <input type="hidden" name="modal" value="true" />
                                <?php } ?>
                                <button type="submit"><?php echo escape($lang['product_recognition_pin']); ?></button>
                            </form>
                            <?php } ?>
                            <?php if ($ref_status === 'pinned') { ?>
                            <form method="post" action="<?php echo escape($pin); ?>" style="display:inline;"
                                  onsubmit="return <?php echo $post_js; ?>(this, true);">
                                <?php generateFormToken('pr_reference'); ?>
                                <input type="hidden" name="resource" value="<?php echo (int)$ref; ?>" />
                                <input type="hidden" name="action" value="unpin" />
                                <?php if ($in_modal) { ?>
                                <input type="hidden" name="modal" value="true" />
                                <?php } ?>
                                <button type="submit"><?php echo escape($lang['product_recognition_unpin']); ?></button>
                            </form>
                            <?php } ?>
                            <?php if ($ref_status === 'pinned' || $ref_status === 'derived') { ?>
                            <form method="post" action="<?php echo escape($pin); ?>" style="display:inline;"
                                  onsubmit="return <?php echo $post_js; ?>(this, true);">
                                <?php generateFormToken('pr_reference'); ?>
                                <input type="hidden" name="resource" value="<?php echo (int)$ref; ?>" />
                                <input type="hidden" name="action" value="exclude" />
                                <?php if ($in_modal) { ?>
                                <input type="hidden" name="modal" value="true" />
                                <?php } ?>
                                <button type="submit"><?php echo escape($lang['product_recognition_exclude']); ?></button>
                            </form>
                            <?php } ?>
                            <?php } ?>
                            <?php if ($can_decide && $row['suggestion'] > 0) { ?>
                            <form method="post" action="<?php echo escape($review); ?>" style="display:inline;"
                                  onsubmit="return <?php echo $post_js; ?>(this, true);">
                                <?php generateFormToken('pr_review'); ?>
                                <input type="hidden" name="suggestion" value="<?php echo (int)$row['suggestion']; ?>" />
                                <input type="hidden" name="from" value="view" />
                                <input type="hidden" name="resource" value="<?php echo (int)$ref; ?>" />
                                <?php if ($in_modal) { ?>
                                <input type="hidden" name="modal" value="true" />
                                <?php } ?>
                                <button type="submit" name="action" value="accept"><?php echo escape($lang['product_recognition_accept']); ?></button>
                                <button type="submit" name="action" value="reject"><?php echo escape($lang['product_recognition_reject']); ?></button>
                            </form>
                            <?php } ?>
                        </td>
                    </tr>
                        <?php } ?>
                    <?php } ?>
                </table>
            </div>
        </div>
    </div>
    <?php if ($can_decide && $model_names !== []) { ?>
    <script type="application/json" id="pr-colorway-json"><?php
        echo json_encode($colorway_leaves, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
    ?></script>
    <script type="application/json" id="pr-facet-json"><?php
        echo json_encode(pr_facet_catalog(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
    ?></script>
    <script>
    (function () {
        var cwEl = document.getElementById('pr-colorway-json');
        var fieldColorways = cwEl ? JSON.parse(cwEl.textContent) : [];
        var facetEl = document.getElementById('pr-facet-json');
        var facetCatalog = facetEl ? JSON.parse(facetEl.textContent) : [];
        function facetSelect(form, name) {
            return document.querySelector('select[name="' + name + '"][form="' + form.id + '"]');
        }
        function fillSelect(sel, blankLabel, values, keep) {
            if (!sel) {
                return;
            }
            sel.innerHTML = '';
            var blank = document.createElement('option');
            blank.value = '';
            blank.textContent = blankLabel;
            sel.appendChild(blank);
            var seen = {};
            values.forEach(function (value) {
                if (!value || seen[value]) {
                    return;
                }
                seen[value] = true;
                var o = document.createElement('option');
                o.value = value;
                o.textContent = value;
                sel.appendChild(o);
            });
            if (keep && seen[keep]) {
                sel.value = keep;
            }
        }
        function syncModel(form) {
            var family = form.querySelector('[name=family]');
            var sub = facetSelect(form, 'subclass');
            var sub2 = facetSelect(form, 'subclass2');
            var hidden = form.querySelector('[name=model_text]');
            if (!family || !hidden) {
                return;
            }
            var familyValue = family.value;
            var subValue = sub ? sub.value : '';
            var sub2Value = sub2 ? sub2.value : '';
            var match = null;
            facetCatalog.forEach(function (row) {
                if (match || row.family !== familyValue) {
                    return;
                }
                if ((row.subclass || '') !== subValue || (row.subclass2 || '') !== sub2Value) {
                    return;
                }
                match = row;
            });
            if (match) {
                hidden.value = match.name;
            } else if (subValue === '' && sub2Value === '') {
                hidden.value = familyValue;
            } else {
                hidden.value = '';
            }
        }
        // onLoad fills the lists around the detected values and leaves model_text alone,
        // so Apply on an untouched row still posts the detected product.
        function fillFacets(form, resetSub, onLoad) {
            var family = form.querySelector('[name=family]');
            var sub = facetSelect(form, 'subclass');
            var sub2 = facetSelect(form, 'subclass2');
            if (!family || !sub) {
                return;
            }
            var subs = onLoad && sub.value ? [sub.value] : [];
            var sub2s = onLoad && sub2 && sub2.value ? [sub2.value] : [];
            facetCatalog.forEach(function (row) {
                if (row.family !== family.value) {
                    return;
                }
                if (row.subclass) {
                    subs.push(row.subclass);
                }
                if (!resetSub && sub.value !== '' && row.subclass !== sub.value) {
                    return;
                }
                if (row.subclass2) {
                    sub2s.push(row.subclass2);
                }
            });
            fillSelect(sub, <?php echo json_encode($lang['product_recognition_pick_subclass']); ?>, subs, resetSub ? '' : sub.value);
            fillSelect(sub2, <?php echo json_encode($lang['product_recognition_pick_subclass2']); ?>, sub2s, resetSub ? '' : (sub2 ? sub2.value : ''));
            if (!onLoad) {
                syncModel(form);
            }
        }
        function addColorway(sel, seen, name) {
            if (!name || seen[name]) {
                return;
            }
            seen[name] = true;
            var o = document.createElement('option');
            o.value = name;
            o.textContent = name;
            sel.appendChild(o);
        }
        function colorSelectFor(form) {
            return document.querySelector('select[name=colorway_text][form="' + form.id + '"]')
                || form.querySelector('[name=colorway_text]');
        }
        function fillColorway(form) {
            var colorSel = colorSelectFor(form);
            if (!colorSel) {
                return;
            }
            var keep = colorSel.value;
            colorSel.innerHTML = '';
            var blank = document.createElement('option');
            blank.value = '';
            blank.textContent = <?php echo json_encode($lang['product_recognition_pick_colorway']); ?>;
            colorSel.appendChild(blank);
            var seen = {};
            var names = fieldColorways.slice();
            if (keep && names.indexOf(keep) === -1) {
                names.push(keep);
            }
            names.sort(function (a, b) {
                return a.localeCompare(b, undefined, {sensitivity: 'base'});
            });
            names.forEach(function (name) {
                addColorway(colorSel, seen, name);
            });
            if (keep !== '' && seen[keep]) {
                colorSel.value = keep;
            }
        }
        document.querySelectorAll('form.pr-picker').forEach(function (form) {
            var familySel = form.querySelector('[name=family]');
            if (familySel) {
                familySel.addEventListener('change', function () {
                    fillFacets(form, true);
                    fillColorway(form);
                });
            }
            var subSel = facetSelect(form, 'subclass');
            if (subSel) {
                subSel.addEventListener('change', function () {
                    fillFacets(form, false);
                });
            }
            var sub2Sel = facetSelect(form, 'subclass2');
            if (sub2Sel) {
                sub2Sel.addEventListener('change', function () {
                    syncModel(form);
                });
            }
            fillFacets(form, false, true);
            fillColorway(form);
        });
        document.querySelectorAll('form.pr-find').forEach(function (form) {
            form.addEventListener('submit', function () {
                var hidden = form.querySelector('[name=model_text]');
                if (!hidden || !hidden.id) {
                    return;
                }
                var pickerId = hidden.id.replace(/-find-model$/, '');
                var picker = document.getElementById(pickerId);
                var model = picker ? picker.querySelector('[name=model_text]') : null;
                if (model) {
                    hidden.value = model.value;
                }
                ['family', 'subclass', 'subclass2', 'colorway_text'].forEach(function (name) {
                    var src = picker ? picker.querySelector('[name="' + name + '"]') : null;
                    if (!src) {
                        src = document.querySelector('[name="' + name + '"][form="' + pickerId + '"]');
                    }
                    var dst = form.querySelector('[name="' + name + '"]');
                    if (src && dst) {
                        dst.value = src.value;
                    }
                });
            });
        });
        document.querySelectorAll('form.pr-pin').forEach(function (form) {
            form.addEventListener('submit', function () {
                var hidden = form.querySelector('[name=model_text]');
                if (!hidden || !hidden.id) {
                    return;
                }
                var pickerId = hidden.id.replace(/-pin-model$/, '');
                var picker = document.querySelector('#' + pickerId + ' [name=model_text]');
                if (picker && picker.value) {
                    hidden.value = picker.value;
                }
            });
        });
    })();
    </script>
    <?php } ?>
    <?php
    return false;
}
