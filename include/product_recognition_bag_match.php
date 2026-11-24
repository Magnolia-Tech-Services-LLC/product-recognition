<?php
/**
 * Tag one bag, find the same bag (#34): database, service and ResourceSpace plumbing.
 *
 * Every decision is in product_recognition_bag_gates.php (pure, self-checked). This file loads
 * the facts those functions need, asks the service for raw scores (POST /match_bags), and
 * writes what they decide. Nothing here auto-writes unless a config flag says so, and the flags
 * default to false.
 *
 * Tables: product_bag_tag (a person's answer for one cutout, the only seeds),
 * product_cutout_feature (DINOv2 vector + Lab histogram per cutout), product_bag_match (what the
 * nightly pass found, and what became of it).
 */

include_once __DIR__ . '/product_recognition_bag_gates.php';

/**
 * True when the three tables exist. dbstruct creates them when the plugin's structures are
 * checked, which can come after the code is deployed; until then the hot paths (a region
 * commit, a resource delete, every resource view) must not touch them.
 */
function pr_bag_tables_exist(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $exists = (int)ps_value(
        "SELECT COUNT(*) AS value FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('product_bag_tag', 'product_cutout_feature', 'product_bag_match')",
        [],
        0
    ) === 3;
    return $exists;
}

/** True when matching can run: the tables, the product field and a colorway field. */
function pr_bag_enabled(): bool
{
    return pr_bag_tables_exist() && pr_is_configured() && (int)($GLOBALS['product_recognition_colorway_field'] ?? 0) > 0;
}

/** Folder Path field: two photos are the same shoot when their node sets under it are equal. */
function pr_bag_shoot_field(): int
{
    return (int)($GLOBALS['product_recognition_bag_shoot_field'] ?? 200);
}

/** @param list<int> $ids */
function pr_bag_marks(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

/**
 * @param list<int> $ids
 * @return list<int|string>
 */
function pr_bag_int_params(array $ids): array
{
    $params = [];
    foreach ($ids as $id) {
        $params[] = 'i';
        $params[] = (int)$id;
    }
    return $params;
}

/**
 * Current file checksum and cutout boxes for each resource: the boxes only when the stored
 * regions are for the file as it is now.
 *
 * @param list<int> $resources
 * @return array<int,array{file_checksum:string,bboxes:array<int,array<int,float>>}>
 */
function pr_bag_current_cutouts(array $resources): array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique(array_map('intval', $resources))), 500) as $chunk) {
        $rows = ps_query(
            "SELECT r.ref AS resource, r.file_checksum, pr.bbox, pr.checksum AS stamp
               FROM resource r LEFT JOIN product_region pr ON pr.resource = r.ref
              WHERE r.ref IN (" . pr_bag_marks($chunk) . ")",
            pr_bag_int_params($chunk)
        );
        foreach ($rows as $row) {
            $checksum = (string)$row['file_checksum'];
            $boxes = ($row['stamp'] !== null && (string)$row['stamp'] === pr_region_stamp($checksum))
                ? pr_decode_bboxes((string)$row['bbox']) : [];
            $out[(int)$row['resource']] = ['file_checksum' => $checksum, 'bboxes' => $boxes];
        }
    }
    return $out;
}

/**
 * Products, colorways and cutout count on each photo: leaf nodes only.
 *
 * @param list<int> $resources
 * @return array<int,array{products:list<int>,colorways:list<int>,cutouts:int}>
 */
function pr_bag_load_photo_states(array $resources): array
{
    $out = [];
    foreach ($resources as $resource) {
        $out[(int)$resource] = ['products' => [], 'colorways' => [], 'cutouts' => 0];
    }
    if ($out === []) {
        return [];
    }
    $product_field = pr_product_field();
    $cw_field = (int)($GLOBALS['product_recognition_colorway_field'] ?? 0);
    $leaves = pr_leaf_models();
    foreach (array_chunk(array_keys($out), 500) as $chunk) {
        $rows = ps_query(
            "SELECT rn.resource, n.ref AS node, n.resource_type_field AS field
               FROM resource_node rn JOIN node n ON n.ref = rn.node
              WHERE rn.resource IN (" . pr_bag_marks($chunk) . ")
                AND n.resource_type_field IN (?, ?)
                AND NOT EXISTS (SELECT 1 FROM node ch WHERE ch.parent = n.ref)",
            array_merge(pr_bag_int_params($chunk), ['i', $product_field, 'i', $cw_field])
        );
        foreach ($rows as $row) {
            $resource = (int)$row['resource'];
            $node = (int)$row['node'];
            if ((int)$row['field'] === $product_field && isset($leaves[$node])) {
                $out[$resource]['products'][] = $node;
            } elseif ((int)$row['field'] === $cw_field) {
                $out[$resource]['colorways'][] = $node;
            }
        }
    }
    foreach (pr_bag_current_cutouts(array_keys($out)) as $resource => $cut) {
        $out[$resource]['cutouts'] = count($cut['bboxes']);
    }
    return $out;
}

/**
 * Shoot key per resource (Folder Path node set, copy segments removed).
 *
 * @param list<int> $resources
 * @return array<int,string>
 */
function pr_bag_load_shoots(array $resources, ?array $cfg = null): array
{
    $cfg ??= pr_bag_config();
    $field = pr_bag_shoot_field();
    $nodes = [];
    foreach ($resources as $resource) {
        $nodes[(int)$resource] = [];
    }
    if ($nodes === [] || $field <= 0) {
        return array_fill_keys(array_keys($nodes), '');
    }
    foreach (array_chunk(array_keys($nodes), 500) as $chunk) {
        $rows = ps_query(
            "SELECT rn.resource, n.ref, n.name FROM resource_node rn
               JOIN node n ON n.ref = rn.node AND n.resource_type_field = ?
              WHERE rn.resource IN (" . pr_bag_marks($chunk) . ")",
            array_merge(['i', $field], pr_bag_int_params($chunk))
        );
        foreach ($rows as $row) {
            $nodes[(int)$row['resource']][] = ['ref' => (int)$row['ref'], 'name' => (string)$row['name']];
        }
    }
    $out = [];
    foreach ($nodes as $resource => $list) {
        $out[$resource] = pr_bag_shoot_key($list, $cfg['copy_segments']);
    }
    return $out;
}

/**
 * POST a JSON body to the service. Null means it did not answer; callers stop, never guess.
 *
 * @return array<string,mixed>|null
 */
