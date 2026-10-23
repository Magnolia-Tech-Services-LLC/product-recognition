<?php
/**
 * Setup form definition. Kept out of pages/setup.php so a selfcheck can walk the
 * controls without posting the page or saving plugins.config_json.
 *
 * @return array{page_def:list<array>,never_list_at:int}
 */
function pr_setup_page_def(): array
{
    global $lang, $FIXED_LIST_FIELD_TYPES;
    $page_def = [];

    $page_def[] = config_add_section_header(
        $lang["product_recognition_setup_fields"],
        $lang["product_recognition_setup_fields_desc"]
    );
    # Colorway is a node field, not necessarily a tree (dynamic keywords is type 9).
    $page_def[] = config_add_single_ftype_select(
        "product_recognition_colorway_field",
        $lang["product_recognition_setup_colorway_field"],
        300,
        false,
        $FIXED_LIST_FIELD_TYPES
    );
    $page_def[] = config_add_single_ftype_select(
        "product_recognition_collab_field",
        $lang["product_recognition_setup_collab_field"],
        300,
        false,
        $FIXED_LIST_FIELD_TYPES
    );
    $page_def[] = config_add_multi_rtype_select(
        "product_recognition_resource_types",
        $lang["product_recognition_setup_resource_types"]
    );

    $page_def[] = config_add_section_header(
        $lang["product_recognition_setup_service"],
        $lang["product_recognition_setup_service_desc"]
    );
    $page_def[] = config_add_text_input(
        "product_recognition_service_url",
        $lang["product_recognition_setup_service_url"],
        false,
        420,
        false,
        $lang["product_recognition_setup_service_url_help"]
    );

    # Filled after config_gen_setup_post so Save shows the posted list.
    $never_list_at = count($page_def);
    $page_def[] = config_add_html('');
    $page_def[] = config_add_html(
        '<details id="product_recognition_advanced"><summary>'
        . escape($lang["product_recognition_setup_advanced"])
        . '</summary>'
    );
    $page_def[] = config_add_text_input(
        "product_recognition_api_key",
        $lang["product_recognition_setup_api_key"],
        true,
        420,
        false,
        $lang["product_recognition_setup_api_key_help"]
    );
    $page_def[] = config_add_text_input(
        "product_recognition_segment_classes",
        $lang["product_recognition_setup_segment_classes"],
        false,
        420,
        false,
        $lang["product_recognition_setup_segment_classes_help"]
    );

    $page_def[] = config_add_section_header(
        $lang["product_recognition_setup_pipeline"],
        $lang["product_recognition_setup_pipeline_desc"]
    );
    $page_def[] = config_add_boolean_select(
        "product_recognition_auto_apply",
        $lang["product_recognition_setup_auto_apply"]
    );
    $page_def[] = config_add_html(
        "<div class=\"Question\"><p>" . escape($lang["product_recognition_setup_auto_apply_help"])
        . "</p><div class=\"clearerleft\"></div></div>"
    );
    $page_def[] = config_add_text_input(
        "product_recognition_k",
        $lang["product_recognition_setup_k"],
        false,
        420,
        false,
        $lang["product_recognition_setup_k_help"]
    );
    $page_def[] = config_add_text_input(
        "product_recognition_min_similarity",
        $lang["product_recognition_setup_min_similarity"],
        false,
        420,
        false,
        $lang["product_recognition_setup_min_similarity_help"]
    );
    $page_def[] = config_add_text_input(
        "product_recognition_target_precision",
        $lang["product_recognition_setup_target_precision"],
        false,
        420,
        false,
        $lang["product_recognition_setup_target_precision_help"]
    );
    $page_def[] = config_add_text_input(
        "product_recognition_never_auto_apply",
        $lang["product_recognition_setup_never_auto_apply"],
        false,
        420,
        false,
        $lang["product_recognition_setup_never_auto_apply_help"]
    );
    $page_def[] = config_add_text_input(
        "product_recognition_batch",
        $lang["product_recognition_setup_batch"],
        false,
        420,
        false,
        $lang["product_recognition_setup_batch_help"]
    );
    $page_def[] = config_add_text_input(
        "product_recognition_classify_batch",
        $lang["product_recognition_setup_classify_batch"],
        false,
        420,
        false,
        $lang["product_recognition_setup_classify_batch_help"]
    );
    $page_def[] = config_add_text_input(
        "product_recognition_catalog_title_match",
        $lang["product_recognition_setup_catalog_title_match"],
        false,
        420,
        false,
        $lang["product_recognition_setup_catalog_title_match_help"]
    );
    $page_def[] = config_add_html('</details>');

    return ['page_def' => $page_def, 'never_list_at' => $never_list_at];
}

/**
 * True when the service URL is outside Advanced and every saved knob is a real
 * input between the details markers. Closed details still submit those inputs.
 * This does not POST the page.
 *
 * @param list<array> $page_def
 */
function pr_setup_advanced_inputs_inside_details(array $page_def): bool
{
    $required = [
        'product_recognition_api_key',
        'product_recognition_segment_classes',
        'product_recognition_auto_apply',
        'product_recognition_k',
        'product_recognition_min_similarity',
        'product_recognition_target_precision',
        'product_recognition_never_auto_apply',
        'product_recognition_batch',
        'product_recognition_classify_batch',
        'product_recognition_catalog_title_match',
    ];
    $open = null;
    $close = null;
    $service_at = null;
    $found = [];
    foreach ($page_def as $i => $item) {
        if (!is_array($item) || !isset($item[0])) {
            return false;
        }
        $type = (string)$item[0];
        if ($type === 'html') {
            $html = (string)($item[1] ?? '');
            if (str_contains($html, 'id="product_recognition_advanced"')) {
                $open = $i;
            }
            if (str_contains($html, '</details>')) {
                $close = $i;
            }
            continue;
        }
        $name = (string)($item[1] ?? '');
        if ($name === 'product_recognition_service_url') {
            $service_at = $i;
        }
        if (in_array($name, $required, true)) {
            $found[$name] = $i;
        }
    }
    if ($open === null || $close === null || $service_at === null || $service_at > $open || $open >= $close) {
        return false;
    }
    foreach ($required as $name) {
        if (!isset($found[$name]) || $found[$name] <= $open || $found[$name] >= $close) {
            return false;
        }
    }
    return true;
}