function pr_bag_post_json(string $path, array $body, int $timeout = 600): ?array
{
    $ch = curl_init(rtrim((string)$GLOBALS['product_recognition_service_url'], '/') . $path);
    $headers = ['Content-Type: application/json'];
    $key = trim((string)($GLOBALS['product_recognition_api_key'] ?? ''));
    if ($key !== '') {
        $headers[] = 'X-API-Key: ' . $key;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    # No curl_close(): a no-op since PHP 8.0 and deprecated in 8.5. The handle frees on scope exit.
    if ($response === false || $status !== 200) {
        logScript("product_recognition: POST $path failed (HTTP $status) $err");
        return null;
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

# ---------------------------------------------------------------------------------------------
# Cutout features
# ---------------------------------------------------------------------------------------------

/**
 * Ask the service for one cutout's features.
 *
 * @return array{status:string,dinov2:?string,hist:?string}|null null = the service did not answer
 */
function pr_bag_compute_features(string $mask): ?array
{
    # A plain-rectangle crop of the scene (no alpha) is not the bag. Unusable, without a call.
    if (!pr_png_has_alpha($mask)) {
        return ['status' => 'unusable', 'dinov2' => null, 'hist' => null];
    }
    $response = pr_service_post('/cutout_features', [], $mask, 180, 5);
    if ($response === null) {
        return null;
    }
    if (($response['status'] ?? '') !== 'ok') {
        return ['status' => 'unusable', 'dinov2' => null, 'hist' => null];
    }
    $dino = base64_decode((string)($response['dinov2'] ?? ''), true);
    $hist = base64_decode((string)($response['hist'] ?? ''), true);
    if ($dino === false || strlen($dino) !== 768 * 4 || $hist === false || strlen($hist) !== 1152 * 4) {
        logScript("product_recognition: /cutout_features returned a malformed vector for $mask");
        return null;
    }
    return ['status' => 'ok', 'dinov2' => $dino, 'hist' => $hist];
}

/**
 * Store one cutout's features, replacing any row for the same cutout. The key is unique in
 * intent only: RS creates the index non-unique, so this is delete then insert.
 */
function pr_bag_store_features(int $resource, int $region, string $checksum, array $bbox, int $mask_mtime, array $features): void
{
    ps_query('DELETE FROM product_cutout_feature WHERE resource = ? AND region_index = ?',
             ['i', $resource, 'i', $region]);
    ps_query(
        "INSERT INTO product_cutout_feature (resource, region_index, file_checksum, bbox, mask_mtime, dinov2, hist, status)
         VALUES (?,?,?,?,?,?,?,?)",
        ['i', $resource, 'i', $region, 's', $checksum, 's', json_encode($bbox), 'i', $mask_mtime,
         's', $features['dinov2'], 's', $features['hist'], 's', $features['status']]
    );
}

/**
 * A re-cut or a replaced file makes the stored features and any open suggestion for the photo
 * stale. Called from pr_commit_regions. Seeds stay: they re-bind by checksum and overlap.
 */
function pr_bag_forget_cutouts(int $resource): void
{
    if (!pr_bag_tables_exist()) {
        return;
    }
    ps_query('DELETE FROM product_cutout_feature WHERE resource = ?', ['i', $resource]);
    ps_query("UPDATE product_bag_match SET state = 'superseded', decided_at = NOW()
               WHERE resource = ? AND state = 'open'", ['i', $resource]);
}

/**
 * A deleted resource takes its seeds, features and matches with it, and every open suggestion
 * that was found from one of its seeds closes. Applied matches elsewhere stay, so they can be undone.
 */
function pr_bag_forget_resource(int $resource): void
{
    if (!pr_bag_tables_exist()) {
        return;
    }
    ps_query("UPDATE product_bag_match SET state = 'superseded', decided_at = NOW()
               WHERE state = 'open' AND seed_ref IN (SELECT ref FROM product_bag_tag WHERE resource = ?)",
             ['i', $resource]);
    ps_query('DELETE FROM product_bag_tag WHERE resource = ?', ['i', $resource]);
    ps_query('DELETE FROM product_cutout_feature WHERE resource = ?', ['i', $resource]);
    ps_query('DELETE FROM product_bag_match WHERE resource = ?', ['i', $resource]);
}

/**
 * Compute features for cutouts that have none, up to $limit cutouts. Resumes from a cursor;
 * a cutout whose features were cleared by a re-cut is found again by its missing row.
 *
 * @return array{computed:int,unusable:int,skipped_no_mask:int,service_down:bool,scanned:int,last:int}
 */
function pr_bag_backfill_features(int $limit, bool $dry_run = false): array
{
    $stats = ['computed' => 0, 'unusable' => 0, 'skipped_no_mask' => 0, 'service_down' => false,
              'scanned' => 0, 'last' => 0];
    if (!pr_bag_enabled() || $limit <= 0) {
        return $stats;
    }
    $cursor = (int)get_sysvar('product_recognition_bag_feature_position', 0);
    $wrapped = false;
    $budget = $limit;
    $done = [];       # a wrap must not visit a resource twice in one run
    $sql = "SELECT pr.resource, pr.bbox, pr.checksum AS stamp, r.file_checksum
              FROM product_region pr JOIN resource r ON r.ref = pr.resource AND r.archive >= 0
             WHERE pr.resource > ? AND pr.bbox <> ''
               AND pr.checksum LIKE CONCAT(r.file_checksum, ':%')
               AND (SELECT COUNT(*) FROM product_cutout_feature f
                     WHERE f.resource = pr.resource AND f.file_checksum = r.file_checksum)
                   < (LENGTH(pr.bbox) - LENGTH(REPLACE(pr.bbox, '],[', ''))) / 3 + 1
             ORDER BY pr.resource LIMIT 500";
    while ($budget > 0) {
        $rows = ps_query($sql, ['i', $cursor]);
        if ($rows === []) {
            if (!$wrapped && $cursor > 0) {
                $wrapped = true;
                $cursor = 0;
                continue;
            }
            break;
        }
        foreach ($rows as $row) {
            $resource = (int)$row['resource'];
            $checksum = (string)$row['file_checksum'];
            $cursor = $resource;
            if (isset($done[$resource])) {
                continue;
            }
            $done[$resource] = true;
            $stats['scanned']++;
            if ((string)$row['stamp'] !== pr_region_stamp($checksum)) {
                continue;
            }
            $boxes = pr_decode_bboxes((string)$row['bbox']);
            $have = [];
            foreach (ps_query('SELECT region_index, file_checksum FROM product_cutout_feature WHERE resource = ?',
                              ['i', $resource]) as $f) {
                if ((string)$f['file_checksum'] === $checksum && isset($boxes[(int)$f['region_index']])) {
                    $have[(int)$f['region_index']] = true;
                } elseif (!$dry_run) {
                    ps_query('DELETE FROM product_cutout_feature WHERE resource = ? AND region_index = ? AND file_checksum = ?',
                             ['i', $resource, 'i', (int)$f['region_index'], 's', (string)$f['file_checksum']]);
                }
            }
            foreach ($boxes as $index => $bbox) {
                if (isset($have[$index])) {
                    continue;
                }
                if ($budget <= 0) {
                    $cursor = $resource - 1;      # resume at this resource
                    break 3;
                }
                $mask = pr_mask_file($resource, $checksum, (int)$index);
                if (!is_file($mask)) {
                    $stats['skipped_no_mask']++;
                    continue;
                }
                $budget--;
                if ($dry_run) {
                    $stats['computed']++;
                    continue;
                }
                $features = pr_bag_compute_features($mask);
                if ($features === null) {
                    $stats['service_down'] = true;
                    $cursor = $resource - 1;
                    break 3;
                }
                pr_bag_store_features($resource, (int)$index, $checksum, $bbox, (int)@filemtime($mask), $features);
                $stats[$features['status'] === 'ok' ? 'computed' : 'unusable']++;
            }
        }
    }
    $stats['last'] = $cursor;
    if (!$dry_run) {
        set_sysvar('product_recognition_bag_feature_position', (string)max(0, $cursor));
    }
    return $stats;
}

# ---------------------------------------------------------------------------------------------
# Seeds (product_bag_tag)
# ---------------------------------------------------------------------------------------------

/**
 * Write one person's answer for a cutout. The cutout is its file checksum plus its box, not its
 * index: the existing seed is the stored row that re-binds to this cutout, and an answer for it
 * replaces that row (and refreshes its index and box). Open suggestions for the cutout close,
 * since a person has now said what it is. When the answer differs from the old one, open
 * matches found from that seed are stale and close too; ones already applied are left alone
 * (--bag-match lists them).
 */
function pr_bag_store_tag(int $resource, int $region, string $checksum, array $bbox, int $name_node, int $colorway_node, int $user): bool
{
    $now = pr_bag_current_cutouts([$resource])[$resource] ?? null;
    $boxes = ($now !== null && $now['file_checksum'] === $checksum) ? $now['bboxes'] : [];
    $boxes[$region] = $bbox;
    $cuts = [$resource => ['file_checksum' => $checksum, 'bboxes' => $boxes]];
    $existing = null;
    foreach (pr_bag_bind_rows(
        ps_query('SELECT * FROM product_bag_tag WHERE resource = ? AND file_checksum = ?', ['i', $resource, 's', $checksum]),
        $cuts
    ) as $row) {
        if ($row['bound'] === $region && $row['newest']) {
            $existing = $row;
        }
    }
    if ($existing !== null) {
        $changed = (int)$existing['name_node'] !== $name_node || (int)$existing['colorway_node'] !== $colorway_node;
        ps_query(
            'UPDATE product_bag_tag SET region_index = ?, bbox = ?, name_node = ?, colorway_node = ?, user = ?, created = NOW() WHERE ref = ?',
            ['i', $region, 's', json_encode($bbox), 'i', $name_node, 'i', $colorway_node, 'i', $user, 'i', (int)$existing['ref']]
        );
        if ($changed) {
            ps_query("UPDATE product_bag_match SET state = 'superseded', decided_by = ?, decided_at = NOW()
                       WHERE seed_ref = ? AND state = 'open'",
                     ['i', $user, 'i', (int)$existing['ref']]);
        }
    } else {
        ps_query(
            'INSERT INTO product_bag_tag (resource, region_index, file_checksum, bbox, name_node, colorway_node, user)
             VALUES (?,?,?,?,?,?,?)',
            ['i', $resource, 'i', $region, 's', $checksum, 's', json_encode($bbox), 'i', $name_node, 'i', $colorway_node, 'i', $user]
        );
    }
    foreach (pr_bag_bind_rows(
        ps_query("SELECT ref, resource, region_index, file_checksum, bbox FROM product_bag_match
                   WHERE resource = ? AND file_checksum = ? AND state = 'open'", ['i', $resource, 's', $checksum]),
        $cuts
    ) as $open) {
        if ($open['bound'] === $region) {
            ps_query("UPDATE product_bag_match SET state = 'superseded', decided_by = ?, decided_at = NOW() WHERE ref = ? AND state = 'open'",
                     ['i', $user, 'i', (int)$open['ref']]);
        }
    }
    return true;
}

/**
 * Applied matches whose seed has since been re-answered (another Product or Colorway). They are
 * not undone automatically; --bag-match lists them so a person can run --undo-bag-matches.
 *
 * @return list<array{match:int,resource:int,seed_ref:int}>
 */
function pr_bag_applied_from_changed_seeds(): array
{
    $out = [];
    foreach (ps_query(
        "SELECT m.ref, m.resource, m.seed_ref FROM product_bag_match m
           JOIN product_bag_tag t ON t.ref = m.seed_ref
          WHERE m.state = 'applied'
            AND (m.colorway_node <> t.colorway_node OR (m.name_node IS NOT NULL AND m.name_node <> t.name_node))
          ORDER BY m.ref"
    ) as $r) {
        $out[] = ['match' => (int)$r['ref'], 'resource' => (int)$r['resource'], 'seed_ref' => (int)$r['seed_ref']];
    }
    return $out;
}

/**
 * Save a seed from the panel: Product and Colorway for one cutout, as chosen by a person.
 *
 * @return 'saved'|'denied'|'need_product'|'need_colorway'|'need_cutout'|'failed'
 */
function pr_bag_save_tag(int $resource, int $region, string $model_text, string $colorway_text, int $user): string
{
    if ($resource <= 0 || $region < 0 || $user <= 0 || !pr_bag_enabled()) {
        return 'failed';
    }
    if (!pr_can_edit_resource($resource)) {
        return 'denied';
    }
    $bind_text = pr_product_field_is_flat() ? pr_reference_label($model_text) : $model_text;
    $name_node = pr_bind_catalog_model($bind_text, pr_leaf_models());
    if ($name_node === null) {
        return 'need_product';
    }
    $colorway_node = pr_bind_catalog_colorway($colorway_text, pr_field_leaves((int)$GLOBALS['product_recognition_colorway_field']));
    if ($colorway_node === null) {
        return 'need_colorway';
    }
    $current = pr_bag_current_cutouts([$resource])[$resource] ?? null;
    if ($current === null || !isset($current['bboxes'][$region])) {
        return 'need_cutout';
    }
    $mask = pr_mask_file($resource, $current['file_checksum'], $region);
    if (!is_file($mask) || !pr_png_has_alpha($mask)) {
        return 'need_cutout';
    }
    return pr_bag_store_tag($resource, $region, $current['file_checksum'], $current['bboxes'][$region], $name_node, $colorway_node, $user)
        ? 'saved' : 'failed';
}

/**
 * Seeds as they are now. A seed's photo can have been re-cut, so each is re-bound to the
 * current cutout by file checksum and box overlap. Seeds that cannot be bound, or whose cutout
 * has no usable features yet, are reported with the reason and left out.
 *
 * @return array{usable:array<int,array<string,mixed>>,ignored:list<array{ref:int,resource:int,reason:string}>}
 */
function pr_bag_load_seeds(?array $cfg = null): array
{
    $cfg ??= pr_bag_config();
    $tags = ps_query('SELECT * FROM product_bag_tag ORDER BY ref');
    $resources = array_values(array_unique(array_map(static fn(array $t): int => (int)$t['resource'], $tags)));
    $cutouts = pr_bag_current_cutouts($resources);
    $shoots = pr_bag_load_shoots($resources, $cfg);
    $features = [];
    foreach (array_chunk($resources, 500) as $chunk) {
        foreach (ps_query(
            "SELECT resource, region_index, file_checksum, status FROM product_cutout_feature
              WHERE resource IN (" . pr_bag_marks($chunk) . ")",
            pr_bag_int_params($chunk)
        ) as $f) {
            $features[(int)$f['resource'] . ':' . (int)$f['region_index']] = $f;
        }
    }
    $usable = [];
    $ignored = [];
    $bound = [];
    foreach ($tags as $tag) {
        $ref = (int)$tag['ref'];
        $resource = (int)$tag['resource'];
        $now = $cutouts[$resource] ?? null;
        $reason = '';
        $region = null;
        if ($now === null) {
            $reason = 'resource_gone';
        } elseif ((string)$tag['file_checksum'] !== $now['file_checksum']) {
            $reason = 'file_changed';
        } else {
            $region = pr_bag_rebind([
                'region_index' => (int)$tag['region_index'],
                'file_checksum' => (string)$tag['file_checksum'],
                'bbox' => pr_decode_bbox((string)$tag['bbox']) ?? [],
            ], $now['file_checksum'], $now['bboxes']);
            if ($region === null) {
                $reason = 'no_cutout_overlaps';
            } else {
                $f = $features[$resource . ':' . $region] ?? null;
                if ($f === null || (string)$f['file_checksum'] !== $now['file_checksum']) {
                    $reason = 'features_pending';
                } elseif ((string)$f['status'] !== 'ok') {
                    $reason = 'cutout_unusable';
                }
            }
        }
        if ($reason !== '') {
            $ignored[] = ['ref' => $ref, 'resource' => $resource, 'reason' => $reason];
            continue;
        }
        $key = $resource . ':' . $region;
        if (isset($bound[$key])) {
            # Two answers for one cutout: the later one stands.
            unset($usable[$bound[$key]]);
        }
        $bound[$key] = $ref;
        $usable[$ref] = [
            'ref' => $ref, 'resource' => $resource, 'region_index' => (int)$region,
            'checksum' => $now['file_checksum'], 'product' => (int)$tag['name_node'],
            'colorway' => (int)$tag['colorway_node'], 'shoot' => $shoots[$resource] ?? '',
        ];
    }
    return ['usable' => $usable, 'ignored' => $ignored];
}

/**
 * --import-bag-tags: rows {resource, region_index, file_checksum, bbox, name, colorway, user}.
 * A row is taken only if its file and cutout are still what the person saw.
 *
 * @param list<array<string,mixed>> $rows
 * @return array{accepted:int,rejected:list<array{row:int,resource:int,reason:string}>}
 */
function pr_bag_import_tags(array $rows, bool $dry_run = false): array
{
    $stats = ['accepted' => 0, 'rejected' => []];
    $leaves = pr_leaf_models();
    $cw_field = (int)($GLOBALS['product_recognition_colorway_field'] ?? 0);
    $colorways = $cw_field > 0 ? pr_field_leaves($cw_field) : [];
    $current = pr_bag_current_cutouts(array_values(array_filter(array_map(
        static fn($r): int => (int)($r['resource'] ?? 0), $rows
    ))));
    foreach ($rows as $at => $row) {
        $resource = (int)($row['resource'] ?? 0);
        $name = trim((string)($row['name'] ?? ''));
        $bind = pr_product_field_is_flat() ? pr_reference_label($name) : $name;
        $name_node = $name === '' ? null : pr_bind_catalog_model($bind, $leaves);
        $colorway_node = pr_bind_catalog_colorway(trim((string)($row['colorway'] ?? '')), $colorways);
        $check = pr_bag_import_check($row, $current[$resource] ?? null, $name_node, $colorway_node);
        if (!$check['ok']) {
            $stats['rejected'][] = ['row' => (int)$at, 'resource' => $resource, 'reason' => $check['reason']];
            continue;
        }
        $stats['accepted']++;
        if (!$dry_run) {
            pr_bag_store_tag(
                $resource, (int)$row['region_index'], (string)$row['file_checksum'],
                array_map('floatval', (array)$row['bbox']), (int)$name_node, (int)$colorway_node,
                (int)($row['user'] ?? 0)
            );
        }
    }
    return $stats;
}

# ---------------------------------------------------------------------------------------------
# Competitors, queries and the nightly match
# ---------------------------------------------------------------------------------------------

/**
 * Cutouts that can only lower a margin: single-cutout photos with exactly one product and one
 * colorway. Photos that hold a seed are excluded (they are seeds, not rivals).
 *
 * @param array<int,true> $seed_resources
 * @return list<array<string,mixed>>
 */
function pr_bag_load_competitors(array $seed_resources, array $cfg): array
{
    $cw_field = (int)$GLOBALS['product_recognition_colorway_field'];
    $out = [];
    $after = 0;
    while (true) {
        $rows = ps_query(
            "SELECT f.resource, r.file_checksum
               FROM product_cutout_feature f
               JOIN resource r ON r.ref = f.resource AND r.file_checksum = f.file_checksum AND r.archive >= 0
              WHERE f.status = 'ok' AND f.region_index = 0 AND f.resource > ?
                AND (SELECT COUNT(*) FROM resource_node rn JOIN node n ON n.ref = rn.node AND n.resource_type_field = ?
                      WHERE rn.resource = f.resource
                        AND NOT EXISTS (SELECT 1 FROM node ch WHERE ch.parent = n.ref)) = 1
              ORDER BY f.resource LIMIT 1000",
            ['i', $after, 'i', $cw_field]
        );
        if ($rows === []) {
            break;
        }
        $ids = array_map(static fn(array $r): int => (int)$r['resource'], $rows);
        $after = max($ids);
        $states = pr_bag_load_photo_states($ids);
        $shoots = pr_bag_load_shoots($ids, $cfg);
        foreach ($rows as $row) {
            $resource = (int)$row['resource'];
            $key = pr_bag_competitor_key($states[$resource]);
            if ($key === null || isset($seed_resources[$resource])) {
                continue;
            }
            $out[] = ['resource' => $resource, 'region_index' => 0, 'checksum' => (string)$row['file_checksum'],
                      'kind' => 'competitor', 'product' => $key['product'], 'colorway' => $key['colorway'],
                      'shoot' => $shoots[$resource] ?? '', 'ref' => 0];
        }
    }
    return $out;
}

/**
 * The cutouts to find matches for: every current cutout with usable features, except cutouts
 * that are seeds themselves (a person already answered) and cutouts whose match was applied.
 */
function pr_bag_load_cutout_page(int $after_ref, int $size): array
{
    return ps_query(
        "SELECT f.ref AS feature_ref, f.resource, f.region_index, f.file_checksum
           FROM product_cutout_feature f
           JOIN resource r ON r.ref = f.resource AND r.file_checksum = f.file_checksum AND r.archive >= 0
          WHERE f.status = 'ok' AND f.ref > ?
          ORDER BY f.ref LIMIT ?",
        ['i', $after_ref, 'i', $size]
    );
}

/**
 * Nightly: score every current cutout against the seeds, and write what the config allows.
 *
 * Seeds with no usable cutout yet are reported, not guessed. A service that does not answer
 * stops the run; nothing is decided without scores.
 *
 * @return array<string,mixed>
 */
function pr_bag_run_match(bool $dry_run = false, int $page = 300): array
{
    $stats = ['seeds' => 0, 'ignored_seeds' => [], 'competitors' => 0, 'considered' => 0, 'opened' => 0,
              'updated' => 0, 'closed' => 0, 'auto_applied' => 0, 'blocked' => 0, 'missing_features' => 0,
              'service_down' => false, 'disabled' => false];
    if (!pr_bag_enabled()) {
        $stats['disabled'] = true;
        return $stats;
    }
    $cfg = pr_bag_config();
    $withheld = pr_nodes_withheld_from_auto_apply();
    $loaded = pr_bag_load_seeds($cfg);
    $seeds = $loaded['usable'];
    $stats['seeds'] = count($seeds);
    $stats['ignored_seeds'] = $loaded['ignored'];
    $stats['stale_applied'] = pr_bag_applied_from_changed_seeds();
    $stats['superseded'] = 0;
    if ($seeds === []) {
        # No seed to stand behind an open suggestion: close them all, except those whose seed is
        # only waiting for its features (it will be usable soon).
        $pending = [];
        foreach ($loaded['ignored'] as $i) {
            if ($i['reason'] === 'features_pending') {
                $pending[] = (int)$i['ref'];
            }
        }
        $keep = $pending === [] ? '' : ' AND seed_ref NOT IN (' . pr_bag_marks($pending) . ')';
        $where = "state = 'open'" . $keep;
        $stats['superseded'] = (int)ps_value("SELECT COUNT(*) AS value FROM product_bag_match WHERE $where", pr_bag_int_params($pending), 0);
        if (!$dry_run && $stats['superseded'] > 0) {
            ps_query("UPDATE product_bag_match SET state = 'superseded', decided_at = NOW() WHERE $where", pr_bag_int_params($pending));
        }
        return $stats;
    }
    $seed_cutouts = [];
    $seed_resources = [];
    foreach ($seeds as $seed) {
        $seed_cutouts[$seed['resource'] . ':' . $seed['region_index']] = true;
        $seed_resources[$seed['resource']] = true;
    }
    $candidates = [];
    foreach ($seeds as $seed) {
        $candidates[] = ['resource' => $seed['resource'], 'region_index' => $seed['region_index'],
                         'checksum' => $seed['checksum'], 'kind' => 'seed', 'product' => $seed['product'],
                         'colorway' => $seed['colorway'], 'shoot' => $seed['shoot'], 'ref' => $seed['ref']];
    }
    $competitors = pr_bag_load_competitors($seed_resources, $cfg);
    $stats['competitors'] = count($competitors);
    $candidates = array_merge($candidates, $competitors);

    # Stored decisions follow the cutout (file checksum + box), not its old index. A row that no
    # longer binds to a current cutout suppresses nothing.
    $cols = 'ref, resource, region_index, file_checksum, bbox, name_node, colorway_node, seed_ref, state';
    $decided = ps_query("SELECT $cols FROM product_bag_match WHERE state IN ('rejected','undone','applied','open')");
    $current = pr_bag_current_cutouts(array_values(array_unique(array_map(static fn(array $r): int => (int)$r['resource'], $decided))));
    $rejected = [];
    $applied = [];
    $open = [];
    $drop = [];
    foreach (pr_bag_bind_rows($decided, $current) as $r) {
        $state = (string)$r['state'];
        if ($r['bound'] === null) {
            if ($state === 'open') {
                $drop[] = (int)$r['ref'];
            }
            continue;
        }
        $key = (int)$r['resource'] . ':' . (int)$r['bound'];
        if ($state === 'applied') {
            $applied[$key . ':' . $r['file_checksum']] = true;
        } elseif ($state === 'open') {
            if (!$r['newest']) {
                $drop[] = (int)$r['ref'];
                continue;
            }
            $open[$key] = [
                'ref' => (int)$r['ref'], 'name_node' => $r['name_node'] === null ? null : (int)$r['name_node'],
                'colorway_node' => (int)$r['colorway_node'], 'seed_ref' => (int)$r['seed_ref'],
            ];
        } else {
            $rejected[$key][] = (int)$r['colorway_node'];
        }
    }
    $stats['superseded'] += count($drop);
    if (!$dry_run && $drop !== []) {
        ps_query("UPDATE product_bag_match SET state = 'superseded', decided_at = NOW() WHERE state = 'open' AND ref IN (" . pr_bag_marks($drop) . ')',
                 pr_bag_int_params($drop));
    }
    $seen_open = [];

    $after = 0;
    while (true) {
        $cuts = pr_bag_load_cutout_page($after, $page);
        if ($cuts === []) {
            break;
        }
        $after = (int)$cuts[count($cuts) - 1]['feature_ref'];
        $queries = [];
        foreach ($cuts as $c) {
            $key = (int)$c['resource'] . ':' . (int)$c['region_index'];
            if (isset($seed_cutouts[$key]) || isset($applied[$key . ':' . $c['file_checksum']])) {
                continue;
            }
            $queries[] = ['resource' => (int)$c['resource'], 'region_index' => (int)$c['region_index'],
                          'checksum' => (string)$c['file_checksum']];
        }
        if ($queries === []) {
            continue;
        }
        $ids = array_values(array_unique(array_map(static fn(array $q): int => $q['resource'], $queries)));
        $states = pr_bag_load_photo_states($ids);
        $shoots = pr_bag_load_shoots($ids, $cfg);
        foreach ($queries as &$q) {
            $q['shoot'] = $shoots[$q['resource']] ?? '';
            $q['products'] = $states[$q['resource']]['products'];
        }
        unset($q);
        $response = pr_bag_post_json('/match_bags', [
            'db' => (string)$GLOBALS['mysql_db'], 'queries' => $queries, 'candidates' => $candidates,
        ]);
        if ($response === null || !isset($response['results']) || !is_array($response['results'])) {
            $stats['service_down'] = true;
            break;
        }
        $stats['missing_features'] += (int)($response['missing_queries'] ?? 0);
        foreach ($queries as $at => $q) {
            $scores = $response['results'][$at] ?? null;
            $cut_key = $q['resource'] . ':' . $q['region_index'];
            if (!is_array($scores)) {
                continue;
            }
            $stats['considered']++;
            $seen_open[$cut_key] = true;
            $decision = pr_bag_decide(
                ['photo' => $states[$q['resource']], 'shoot' => $q['shoot']],
                (array)($scores['colorway'] ?? []), (array)($scores['pair'] ?? []),
                array_map(static fn(array $s): array => ['product' => $s['product'], 'shoot' => $s['shoot']], $seeds),
                $open[$cut_key] ?? null, $rejected[$cut_key] ?? [], $cfg, $withheld
            );
            pr_bag_enact($decision, $q, $dry_run, $stats);
        }
    }
    # An open row for a cutout that has no features any more (re-cut, deleted) is closed by the
    # re-cut itself; one for a cutout that is now a seed or has been applied is closed here.
    if (!$dry_run && !$stats['service_down']) {
        foreach ($open as $cut_key => $row) {
            if (isset($seed_cutouts[$cut_key])) {
                ps_query("UPDATE product_bag_match SET state = 'superseded', decided_at = NOW() WHERE ref = ? AND state = 'open'",
                         ['i', $row['ref']]);
                $stats['closed']++;
            }
        }
    }
    return $stats;
}

/**
 * Carry out one pr_bag_decide() result.
 *
 * @param array<string,mixed> $stats
 */
function pr_bag_enact(array $decision, array $query, bool $dry_run, array &$stats): void
{
    $action = $decision['action'];
    if ($action === 'none') {
        return;
    }
    if ($action === 'close') {
        $stats['closed']++;
        if (!$dry_run) {
            ps_query("UPDATE product_bag_match SET state = 'superseded', decided_at = NOW() WHERE ref = ? AND state = 'open'",
                     ['i', $decision['open_ref']]);
        }
        return;
    }
    $m = $decision['match'];
    if ($decision['blocked'] !== '') {
        $stats['blocked']++;
    }
    if ($action === 'update') {
        $stats['updated']++;
        if (!$dry_run) {
            ps_query("UPDATE product_bag_match SET similarity = ?, margin = ?, same_shoot = ? WHERE ref = ? AND state = 'open'",
                     ['d', $m['similarity'], 'd', $m['margin'], 'i', $m['same_shoot'] ? 1 : 0, 'i', $decision['open_ref']]);
        }
        $match_ref = $decision['open_ref'];
    } else {
        $stats['opened']++;
        if ($dry_run) {
            $stats['auto_applied'] += $decision['auto'] ? 1 : 0;
            return;
        }
        if ($decision['open_ref'] > 0) {
            ps_query("UPDATE product_bag_match SET state = 'superseded', decided_at = NOW() WHERE ref = ? AND state = 'open'",
                     ['i', $decision['open_ref']]);
        }
        $bbox = ps_value('SELECT bbox AS value FROM product_cutout_feature WHERE resource = ? AND region_index = ?',
                         ['i', $query['resource'], 'i', $query['region_index']], '');
        ps_query(
            "INSERT INTO product_bag_match (resource, region_index, file_checksum, bbox, seed_ref, name_node, colorway_node,
                                            similarity, margin, same_shoot, state)
             VALUES (?,?,?,?,?,?,?,?,?,?, 'open')",
            ['i', $query['resource'], 'i', $query['region_index'], 's', $query['checksum'], 's', (string)$bbox,
             'i', $m['seed_ref'], 'i', $m['name_node'], 'i', $m['colorway_node'], 'd', $m['similarity'],
             'd', $m['margin'], 'i', $m['same_shoot'] ? 1 : 0]
        );
        $match_ref = (int)sql_insert_id();
    }
    if ($decision['auto'] && $match_ref > 0 && !$dry_run) {
        if (pr_bag_apply_match($match_ref, 0, false) === 'applied') {
            $stats['auto_applied']++;
        }
    } elseif ($decision['auto'] && $dry_run) {
        $stats['auto_applied']++;
    }
}

# ---------------------------------------------------------------------------------------------
# Accept, reject, undo
# ---------------------------------------------------------------------------------------------

/**
 * Write an open match to its photo: add the Name and Colorway nodes that are missing, nothing
 * else, and record exactly which node ids were added so --undo-bag-matches can remove only those.
 * Name and Colorway fields only: the other identity fields (Family, Category) are not touched.
 *
 * $manual is a person clicking Accept: edit access is checked. The nightly pass passes false.
 *
 * @return 'applied'|'blocked'|'gone'|'denied'|'failed'
 */
function pr_bag_apply_match(int $match_ref, int $user, bool $manual): string
{
    $rows = ps_query("SELECT * FROM product_bag_match WHERE ref = ? AND state = 'open'", ['i', $match_ref]);
    if ($rows === []) {
        return 'gone';
    }
    $m = $rows[0];
    $resource = (int)$m['resource'];
    if ($manual && !pr_can_edit_resource($resource)) {
        return 'denied';
    }
    # The match was found for a cutout (file checksum + box). If the file was replaced or the
    # cutout moved since, it no longer describes anything on the photo: close it, write nothing.
    if (pr_bag_bind_row($m, pr_bag_current_cutouts([$resource])[$resource] ?? null) === null) {
        ps_query("UPDATE product_bag_match SET state = 'superseded', decided_at = NOW() WHERE ref = ? AND state = 'open'",
                 ['i', $match_ref]);
        return 'gone';
    }
    $photo = pr_bag_load_photo_states([$resource])[$resource] ?? null;
    $name_node = $m['name_node'] === null ? null : (int)$m['name_node'];
    $colorway_node = (int)$m['colorway_node'];
    if ($photo === null || ($name_node !== null && !pr_is_leaf_model($name_node))) {
        return 'failed';
    }
    $plan = pr_bag_write_plan($photo, $name_node, $colorway_node);
    if ($plan['blocked'] !== '') {
        return 'blocked';
    }
    # Claim the row before writing, so two accepts cannot both add nodes.
    ps_query("UPDATE product_bag_match SET state = 'applied', decided_by = ?, decided_at = NOW() WHERE ref = ? AND state = 'open'",
             ['i', $user, 'i', $match_ref]);
    if (sql_affected_rows() !== 1) {
        return 'gone';
    }
    $note = sprintf(
        'Product recognition bag match %d: seed tag %d, similarity %.3f, margin %s, %s gate',
        $match_ref, (int)$m['seed_ref'], (float)$m['similarity'],
        $m['margin'] === null ? 'n/a' : sprintf('%.3f', (float)$m['margin']),
        $name_node === null ? 'colorway' : 'product+colorway'
    );
    $name_written = [];
    $colorway_written = [];
    if ($plan['add_name'] && $name_node !== null) {
        $missing = array_values(array_diff(pr_nodes_to_apply($name_node), pr_resource_field_nodes($resource, pr_product_field())));
        if ($missing !== []) {
            add_resource_nodes($resource, $missing, false, false);
            resource_log($resource, LOG_CODE_EDITED, pr_product_field(), $note);
            $name_written = $missing;
        }
    }
    if ($plan['add_colorway']) {
        $cw_field = (int)$GLOBALS['product_recognition_colorway_field'];
        $missing = array_values(array_diff(
            pr_model_nodes_with_ancestors($colorway_node), pr_resource_field_nodes($resource, $cw_field)
        ));
        if ($missing !== []) {
            add_resource_nodes($resource, $missing, false, false);
            resource_log($resource, LOG_CODE_EDITED, $cw_field, $note);
            $colorway_written = $missing;
        }
    }
    ps_query('UPDATE product_bag_match SET name_written = ?, colorway_written = ? WHERE ref = ?',
             ['s', pr_bag_ids_csv($name_written), 's', pr_bag_ids_csv($colorway_written), 'i', $match_ref]);
    return 'applied';
}

/** @return 'rejected'|'denied'|'gone' */
function pr_bag_reject_match(int $match_ref, int $user): string
{
    $rows = ps_query("SELECT resource FROM product_bag_match WHERE ref = ? AND state = 'open'", ['i', $match_ref]);
    if ($rows === []) {
        return 'gone';
    }
    if (!pr_can_edit_resource((int)$rows[0]['resource'])) {
        return 'denied';
    }
    ps_query("UPDATE product_bag_match SET state = 'rejected', decided_by = ?, decided_at = NOW() WHERE ref = ? AND state = 'open'",
             ['i', $user, 'i', $match_ref]);
    return 'rejected';
}

/**
 * --undo-bag-matches: take back what applied matches wrote, only those nodes and only if still
 * present, then mark the match undone (which also stops it being suggested again).
 *
 * @return array{matches:int,nodes_removed:int,already_gone:int,protected:int}
 */
function pr_bag_undo_matches(bool $dry_run = false, ?int $resource = null): array
{
    $stats = ['matches' => 0, 'nodes_removed' => 0, 'already_gone' => 0, 'protected' => 0];
    $sql = "SELECT * FROM product_bag_match WHERE state = 'applied'";
    $params = [];
    if ($resource !== null) {
        $sql .= ' AND resource = ?';
        $params = ['i', $resource];
    }
    $rows = ps_query($sql . ' ORDER BY ref', $params);
    $pending = [];
    foreach ($rows as $m) {
        $pending[(int)$m['resource']][] = $m;
    }
    foreach ($pending as $res => $matches) {
        $present = array_merge(
            pr_resource_field_nodes($res, pr_product_field()),
            pr_resource_field_nodes($res, (int)$GLOBALS['product_recognition_colorway_field'])
        );
        # Applied matches on this photo that stay: nodes they wrote are not removed from under them.
        $all_applied = ps_query("SELECT ref, name_written, colorway_written FROM product_bag_match WHERE resource = ? AND state = 'applied'", ['i', $res]);
        $undoing = array_fill_keys(array_map(static fn(array $m): int => (int)$m['ref'], $matches), true);
        foreach ($matches as $m) {
            $protect = [];
            foreach ($all_applied as $other) {
                if (!isset($undoing[(int)$other['ref']])) {
                    $protect = array_merge($protect, pr_bag_csv_ids((string)$other['name_written']), pr_bag_csv_ids((string)$other['colorway_written']));
                }
            }
            $plan = pr_bag_undo_plan('applied', (string)$m['name_written'], (string)$m['colorway_written'], $present, $protect);
            $stats['matches']++;
            $stats['nodes_removed'] += count($plan['remove']);
            $stats['already_gone'] += count($plan['already_gone']);
            $stats['protected'] += count($plan['protected']);
            if ($dry_run) {
                continue;
            }
            if ($plan['remove'] !== []) {
                delete_resource_nodes($res, $plan['remove'], false);
                resource_log($res, LOG_CODE_EDITED, 0, sprintf('Product recognition bag match %d undone: removed nodes %s', (int)$m['ref'], pr_bag_ids_csv($plan['remove'])));
                $present = array_values(array_diff($present, $plan['remove']));
            }
            ps_query("UPDATE product_bag_match SET state = 'undone', decided_at = NOW() WHERE ref = ? AND state = 'applied'", ['i', (int)$m['ref']]);
        }
    }
    return $stats;
}

# ---------------------------------------------------------------------------------------------
# --bag-match-report
# ---------------------------------------------------------------------------------------------

/**
 * Read-only measurement on the seeds: each seed against all the others (leave-one-out, near
 * copies removed as prop3 did), and again with its own colorway hidden. The held-out half is
 * the shoots whose key checksum is odd; the verdict is on that half only.
 *
 * Differences from prop3, deliberately: seeds are the only candidates (no competitors), and the
 * near-copy test covers the seed cutouts of a photo, not every cutout the photo has.
 *
 * @param callable(array):void $on_row
 * @return array{tally:array<string,array<string,int>>,seeds:int,ignored:list<array>,verdict:array{ok:bool,reasons:list<string>},error:?string}
 */
function pr_bag_report(callable $on_row): array
{
    $cfg = pr_bag_config();
    $loaded = pr_bag_load_seeds($cfg);
    $seeds = array_values($loaded['usable']);
    $out = ['tally' => [], 'seeds' => count($seeds), 'ignored' => $loaded['ignored'], 'error' => null,
            'verdict' => ['ok' => false, 'reasons' => []]];
    if (count($seeds) < 2) {
        $out['error'] = 'fewer than two usable seeds';
        $out['verdict'] = pr_bag_report_verdict([]);
        return $out;
    }
    $candidates = array_map(static fn(array $s): array => [
        'resource' => $s['resource'], 'region_index' => $s['region_index'], 'checksum' => $s['checksum'],
        'kind' => 'seed', 'product' => $s['product'], 'colorway' => $s['colorway'], 'shoot' => $s['shoot'], 'ref' => $s['ref'],
    ], $seeds);
    $modes = ['loo' => false, 'hidden' => true];
    foreach ($modes as $mode => $hide) {
        foreach (array_chunk($seeds, 200) as $chunk) {
            $queries = array_map(static fn(array $s): array => [
                'resource' => $s['resource'], 'region_index' => $s['region_index'], 'checksum' => $s['checksum'],
                'shoot' => $s['shoot'], 'products' => [$s['product']],
                'hide_colorway' => $hide ? $s['colorway'] : 0, 'hide_product' => $hide ? $s['product'] : 0,
            ], $chunk);
            $response = pr_bag_post_json('/match_bags', [
                'db' => (string)$GLOBALS['mysql_db'], 'queries' => $queries, 'candidates' => $candidates, 'near_copy' => 0.95,
            ]);
            if ($response === null || !isset($response['results'])) {
                $out['error'] = 'service did not answer';
                return $out;
            }
            foreach ($chunk as $at => $seed) {
                $scores = $response['results'][$at] ?? null;
                if (!is_array($scores)) {
                    continue;
                }
                $held = pr_bag_report_held_out($seed['shoot'] !== '' ? $seed['shoot'] : 'resource-' . $seed['resource']);
                foreach (['colorway' => (string)$seed['colorway'], 'pair' => $seed['product'] . '|' . $seed['colorway']] as $gate => $truth) {
                    $result = pr_bag_pool_result((array)($scores[$gate] ?? []));
                    $outcome = pr_bag_report_outcome($gate, $result, $truth, $cfg);
                    if ($held) {
                        $tally = &$out['tally']["$mode:$gate"];
                        $tally ??= ['right' => 0, 'wrong' => 0, 'none' => 0];
                        $tally[$outcome]++;
                        unset($tally);
                    }
                    $on_row([
                        'resource' => $seed['resource'], 'region' => $seed['region_index'], 'held_out' => $held,
                        'mode' => $mode, 'gate' => $gate, 'outcome' => $outcome,
                        'key' => $result['key'] ?? '', 'similarity' => $result['similarity'] ?? null,
                        'margin' => $result['margin'] ?? null,
                    ]);
                }
            }
        }
    }
    $out['verdict'] = pr_bag_report_verdict($out['tally']);
    return $out;
}

/**
 * The nightly step for --all: features, then matches. Each part answers for itself, so one
 * failing does not cost the other (the caller wraps both in try/catch as well).
 *
 * @return array{features:array,match:array}
 */
function pr_bag_nightly(): array
{
    $cfg = pr_bag_config();
    $features = [];
    $match = [];
    try {
        $features = pr_bag_backfill_features($cfg['batch']);
    } catch (Throwable $e) {
        $features = ['error' => 'bag features threw: ' . $e->getMessage()];
    }
    try {
        $match = pr_bag_run_match();
    } catch (Throwable $e) {
        $match = ['error' => 'bag match threw: ' . $e->getMessage()];
    }
    return ['features' => $features, 'match' => $match];
}

# ---------------------------------------------------------------------------------------------
# Screens: the resource panel and the review tab
# ---------------------------------------------------------------------------------------------

/**
 * What the panel shows beside each cutout of one photo: the seed a person saved for it, and an
 * open match found for it.
 *
 * @return array<int,array{seed:?array{product:string,colorway:string},match:?array{ref:int,product:string,colorway:string,similarity:float}}>
 */
function pr_bag_panel_state(int $resource, string $checksum): array
{
    $out = [];
    $current = [$resource => ['file_checksum' => $checksum,
                              'bboxes' => pr_bag_current_cutouts([$resource])[$resource]['bboxes'] ?? []]];
    foreach (pr_bag_bind_rows(ps_query(
        "SELECT t.ref, t.resource, t.region_index, t.file_checksum, t.bbox, nn.name AS product, cn.name AS colorway
           FROM product_bag_tag t
           JOIN node nn ON nn.ref = t.name_node JOIN node cn ON cn.ref = t.colorway_node
          WHERE t.resource = ?",
        ['i', $resource]
    ), $current) as $row) {
        if ($row['bound'] !== null && $row['newest']) {
            $out[$row['bound']]['seed'] = ['product' => (string)$row['product'], 'colorway' => (string)$row['colorway']];
        }
    }
    foreach (pr_bag_bind_rows(ps_query(
        "SELECT m.ref, m.resource, m.region_index, m.file_checksum, m.bbox, m.similarity, nn.name AS product, cn.name AS colorway
           FROM product_bag_match m
           LEFT JOIN node nn ON nn.ref = m.name_node JOIN node cn ON cn.ref = m.colorway_node
          WHERE m.resource = ? AND m.state = 'open'",
        ['i', $resource]
    ), $current) as $row) {
        if ($row['bound'] !== null && $row['newest']) {
            $out[$row['bound']]['match'] = [
                'ref' => (int)$row['ref'], 'product' => (string)($row['product'] ?? ''),
                'colorway' => (string)$row['colorway'], 'similarity' => (float)$row['similarity'],
            ];
        }
    }
    foreach ($out as $region => $parts) {
        $out[$region] += ['seed' => null, 'match' => null];
    }
    return $out;
}

/**
 * Open matches for the review tab, best first, with what the photo can take.
 *
 * @return list<array<string,mixed>>
 */
function pr_bag_open_matches(int $limit = 100): array
{
    $rows = ps_query(
        "SELECT m.ref, m.resource, m.region_index, m.file_checksum, m.bbox, m.similarity, m.margin, m.same_shoot, m.seed_ref, m.name_node,
                m.colorway_node, nn.name AS product, cn.name AS colorway,
                t.resource AS seed_resource, t.region_index AS seed_region, t.file_checksum AS seed_checksum,
                t.bbox AS seed_bbox, sn.name AS seed_product, sc.name AS seed_colorway
           FROM product_bag_match m
           JOIN product_bag_tag t ON t.ref = m.seed_ref
           JOIN node cn ON cn.ref = m.colorway_node
           JOIN node sn ON sn.ref = t.name_node JOIN node sc ON sc.ref = t.colorway_node
           LEFT JOIN node nn ON nn.ref = m.name_node
          WHERE m.state = 'open'
          ORDER BY m.similarity DESC, m.ref LIMIT ?",
        ['i', $limit]
    );
    $resources = array_values(array_unique(array_map(static fn(array $r): int => (int)$r['resource'], $rows)));
    # Only matches whose cutout (file checksum + box) is still on the photo; shown at its current index.
    $match_cuts = pr_bag_current_cutouts($resources);
    $kept = [];
    foreach (pr_bag_bind_rows($rows, $match_cuts) as $r) {
        if ($r['bound'] !== null && $r['newest']) {
            $r['region_index'] = $r['bound'];
            $kept[] = $r;
        }
    }
    $rows = $kept;
    $states = pr_bag_load_photo_states($resources);
    $seed_cuts = pr_bag_current_cutouts(array_values(array_unique(array_map(static fn(array $r): int => (int)$r['seed_resource'], $rows))));
    $out = [];
    foreach ($rows as $r) {
        $plan = pr_bag_write_plan($states[(int)$r['resource']], $r['name_node'] === null ? null : (int)$r['name_node'], (int)$r['colorway_node']);
        $now = $seed_cuts[(int)$r['seed_resource']] ?? null;
        $seed_region = $now === null ? null : pr_bag_rebind([
            'region_index' => (int)$r['seed_region'], 'file_checksum' => (string)$r['seed_checksum'],
            'bbox' => pr_decode_bbox((string)$r['seed_bbox']) ?? [],
        ], $now['file_checksum'], $now['bboxes']);
        $out[] = $r + ['blocked' => $plan['blocked'], 'seed_current_region' => $seed_region,
                       'seed_current_checksum' => $now['file_checksum'] ?? ''];
    }
    return $out;
}

/**
 * The review tab body. Output is escaped here; the page owns the frame and the notices.
 */
function pr_bag_render_review(): void
{
    global $lang, $baseurl;

    $rows = pr_bag_open_matches(100);
    if ($rows === []) {
        echo '<p>' . escape($lang['product_recognition_bag_none']) . '</p>';
        return;
    }
    $review = $baseurl . '/plugins/product_recognition/pages/review.php';
    $mask_url = static function (int $resource, int $region, string $checksum) use ($baseurl): string {
        return generateURL($baseurl . '/plugins/product_recognition/pages/mask.php',
                           ['ref' => $resource, 'i' => $region, 'c' => substr($checksum, 0, 12)]);
    };
    foreach ($rows as $r) {
        $resource = (int)$r['resource'];
        $cut = pr_bag_current_cutouts([$resource])[$resource] ?? ['file_checksum' => '', 'bboxes' => []];
        $product = (string)($r['product'] ?? '') !== '' ? (string)$r['product'] : $lang['product_recognition_bag_product_on_photo'];
        ?>
        <div class="ListviewStyle" style="margin-bottom:1em;">
            <div style="display:flex;gap:1em;align-items:flex-start;flex-wrap:wrap;">
                <figure style="margin:0;">
                    <a href="<?php echo escape($baseurl . '/pages/view.php?ref=' . $resource); ?>" target="_blank">
                        <img src="<?php echo escape($mask_url($resource, (int)$r['region_index'], $cut['file_checksum'])); ?>"
                             alt="" style="max-height:180px;max-width:220px;" loading="lazy" />
                    </a>
                    <figcaption><?php echo escape($lang['product_recognition_bag_this_cutout']); ?></figcaption>
                </figure>
                <figure style="margin:0;">
                    <?php if ($r['seed_current_region'] !== null) { ?>
                    <a href="<?php echo escape($baseurl . '/pages/view.php?ref=' . (int)$r['seed_resource']); ?>" target="_blank">
                        <img src="<?php echo escape($mask_url((int)$r['seed_resource'], (int)$r['seed_current_region'], (string)$r['seed_current_checksum'])); ?>"
                             alt="" style="max-height:180px;max-width:220px;" loading="lazy" />
                    </a>
                    <?php } ?>
                    <figcaption><?php echo escape(sprintf($lang['product_recognition_bag_seed_caption'], $r['seed_product'], $r['seed_colorway'])); ?></figcaption>
                </figure>
                <div>
                    <p><strong><?php echo escape($product); ?></strong> / <strong><?php echo escape((string)$r['colorway']); ?></strong></p>
                    <p><?php echo escape($lang['product_recognition_bag_similarity']); ?>
                       <?php echo escape(sprintf('%.0f%%', 100 * (float)$r['similarity'])); ?>,
                       <?php echo escape($lang['product_recognition_bag_margin']); ?>
                       <?php echo $r['margin'] === null ? escape($lang['product_recognition_bag_no_rival']) : escape(sprintf('%.0f%%', 100 * (float)$r['margin'])); ?>,
                       <?php echo escape((int)$r['same_shoot'] === 1 ? $lang['product_recognition_bag_same_shoot'] : $lang['product_recognition_bag_other_shoot']); ?></p>
                    <?php if ($r['blocked'] !== '') { ?>
                    <p><?php echo escape($lang['product_recognition_bag_blocked_' . $r['blocked']] ?? $lang['product_recognition_bag_blocked']); ?></p>
                    <?php } ?>
                    <form method="post" action="<?php echo escape($review); ?>" style="display:inline;">
                        <?php generateFormToken('pr_bag_review'); ?>
                        <input type="hidden" name="tab" value="bags" />
                        <input type="hidden" name="match" value="<?php echo (int)$r['ref']; ?>" />
                        <?php if ($r['blocked'] === '') { ?>
                        <button type="submit" name="action" value="bag_accept"><?php echo escape($lang['product_recognition_accept']); ?></button>
                        <?php } ?>
                        <button type="submit" name="action" value="bag_reject"><?php echo escape($lang['product_recognition_reject']); ?></button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}
