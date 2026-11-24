<?php
/**
 * Tag one bag, find the same bag (#34): database, service and ResourceSpace plumbing.
 *
 * Every decision is in product_recognition_bag_gates.php (pure, self-checked). This file loads
 * the facts those functions need, asks the service for raw scores (POST /match_bags), and
 * records what they decide. The only code here that writes tags to a photo is a person's Accept
 * (pr_bag_accept_match); the nightly pass only opens suggestions.
 *
 * Tables: product_bag_tag (a person's answer for one cutout, the only seeds),
 * product_cutout_feature (DINOv2 vector + Lab histogram per cutout), product_bag_match (what the
 * nightly pass found, and what became of it).
 */

include_once __DIR__ . '/product_recognition_bag_gates.php';

/**
 * True when the three tables exist, read from information_schema so asking never touches them.
 * The hot paths (a region commit, a resource delete, every resource view, the review tab) check
 * this first and skip the tables: a query on a missing table makes ResourceSpace run its whole
 * structure check inside the web request. Only the CLI write modes create the tables
 * (pr_bag_ensure_tables).
 */
function pr_bag_tables_exist(bool $refresh = false): bool
{
    static $exists = null;
    if ($exists !== null && !$refresh) {
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

/**
 * Create the three tables when they are missing, as pr_sheet_ensure_tables does for the sheet
 * tables. Called only by the CLI write modes: a web request or a read-only mode never creates
 * tables, and never runs check_db_structs().
 */
function pr_bag_ensure_tables(): bool
{
    if (pr_bag_tables_exist()) {
        return true;
    }
    if (PHP_SAPI !== 'cli') {
        return false;
    }
    foreach (pr_bag_table_definitions() as $statement) {
        ps_query($statement);
    }
    return pr_bag_tables_exist(true);
}

/**
 * The UNIQUE keys the tables rely on. ResourceSpace ignores the unique flag in dbstruct index files, so a
 * table it created has the index without it.
 *
 * @return array<string,array{name:string,columns:list<string>}>
 */
function pr_bag_unique_keys(): array
{
    return ['product_cutout_feature' => ['name' => 'cutout_feature_key', 'columns' => ['resource', 'region_index']]];
}

/**
 * Make sure each table has its UNIQUE key, adding it when the table was created without one. Duplicate
 * rows are never deleted: with any, the key is not added and the message says so.
 *
 * @return string '' when every key is there, else why a writing run must not go on
 */
function pr_bag_ensure_unique_keys(): string
{
    foreach (pr_bag_unique_keys() as $table => $key) {
        $rows = ps_query(
            "SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            ['s', $table]
        );
        $action = pr_bag_unique_key_action($rows, $key['name'], $key['columns']);
        if ($action === 'ok') {
            continue;
        }
        $cols = implode(', ', $key['columns']);
        $dupes = (int)ps_value("SELECT COUNT(*) AS value FROM (SELECT 1 FROM $table GROUP BY $cols HAVING COUNT(*) > 1) d", [], 0);
        if ($dupes > 0) {
            return "$table has $dupes duplicated ($cols) groups, so its UNIQUE key cannot be added; nothing was deleted. "
                . 'Remove the duplicates (keep the newest row of each) and run again';
        }
        ps_query("ALTER TABLE $table " . ($action === 'replace' ? "DROP INDEX {$key['name']}, " : '') . "ADD UNIQUE KEY {$key['name']} ($cols)");
    }
    return '';
}

/**
 * What every CLI writing mode runs first: create missing tables, then make sure the UNIQUE keys are
 * there. A problem is kept for pr_bag_disabled_reason() ('setup'), so the mode stops with the message.
 */
function pr_bag_prepare_tables(): void
{
    if (PHP_SAPI !== 'cli') {
        return;
    }
    pr_bag_ensure_tables();
    $GLOBALS['pr_bag_setup_problem'] = pr_bag_tables_exist() ? pr_bag_ensure_unique_keys() : '';
}

/** Why matching cannot run: 'colorway_field' (not set up), 'tables' (not created yet), 'setup' (a table is not usable), or '' (it can). */
function pr_bag_disabled_reason(): string
{
    if (!pr_is_configured() || (int)($GLOBALS['product_recognition_colorway_field'] ?? 0) <= 0) {
        return 'colorway_field';
    }
    if (!pr_bag_tables_exist()) {
        return 'tables';
    }
    return !empty($GLOBALS['pr_bag_setup_problem']) ? 'setup' : '';
}

/** One line for the CLI: why matching cannot run. */
function pr_bag_disabled_text(string $reason): string
{
    if ($reason === 'setup') {
        return (string)($GLOBALS['pr_bag_setup_problem'] ?? 'a bag table is not usable');
    }
    return $reason === 'tables'
        ? 'the bag tables are not created yet; a writing run creates them'
        : 'no colorway field set';
}

/** True when matching can run: the tables, the product field and a colorway field. */
function pr_bag_enabled(): bool
{
    return pr_bag_disabled_reason() === '';
}

/** Is this node a leaf of the colorway field? A person can delete or move one after a match was found. */
function pr_bag_colorway_valid(int $node): bool
{
    $field = (int)($GLOBALS['product_recognition_colorway_field'] ?? 0);
    return $node > 0 && $field > 0 && (int)ps_value(
        "SELECT COUNT(*) AS value FROM node n
          WHERE n.ref = ? AND n.resource_type_field = ?
            AND NOT EXISTS (SELECT 1 FROM node ch WHERE ch.parent = n.ref)",
        ['i', $node, 'i', $field],
        0
    ) === 1;
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
 * SQL for "this resource is live": not in a pending state (archive < 0) and not in the deletion state.
 * ResourceSpace's default delete only moves a resource to that state, so a seed, a competitor or a
 * query on such a resource must stop counting.
 */
function pr_bag_live_sql(string $alias = 'r'): string
{
    return "$alias.archive >= 0 AND $alias.archive <> " . (int)($GLOBALS['resource_deletion_state'] ?? 3);
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
              WHERE r.ref IN (" . pr_bag_marks($chunk) . ") AND " . pr_bag_live_sql('r'),
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
        pr_bag_log("POST $path failed (HTTP $status) $err");
        return null;
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * POST /match_bags. A run sends the same candidate list with every page of queries, so after the first request
 * only a key (a hash of the list) goes along and the service keeps the list for the run. A service that does not
 * know the key (restarted, or its copy expired) says so and the full list is sent again.
 *
 * @param list<array<string,mixed>> $queries
 * @param list<array<string,mixed>> $candidates
 * @param array<string,mixed> $extra more request fields (near_copy)
 * @return array<string,mixed>|null the service's answer, null when it did not answer
 */
function pr_bag_match_request(array $queries, array $candidates, array $extra = []): ?array
{
    static $sent = [];
    $key = sha1((string)json_encode($candidates));
    $body = ['db' => (string)$GLOBALS['mysql_db'], 'queries' => $queries, 'candidates_key' => $key] + $extra;
    $response = pr_bag_post_json('/match_bags', isset($sent[$key]) ? $body : $body + ['candidates' => $candidates]);
    if (isset($response['unknown_candidates'])) {
        $response = pr_bag_post_json('/match_bags', $body + ['candidates' => $candidates]);
    }
    if ($response !== null && isset($response['results'])) {
        $sent[$key] = true;
    }
    return $response;
}

# ---------------------------------------------------------------------------------------------
# Cutout features
# ---------------------------------------------------------------------------------------------

/**
 * Ask the service for one cutout's features.
 *
 * @return array{status:string,dinov2:?string,hist:?string}|null null = the service did not answer;
 *         status 'error' = it answered but failed on this cutout; 'unreadable' = the mask could not be read (nothing was asked)
 */
function pr_bag_compute_features(string $mask): ?array
{
    # A plain-rectangle crop of the scene (no alpha) is not the bag. Unusable, without a call.
    $alpha = pr_bag_png_alpha($mask);
    if ($alpha === null) {
        return ['status' => 'unreadable', 'dinov2' => null, 'hist' => null];
    }
    if (!$alpha) {
        return ['status' => 'unusable', 'dinov2' => null, 'hist' => null];
    }
    $http_status = 0;
    $response = pr_bag_quiet(static function () use ($mask, &$http_status) {
        return pr_service_post('/cutout_features', [], $mask, 180, 5, $http_status);
    });
    if ($response === null) {
        # No HTTP answer, or a proxy saying there is none, is the service being down. Any other
        # answer is about this cutout, and must not stop the ones behind it.
        return in_array($http_status, [0, 502, 503, 504], true)
            ? null : ['status' => 'error', 'dinov2' => null, 'hist' => null];
    }
    if (($response['status'] ?? '') !== 'ok') {
        return ['status' => 'unusable', 'dinov2' => null, 'hist' => null];
    }
    $dino = base64_decode((string)($response['dinov2'] ?? ''), true);
    $hist = base64_decode((string)($response['hist'] ?? ''), true);
    if ($dino === false || strlen($dino) !== 768 * 4 || $hist === false || strlen($hist) !== 1152 * 4) {
        pr_bag_log("/cutout_features returned a malformed vector for $mask");
        return ['status' => 'error', 'dinov2' => null, 'hist' => null];
    }
    return ['status' => 'ok', 'dinov2' => $dino, 'hist' => $hist];
}

/**
 * Store one cutout's features, replacing any row for the same cutout (delete, then insert).
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
 * Record that the pass is about to post this cutout, before it does. A row with status 'attempt' and a
 * count; success overwrites it (pr_bag_store_features), so what is left after a crash or a failure is
 * the count of tries for this checksum, box and mask.
 */
function pr_bag_store_attempt(int $resource, int $region, string $checksum, array $bbox, int $mask_mtime, int $attempts): void
{
    ps_query('DELETE FROM product_cutout_feature WHERE resource = ? AND region_index = ?',
             ['i', $resource, 'i', $region]);
    ps_query(
        "INSERT INTO product_cutout_feature (resource, region_index, file_checksum, bbox, mask_mtime, status, attempts)
         VALUES (?,?,?,?,?, 'attempt', ?)",
        ['i', $resource, 'i', $region, 's', $checksum, 's', json_encode($bbox), 'i', $mask_mtime, 'i', $attempts]
    );
}

/**
 * Run one file read with PHP's warnings swallowed. ResourceSpace's errorhandler() acts on a warning even
 * under @ (PHP 8 no longer silences it), and with use_error_exception on it throws: so a mask that vanishes
 * between is_file() and the read would end the run. The handler is scoped to this call.
 *
 * @template T
 * @param callable():T $read
 * @return T
 */
function pr_bag_quiet(callable $read)
{
    set_error_handler(static fn(): bool => true);
    try {
        return $read();
    } finally {
        restore_error_handler();
    }
}

/**
 * Make a SQL error throw instead of ending the process. ResourceSpace's errorhandler() prints a page and exits when
 * use_error_exception is off, which no finally block survives: the feature cursor would stay where it was and the
 * process lock would be left behind. Some helpers (get_sysvar with a warm cache, set_sysvar, debug) unset the flag,
 * so the passes call this again after them and at each page. Callers that must restore the flag do it themselves.
 */
function pr_bag_arm_exceptions(): void
{
    $GLOBALS['use_error_exception'] = true;
}

/** The mask file's mtime as it is now (never a cached stat), null when the mask is not there or cannot be read. */
function pr_bag_mask_stat(string $mask): ?int
{
    clearstatcache(true, $mask);
    $time = pr_bag_quiet(static fn() => is_file($mask) ? filemtime($mask) : false);
    return $time === false ? null : (int)$time;
}

/**
 * Whether a mask PNG has an alpha channel: null when its header could not be read (a failed read says nothing
 * about the file), false only for a header that was read and has none. Same test as pr_png_has_alpha(), without its warning.
 */
function pr_bag_png_alpha(string $path): ?bool
{
    $head = pr_bag_quiet(static fn() => file_get_contents($path, false, null, 0, 26));
    if (!is_string($head) || strlen($head) !== 26) {
        return null;
    }
    return in_array(ord($head[25]), [4, 6], true);
}

/** The mask file's mtime for a feature row, null when the mask is not there. */
function pr_bag_mask_mtime(array $row): ?int
{
    return pr_bag_mask_stat(pr_mask_file((int)$row['resource'], (string)$row['file_checksum'], (int)$row['region_index']));
}

/**
 * Delete the feature rows pr_bag_split_features() proved are not their cutout (re-cut leftovers, a mask
 * read with another mtime). A row whose mask cannot be seen or read stays: a missing mount or an I/O error
 * is not a change, and must not wipe every vector.
 *
 * @param list<int> $stale refs from pr_bag_split_features()['stale']
 */
function pr_bag_drop_stale_features(array $stale): int
{
    foreach (array_chunk($stale, 500) as $chunk) {
        ps_query('DELETE FROM product_cutout_feature WHERE ref IN (' . pr_bag_marks($chunk) . ')', pr_bag_int_params($chunk));
    }
    return count($stale);
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
 * that was found from one of its seeds closes. Applied matches elsewhere stay.
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
 * Put back the try counts that pr_bag_store_attempt() recorded, for cutouts whose failure was the service.
 *
 * @param list<array{resource:int,region:int,checksum:string,bbox:array,mtime:int,before:int}> $tries
 */
function pr_bag_give_back_attempts(array $tries): void
{
    foreach ($tries as $t) {
        if ($t['before'] > 0) {
            pr_bag_store_attempt($t['resource'], $t['region'], $t['checksum'], $t['bbox'], $t['mtime'], $t['before']);
        } else {
            ps_query("DELETE FROM product_cutout_feature WHERE resource = ? AND region_index = ? AND status = 'attempt'",
                     ['i', $t['resource'], 'i', $t['region']]);
        }
    }
}

/**
 * Cutouts the feature pass has stopped trying (PR_BAG_MAX_ATTEMPTS tries on the file as it is). The scan
 * skips them, so the loop never sees them: this counts them on their own.
 */
function pr_bag_count_given_up(): int
{
    return (int)ps_value(
        "SELECT COUNT(*) AS value FROM product_cutout_feature f
           JOIN resource r ON r.ref = f.resource AND " . pr_bag_live_sql('r') . " AND r.file_checksum = f.file_checksum
          WHERE f.status = 'attempt' AND f.attempts >= " . PR_BAG_MAX_ATTEMPTS, [], 0);
}

/** The service answered nothing for a cutout: is it down, or only unhappy with that cutout? Waits out a restart. */
function pr_bag_service_up(): bool
{
    for ($try = 0; $try < 6; $try++) {
        if (pr_service_health()) {
            return true;
        }
        if ($try < 5) {
            sleep(5);
        }
    }
    return false;
}

/** Post the fixed synthetic cutout: does the service answer a good one? Asked afresh at each failure, never cached. */
function pr_bag_probe_service(): bool
{
    $path = pr_bag_quiet(static fn() => tempnam(sys_get_temp_dir(), 'prbagprobe'));
    if ($path === false) {
        return false;
    }
    try {
        pr_bag_quiet(static fn() => file_put_contents($path, pr_bag_probe_png()));
        $features = pr_bag_compute_features($path);
    } finally {
        pr_bag_quiet(static fn() => unlink($path));
    }
    return $features !== null && $features['status'] === 'ok';
}

/**
 * Compute features for cutouts that have none or whose stored row is not current. Resumes from a
 * cursor, and scans every cutout of every resource that has regions, so a mask rewritten behind the
 * plugin's back (new mtime) is noticed the first time the pass gets to it and recomputed, with its
 * tries counted from zero. A cutout whose features were cleared by a re-cut is found by its missing row.
 *
 * Before a cutout is posted an 'attempt' row records the try (checksum, box, mask mtime, count). A
 * cutout that fails or kills the service is retried on the next lap and left alone after
 * PR_BAG_MAX_ATTEMPTS tries, until its checksum, box or mask changes. The service is checked before
 * the first attempt is recorded. When a cutout fails, what failed is settled by pr_bag_failure_plan():
 * an error answer is tested against a known-good synthetic cutout (a failing service gives the try
 * back and stops the run; a working one means the cutout is at fault and the run goes on), and a
 * service that died on a cutout and did not come back keeps that try and stops the run past it.
 *
 * @return array{computed:int,unusable:int,failed:int,skipped_no_mask:int,unverified:int,given_up:int,stale:int,service_down:bool,scanned:int,last:int}
 */
function pr_bag_backfill_features(int $limit, bool $dry_run = false): array
{
    $stats = ['computed' => 0, 'unusable' => 0, 'failed' => 0, 'skipped_no_mask' => 0, 'unverified' => 0, 'given_up' => 0, 'stale' => 0, 'service_down' => false,
              'scanned' => 0, 'last' => 0];
    pr_bag_arm_exceptions();
    if (!$dry_run) {
        pr_bag_prepare_tables();
    }
    if (!pr_bag_enabled() || $limit <= 0) {
        $stats['disabled_reason'] = pr_bag_disabled_reason();
        return $stats;
    }
    $stats['given_up'] = pr_bag_count_given_up();
    if (!$dry_run && !pr_bag_service_up()) {
        $stats['service_down'] = true;
        return $stats;
    }
    $cursor = (int)get_sysvar('product_recognition_bag_feature_position', 0);
    pr_bag_arm_exceptions();      # after get_sysvar(), which clears it when the query cache is warm
    $wrapped = false;
    $budget = $limit;
    $done = [];       # a wrap must not visit a resource twice in one run
    $sql = "SELECT pr.resource, pr.bbox, pr.checksum AS stamp, r.file_checksum
              FROM product_region pr JOIN resource r ON r.ref = pr.resource AND " . pr_bag_live_sql('r') . "
             WHERE pr.resource > ? AND pr.bbox <> ''
               AND pr.checksum LIKE CONCAT(r.file_checksum, ':%')
             ORDER BY pr.resource LIMIT 500";
    try {
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
            $page_resources = array_map(static fn(array $r): int => (int)$r['resource'], $rows);
            $stored_by_resource = [];
            foreach (ps_query(
                'SELECT ref, resource, region_index, file_checksum, bbox, mask_mtime, status, attempts FROM product_cutout_feature
                  WHERE resource IN (' . pr_bag_marks($page_resources) . ')',
                pr_bag_int_params($page_resources)
            ) as $f) {
                $stored_by_resource[(int)$f['resource']][] = $f;
            }
            foreach ($rows as $row) {
                pr_bag_arm_exceptions();
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
                # A stored row counts only if it is this cutout: file checksum, box and mask file as it is
                # now (mtime). Anything else is a leftover and is replaced here, with its tries.
                $stored = $stored_by_resource[$resource] ?? [];
                $split = pr_bag_split_features($stored, [$resource => ['file_checksum' => $checksum, 'bboxes' => $boxes]], 'pr_bag_mask_mtime');
                $current = $split['current'];
                $stats['unverified'] += count($split['unverified']);
                if (!$dry_run) {
                    pr_bag_drop_stale_features($split['stale']);
                }
                foreach ($boxes as $index => $bbox) {
                    $mine = $current[$resource . ':' . $index] ?? null;
                    $state = pr_bag_feature_state($mine);
                    if ($state === 'done') {
                        continue;
                    }
                    $mask = pr_mask_file($resource, $checksum, (int)$index);
                    if (pr_bag_mask_stat($mask) === null || is_file($mask . '.fail')) {
                        $stats['skipped_no_mask']++;
                        continue;
                    }
                    if ($state === 'given_up') {
                        continue;
                    }
                    if ($budget <= 0) {
                        $cursor = $resource - 1;      # resume at this resource
                        break 3;
                    }
                    $budget--;
                    if ($dry_run) {
                        $stats['computed']++;
                        continue;
                    }
                    $mtime = pr_bag_mask_stat($mask);
                    if ($mtime === null) {
                        $stats['skipped_no_mask']++;      # gone since the check above
                        continue;
                    }
                    $tried = ['resource' => $resource, 'region' => (int)$index, 'checksum' => $checksum, 'bbox' => $bbox, 'mtime' => $mtime,
                              'before' => (int)($mine['attempts'] ?? 0)];
                    pr_bag_store_attempt($resource, (int)$index, $checksum, $bbox, $mtime, $tried['before'] + 1);
                    $features = pr_bag_compute_features($mask);
                    if (pr_bag_mask_stat($mask) !== $mtime) {
                        # The mask was rewritten or removed while the service worked, so whatever it
                        # answered, or failed to, is about the old one. The row is replaced on the next pass.
                        $stats['stale']++;
                        continue;
                    }
                    if ($features !== null && $features['status'] === 'unreadable') {
                        # The header could not be read: no row, no try, the same as a mask that is not there.
                        pr_bag_give_back_attempts([$tried]);
                        $stats['unverified']++;
                        continue;
                    }
                    $no_answer = $features === null;
                    $back = true;
                    if ($no_answer) {
                        # No answer: the service is down, or it died on this cutout. Wait for it.
                        $back = pr_bag_service_up();
                        $features = ['status' => 'error', 'dinov2' => null, 'hist' => null];
                    }
                    if ($features['status'] === 'error') {
                        $stats['failed']++;
                        $plan = pr_bag_failure_plan($no_answer, $back, $no_answer || pr_bag_probe_service());
                        if (!$plan['keep_try']) {
                            pr_bag_give_back_attempts([$tried]);
                        }
                        if ($plan['stop']) {
                            $stats['service_down'] = true;
                            $cursor = $plan['cursor'] === 'past' ? $resource : $resource - 1;
                            break 3;
                        }
                        continue;
                    }
                    pr_bag_store_features($resource, (int)$index, $checksum, $bbox, $mtime, $features);
                    $stats[$features['status'] === 'ok' ? 'computed' : 'unusable']++;
                }
            }
        }
    } finally {
        # Wherever the loop ended, an exit from a throw included, the next run resumes from here.
        $stats['last'] = $cursor;
        if (!$dry_run) {
            set_sysvar('product_recognition_bag_feature_position', (string)max(0, $cursor));
            pr_bag_arm_exceptions();
        }
    }
    return $stats;
}

# ---------------------------------------------------------------------------------------------
# Seeds (product_bag_tag)
# ---------------------------------------------------------------------------------------------

/** Thrown when a row a transaction just wrote cannot be found before it commits. */
class PrBagRowMissing extends RuntimeException
{
}

/**
 * Inside a transaction, read back the row it just wrote and throw if it is not there as written. A deadlock
 * rolls the transaction back and ResourceSpace retries only the failed statement outside it, so a write can be
 * lost with no error; the caller must not report success, nor commit, on that.
 *
 * @param string $what for the log
 * @param string $sql a SELECT that matches the final row exactly, with ? marks
 * @param array<int,mixed> $params ps_query parameters
 */
function pr_bag_require_row(string $what, string $sql, array $params): void
{
    if (ps_query($sql, $params) === []) {
        throw new PrBagRowMissing($what . ' is not there before commit');
    }
}

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
    # One seed per cutout holds under concurrent saves: the photo's lock is the first statement, so a second
    # save waits for the first and then finds its row. A person's save and an import take the same lock.
    db_begin_transaction('pr_bag_seed');
    try {
        pr_bag_lock_photo($resource);
        $saved = pr_bag_store_tag_locked($resource, $region, $checksum, $bbox, $name_node, $colorway_node, $user);
        pr_bag_require_row('seed tag for resource ' . $resource . ' cutout ' . $region,
            'SELECT ref FROM product_bag_tag WHERE resource = ? AND file_checksum = ? AND region_index = ? AND bbox = ? AND name_node = ? AND colorway_node = ? AND user = ?',
            ['i', $resource, 's', $checksum, 'i', $region, 's', json_encode($bbox), 'i', $name_node, 'i', $colorway_node, 'i', $user]);
    } catch (PrBagRowMissing $e) {
        db_rollback_transaction('pr_bag_seed');
        pr_bag_log('bag seed not saved: ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        db_rollback_transaction('pr_bag_seed');
        throw $e;
    }
    db_end_transaction('pr_bag_seed');
    return $saved;
}

/** The body of pr_bag_store_tag(), run inside its transaction with the photo locked. */
function pr_bag_store_tag_locked(int $resource, int $region, string $checksum, array $bbox, int $name_node, int $colorway_node, int $user): bool
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
 * Accepts that claimed a match and did not finish: applied, but decided_at still empty. An accept that
 * is running right now is not visible here (its claim is uncommitted), so these are rows an implicit
 * commit or a crash left behind. They are counted, never repaired.
 *
 * @return list<int> match refs, oldest first
 */
function pr_bag_half_applied(): array
{
    return array_map(static fn(array $r): int => (int)$r['ref'],
                     ps_query("SELECT ref FROM product_bag_match WHERE state = 'applied' AND decided_at IS NULL ORDER BY ref"));
}

/**
 * Applied matches whose seed has since been re-answered (another Product or Colorway). Nothing
 * takes their tags back; --bag-match lists them for a person to look at.
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
 * @return 'saved'|'denied'|'need_product'|'need_colorway'|'need_cutout'|'no_tables'|'failed'
 */
function pr_bag_save_tag(int $resource, int $region, string $model_text, string $colorway_text, int $user,
                         ?string $posted_checksum = null, ?string $posted_bbox = null): string
{
    if (pr_bag_disabled_reason() === 'tables') {
        return 'no_tables';
    }
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
    if ($posted_checksum !== null) {
        # The form carries the file and box the person was looking at. If that is no longer a
        # current cutout (re-cut, replaced file), what they chose is not an answer for any bag here.
        $bound = pr_bag_bind_row(['region_index' => $region, 'file_checksum' => $posted_checksum,
                                  'bbox' => (string)$posted_bbox], $current);
        if ($bound === null) {
            return 'need_cutout';
        }
        $region = $bound;
    }
    if ($current === null || !isset($current['bboxes'][$region])) {
        return 'need_cutout';
    }
    $mask = pr_mask_file($resource, $current['file_checksum'], $region);
    $alpha = pr_bag_png_alpha($mask);
    if ($alpha === null) {
        return 'failed';      # the mask cannot be read right now: not proof it is a plain crop
    }
    if (!$alpha) {
        return 'need_cutout';
    }
    return pr_bag_store_tag($resource, $region, $current['file_checksum'], $current['bboxes'][$region], $name_node, $colorway_node, $user)
        ? 'saved' : 'failed';
}

/**
 * Seeds as they are now. A seed's photo can have been re-cut, so each is re-bound to the
 * current cutout by file checksum and box overlap. Seeds that cannot be bound, whose Product or
 * Colorway is no longer a leaf of its field, or whose cutout has no usable features yet, are
 * reported with the reason and left out.
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
    $rows = [];
    foreach (array_chunk($resources, 500) as $chunk) {
        $rows = array_merge($rows, ps_query(
            "SELECT ref, resource, region_index, file_checksum, bbox, mask_mtime, status, attempts FROM product_cutout_feature
              WHERE resource IN (" . pr_bag_marks($chunk) . ")",
            pr_bag_int_params($chunk)
        ));
    }
    $features = pr_bag_current_features($rows, $cutouts, 'pr_bag_mask_mtime');
    $products = pr_leaf_models();
    $colorways = pr_field_leaves((int)($GLOBALS['product_recognition_colorway_field'] ?? 0));
    $usable = [];
    $ignored = [];
    $bound = [];
    foreach ($tags as $tag) {
        $ref = (int)$tag['ref'];
        $resource = (int)$tag['resource'];
        $now = $cutouts[$resource] ?? null;
        $bound_at = $now === null || (string)$tag['file_checksum'] !== $now['file_checksum'] ? null : pr_bag_bind_row($tag, $now);
        $feature_key = $bound_at === null ? null : $resource . ':' . $bound_at;
        $seen = pr_bag_seed_reason($tag, $now, $feature_key === null ? null : ($features[$feature_key] ?? null), $products, $colorways);
        $reason = $seen['reason'];
        $region = $seen['region'];
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
 * Why one seed is not usable right now ('' when it is), read from the database as it is. For the check
 * before Accept; pr_bag_load_seeds() does the same for all seeds at once.
 */
function pr_bag_seed_reason_now(array $tag): string
{
    $resource = (int)$tag['resource'];
    $now = pr_bag_current_cutouts([$resource])[$resource] ?? null;
    $feature = null;
    if ($now !== null && (string)$tag['file_checksum'] === $now['file_checksum'] && ($region = pr_bag_bind_row($tag, $now)) !== null) {
        $rows = ps_query('SELECT ref, resource, region_index, file_checksum, bbox, mask_mtime, status, attempts FROM product_cutout_feature WHERE resource = ?',
                         ['i', $resource]);
        $feature = pr_bag_current_features($rows, [$resource => $now], 'pr_bag_mask_mtime')[$resource . ':' . $region] ?? null;
    }
    return pr_bag_seed_reason($tag, $now, $feature, pr_leaf_models(), pr_field_leaves((int)($GLOBALS['product_recognition_colorway_field'] ?? 0)))['reason'];
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
    if (!$dry_run) {
        pr_bag_prepare_tables();
    }
    if (!pr_bag_enabled()) {
        $stats['disabled_reason'] = pr_bag_disabled_reason();
        return $stats;
    }
    $leaves = pr_leaf_models();
    $cw_field = (int)($GLOBALS['product_recognition_colorway_field'] ?? 0);
    $colorways = $cw_field > 0 ? pr_field_leaves($cw_field) : [];
    $current = pr_bag_current_cutouts(array_values(array_filter(array_map(
        static fn($r): int => pr_bag_import_row_is_object($r) ? (int)($r['resource'] ?? 0) : 0, $rows
    ))));
    foreach ($rows as $at => $row) {
        if (!pr_bag_import_row_is_object($row)) {
            $stats['rejected'][] = ['row' => (int)$at, 'resource' => 0, 'reason' => 'not_object'];
            continue;
        }
        $shape = pr_bag_import_row_shape($row);
        if ($shape !== '') {
            $stats['rejected'][] = ['row' => (int)$at, 'resource' => is_scalar($row['resource'] ?? null) ? (int)$row['resource'] : 0, 'reason' => $shape];
            continue;
        }
        $resource = (int)$row['resource'];
        $name = trim($row['name']);
        $bind = pr_product_field_is_flat() ? pr_reference_label($name) : $name;
        $name_node = $name === '' ? null : pr_bind_catalog_model($bind, $leaves);
        $colorway_node = pr_bind_catalog_colorway(trim($row['colorway']), $colorways);
        $check = pr_bag_import_check($row, $current[$resource] ?? null, $name_node, $colorway_node);
        if (!$check['ok']) {
            $stats['rejected'][] = ['row' => (int)$at, 'resource' => $resource, 'reason' => $check['reason']];
            continue;
        }
        # Same test as Save as seed: a plain crop (no alpha) is no bag, and a header that cannot be read is not proof either way.
        $alpha = pr_bag_png_alpha(pr_mask_file($resource, (string)$row['file_checksum'], (int)$row['region_index']));
        if ($alpha !== true) {
            $stats['rejected'][] = ['row' => (int)$at, 'resource' => $resource, 'reason' => $alpha === null ? 'mask_unreadable' : 'need_cutout'];
            continue;
        }
        if (!$dry_run && !pr_bag_store_tag(
            $resource, (int)$row['region_index'], $row['file_checksum'],
            array_map('floatval', $row['bbox']), (int)$name_node, (int)$colorway_node,
            (int)($row['user'] ?? 0)
        )) {
            $stats['rejected'][] = ['row' => (int)$at, 'resource' => $resource, 'reason' => 'store_failed'];
            continue;
        }
        $stats['accepted']++;
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
            "SELECT f.ref, f.resource, f.region_index, f.file_checksum, f.bbox, f.mask_mtime, r.file_checksum AS current_checksum
               FROM product_cutout_feature f
               JOIN resource r ON r.ref = f.resource AND r.file_checksum = f.file_checksum AND " . pr_bag_live_sql('r') . "
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
        $current = pr_bag_current_features($rows, pr_bag_current_cutouts($ids), 'pr_bag_mask_mtime');
        foreach ($rows as $row) {
            $resource = (int)$row['resource'];
            $key = pr_bag_competitor_key($states[$resource]);
            if ($key === null || isset($seed_resources[$resource]) || !isset($current[$resource . ':0'])) {
                continue;
            }
            $out[] = ['resource' => $resource, 'region_index' => 0, 'checksum' => (string)$row['current_checksum'],
                      'kind' => 'competitor', 'product' => $key['product'], 'colorway' => $key['colorway'],
                      'shoot' => $shoots[$resource] ?? '', 'ref' => 0];
        }
    }
    return $out;
}

/**
 * Drop every stored feature row that is not its cutout any more (mask rewritten, box moved). Runs
 * before the match pass looks for seeds, so a night with no seeds still clears them.
 */
function pr_bag_drop_stale_pass(?int &$unverified = null): int
{
    $after = 0;
    $dropped = 0;
    $unverified = 0;
    while (($cuts = pr_bag_load_cutout_page($after, 500)) !== []) {
        $after = (int)$cuts[count($cuts) - 1]['feature_ref'];
        $split = pr_bag_split_features($cuts, pr_bag_current_cutouts(array_map(static fn(array $c): int => (int)$c['resource'], $cuts)), 'pr_bag_mask_mtime');
        $dropped += pr_bag_drop_stale_features($split['stale']);
        $unverified += count($split['unverified']);
    }
    return $dropped;
}

/**
 * One page of cutouts with usable features for the file as it is now. The caller drops the ones
 * that are no longer on the photo, that are seeds themselves (a person already answered) and
 * whose match was applied.
 */
function pr_bag_load_cutout_page(int $after_ref, int $size): array
{
    return ps_query(
        "SELECT f.ref AS feature_ref, f.resource, f.region_index, f.file_checksum, f.bbox, f.mask_mtime
           FROM product_cutout_feature f
           JOIN resource r ON r.ref = f.resource AND r.file_checksum = f.file_checksum AND " . pr_bag_live_sql('r') . "
          WHERE f.status = 'ok' AND f.ref > ?
          ORDER BY f.ref LIMIT ?",
        ['i', $after_ref, 'i', $size]
    );
}

/**
 * The candidate list the service scores against: seeds, then competitors. The nightly pass and the
 * report both build it here.
 *
 * @param array<int,array<string,mixed>> $seeds usable seeds from pr_bag_load_seeds
 * @param list<array<string,mixed>> $competitors from pr_bag_load_competitors
 * @return list<array<string,mixed>>
 */
function pr_bag_build_candidates(array $seeds, array $competitors): array
{
    $out = [];
    foreach ($seeds as $seed) {
        $out[] = ['resource' => $seed['resource'], 'region_index' => $seed['region_index'],
                  'checksum' => $seed['checksum'], 'kind' => 'seed', 'product' => $seed['product'],
                  'colorway' => $seed['colorway'], 'shoot' => $seed['shoot'], 'ref' => $seed['ref']];
    }
    return array_merge($out, $competitors);
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
              'updated' => 0, 'closed' => 0, 'blocked' => 0, 'stale' => 0, 'missing_features' => 0, 'unverified_features' => 0, 'failed_writes' => 0, 'missing_candidates' => 0,
              'service_down' => false, 'disabled' => false];
    pr_bag_arm_exceptions();
    if (!$dry_run) {
        pr_bag_prepare_tables();
    }
    if (!pr_bag_enabled()) {
        $stats['disabled'] = true;
        $stats['disabled_reason'] = pr_bag_disabled_reason();
        return $stats;
    }
    pr_bag_arm_exceptions();
    if (!$dry_run) {
        $stats['dropped_features'] = pr_bag_drop_stale_pass($stats['unverified_features']);
    }
    $stats['half_applied'] = pr_bag_half_applied();
    if (!pr_service_health()) {
        $stats['service_down'] = true;
        return $stats;
    }
    $cfg = pr_bag_config();
    pr_bag_arm_exceptions();
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
    $competitors = pr_bag_load_competitors($seed_resources, $cfg);
    $stats['competitors'] = count($competitors);
    $candidates = pr_bag_build_candidates($seeds, $competitors);
    # A seed still waiting for its features will be usable soon: what was found from it stays open.
    $pending_seeds = [];
    foreach ($loaded['ignored'] as $i) {
        if ($i['reason'] === 'features_pending') {
            $pending_seeds[(int)$i['ref']] = true;
        }
    }

    # Stored decisions follow the cutout (file checksum + box), not its old index. A row that no
    # longer binds to a current cutout suppresses nothing.
    $cols = 'ref, resource, region_index, file_checksum, bbox, name_node, colorway_node, seed_ref, state';
    $decided = ps_query("SELECT $cols FROM product_bag_match WHERE state IN ('rejected','applied','open')");
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
        if ($state === 'open' && !isset($seeds[(int)$r['seed_ref']]) && !isset($pending_seeds[(int)$r['seed_ref']])) {
            $drop[] = (int)$r['ref'];      # its seed is gone, or ignored for a reason other than waiting for features
            continue;
        }
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
    $seed_facts = array_map(static fn(array $s): array => ['product' => $s['product'], 'shoot' => $s['shoot']], $seeds);

    $after = 0;
    while (true) {
        pr_bag_arm_exceptions();
        $cuts = pr_bag_load_cutout_page($after, $page);
        if ($cuts === []) {
            break;
        }
        $after = (int)$cuts[count($cuts) - 1]['feature_ref'];
        # A feature row can outlive its cutout (regions cut under an older stamp, say). A match for
        # it could never bind, so it would be opened and superseded again every night.
        $split = pr_bag_split_features($cuts, pr_bag_current_cutouts(array_map(static fn(array $c): int => (int)$c['resource'], $cuts)), 'pr_bag_mask_mtime');
        $fresh = $split['current'];
        # A row that is not its cutout any more (a re-cut landed while it was being computed) is dropped,
        # so the feature pass sees the cutout as missing and computes it again. One whose mask cannot be
        # seen is kept and unused this run.
        if (!$dry_run) {
            pr_bag_drop_stale_features($split['stale']);
        }
        $queries = [];
        foreach ($cuts as $c) {
            $key = (int)$c['resource'] . ':' . (int)$c['region_index'];
            if (!isset($fresh[$key])) {
                continue;
            }
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
        $response = pr_bag_match_request($queries, $candidates);
        if ($response === null || !isset($response['results']) || !is_array($response['results'])) {
            $stats['service_down'] = true;
            break;
        }
        $stats['missing_features'] += (int)($response['missing_queries'] ?? 0);
        $stats['missing_candidates'] += (int)($response['missing_candidates'] ?? 0);
        foreach ($queries as $at => $q) {
            $scores = $response['results'][$at] ?? null;
            $cut_key = $q['resource'] . ':' . $q['region_index'];
            if (!is_array($scores)) {
                continue;
            }
            $stats['considered']++;
            $decision = pr_bag_decide(
                ['photo' => $states[$q['resource']], 'shoot' => $q['shoot']],
                (array)($scores['colorway'] ?? []), (array)($scores['pair'] ?? []),
                $seed_facts, $open[$cut_key] ?? null, $rejected[$cut_key] ?? [], $cfg
            );
            if ($decision['action'] === 'close' && isset($pending_seeds[(int)($open[$cut_key]['seed_ref'] ?? 0)])) {
                continue;
            }
            # A row is opened only after pr_bag_pre_write on the database as it is then, not on what
            # this run read at its start.
            pr_bag_enact($decision, $q, $dry_run, $stats);
        }
    }
    # An open row for a cutout that has no features any more (re-cut, deleted) is closed by the
    # re-cut itself; one for a cutout that is now a seed or has been accepted is closed here. The
    # accepted ones are read again now: a person can accept while this run is scoring.
    if (!$dry_run && !$stats['service_down']) {
        $answered = pr_bag_answered_cutouts();
        foreach ($open as $cut_key => $row) {
            if (isset($seed_cutouts[$cut_key]) || isset($answered[$cut_key])) {
                ps_query("UPDATE product_bag_match SET state = 'superseded', decided_at = NOW() WHERE ref = ? AND state = 'open'",
                         ['i', $row['ref']]);
                $stats['closed']++;
            }
        }
    }
    return $stats;
}

/**
 * Carry out one pr_bag_decide() result. This only opens, updates or closes suggestions; it never
 * writes a tag to a photo.
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
        return;
    }
    $bbox = (string)ps_value('SELECT bbox AS value FROM product_cutout_feature WHERE resource = ? AND region_index = ?',
                             ['i', $query['resource'], 'i', $query['region_index']], '');
    $row = ['resource' => $query['resource'], 'region_index' => $query['region_index'], 'file_checksum' => $query['checksum'],
            'bbox' => $bbox, 'seed_ref' => $m['seed_ref'], 'name_node' => $m['name_node'], 'colorway_node' => $m['colorway_node']];
    if ($dry_run) {
        if (pr_bag_pre_write($row)['status'] === 'gone') {
            $stats['stale']++;
        } else {
            $stats['opened']++;
        }
        return;
    }
    # Under the photo's lock, so an Accept for this cutout cannot land between the check and the
    # insert and leave an open suggestion for a cutout that is already answered.
    db_begin_transaction('pr_bag_open');
    try {
        pr_bag_lock_photo($query['resource']);
        if (pr_bag_pre_write($row, true)['status'] === 'gone') {
            db_rollback_transaction('pr_bag_open');
            $stats['stale']++;      # what the scores said is no longer true: open nothing
            return;
        }
        if ($decision['open_ref'] > 0) {
            ps_query("UPDATE product_bag_match SET state = 'superseded', decided_at = NOW() WHERE ref = ? AND state = 'open'",
                     ['i', $decision['open_ref']]);
        }
        ps_query(
            "INSERT INTO product_bag_match (resource, region_index, file_checksum, bbox, seed_ref, name_node, colorway_node,
                                            similarity, margin, same_shoot, state)
             VALUES (?,?,?,?,?,?,?,?,?,?, 'open')",
            ['i', $query['resource'], 'i', $query['region_index'], 's', $query['checksum'], 's', (string)$bbox,
             'i', $m['seed_ref'], 'i', $m['name_node'], 'i', $m['colorway_node'], 'd', $m['similarity'],
             'd', $m['margin'], 'i', $m['same_shoot'] ? 1 : 0]
        );
        pr_bag_require_row('open match for resource ' . $query['resource'] . ' cutout ' . $query['region_index'],
            "SELECT ref FROM product_bag_match WHERE resource = ? AND file_checksum = ? AND region_index = ? AND seed_ref = ? AND colorway_node = ? AND state = 'open'",
            ['i', $query['resource'], 's', $query['checksum'], 'i', $query['region_index'], 'i', $m['seed_ref'], 'i', $m['colorway_node']]);
    } catch (PrBagRowMissing $e) {
        db_rollback_transaction('pr_bag_open');
        pr_bag_log('bag match not opened: ' . $e->getMessage());
        $stats['failed_writes']++;
        return;
    } catch (Throwable $e) {
        db_rollback_transaction('pr_bag_open');
        throw $e;
    }
    db_end_transaction('pr_bag_open');
    $stats['opened']++;
}

# ---------------------------------------------------------------------------------------------
# Accept and reject
# ---------------------------------------------------------------------------------------------

/** One line to the script log in the CLI, the PHP error log in a web request. */
function pr_bag_log(string $line): void
{
    $line = 'product_recognition: ' . $line;
    # logScript() is the command line's log (it echoes); a web request has error_log.
    PHP_SAPI === 'cli' && function_exists('logScript') ? logScript($line) : error_log($line);
}

/**
 * Serialize everything that decides a cutout of this photo. The first statement of a transaction
 * that opens or accepts a match: two accepts for different open rows of one cutout each claim their
 * own row, so only a lock they share makes the second one see the first's commit. Same order for
 * everyone, so they queue and never deadlock on each other.
 */
function pr_bag_lock_photo(int $resource): void
{
    ps_query('SELECT ref FROM resource WHERE ref = ? FOR UPDATE', ['i', $resource]);
}

/**
 * Cutouts (resource:index, as they are now) that have an accepted match: a person answered them.
 *
 * @return array<string,true>
 */
function pr_bag_answered_cutouts(): array
{
    $rows = ps_query("SELECT ref, resource, region_index, file_checksum, bbox FROM product_bag_match WHERE state = 'applied'");
    $current = pr_bag_current_cutouts(array_values(array_unique(array_map(static fn(array $r): int => (int)$r['resource'], $rows))));
    $out = [];
    foreach (pr_bag_bind_rows($rows, $current) as $r) {
        if ($r['bound'] !== null) {
            $out[(int)$r['resource'] . ':' . (int)$r['bound']] = true;
        }
    }
    return $out;
}

/**
 * The check before a match is opened or written, on the database as it is now: the seed it came
 * from, the cutout's own answer, rejections and accepted matches, the photo's tags.
 * pr_bag_pre_write_verdict decides; this only gathers the facts, so it is the one place to run
 * against a real database.
 *
 * $locking is for a caller inside a transaction that already holds pr_bag_lock_photo(): the accepted
 * matches are then read with a locking read, which sees the latest commit whatever the snapshot is.
 *
 * @param array<string,mixed> $m resource, region_index, file_checksum, bbox, seed_ref, name_node, colorway_node; ref when it is a stored row
 * @return array{status:string,reason:string,plan:?array,photo:?array,seed:?array}
 */
function pr_bag_pre_write(array $m, bool $locking = false): array
{
    $resource = (int)$m['resource'];
    $now = pr_bag_current_cutouts([$resource])[$resource] ?? null;
    $bound = pr_bag_bind_row($m, $now);
    $seed = ps_query('SELECT * FROM product_bag_tag WHERE ref = ?', ['i', (int)$m['seed_ref']])[0] ?? null;
    $seed_usable = $seed !== null && pr_bag_seed_reason_acceptable(pr_bag_seed_reason_now($seed));
    $own_seed = false;
    $rejected = false;
    $answered = false;
    $verified = false;
    if ($bound !== null) {
        $feature_rows = ps_query('SELECT ref, resource, region_index, file_checksum, bbox, mask_mtime, status, attempts FROM product_cutout_feature WHERE resource = ?',
                                 ['i', $resource]);
        $verified = isset(pr_bag_current_features($feature_rows, [$resource => $now], 'pr_bag_mask_mtime')[$resource . ':' . $bound]);
        $tags = ps_query('SELECT ref, resource, region_index, file_checksum, bbox FROM product_bag_tag WHERE resource = ?', ['i', $resource]);
        $own_seed = array_filter(pr_bag_bind_rows($tags, [$resource => $now]), static fn(array $r): bool => $r['bound'] === $bound) !== [];
        $gone = ps_query("SELECT ref, resource, region_index, file_checksum, bbox FROM product_bag_match
                           WHERE resource = ? AND state = 'rejected' AND colorway_node = ?",
                         ['i', $resource, 'i', (int)$m['colorway_node']]);
        $rejected = array_filter(pr_bag_bind_rows($gone, [$resource => $now]), static fn(array $r): bool => $r['bound'] === $bound) !== [];
        $accepted = ps_query("SELECT ref, resource, region_index, file_checksum, bbox FROM product_bag_match
                               WHERE resource = ? AND state = 'applied' AND ref <> ?" . ($locking ? ' FOR UPDATE' : ''),
                             ['i', $resource, 'i', (int)($m['ref'] ?? 0)]);
        $answered = array_filter(pr_bag_bind_rows($accepted, [$resource => $now]), static fn(array $r): bool => $r['bound'] === $bound) !== [];
    }
    $name_node = $m['name_node'] === null ? null : (int)$m['name_node'];
    $photo = pr_bag_load_photo_states([$resource])[$resource] ?? null;
    $verdict = pr_bag_pre_write_verdict(
        $bound, ['name_node' => $name_node, 'colorway_node' => (int)$m['colorway_node']], $seed, $own_seed, $rejected, $answered, $seed_usable, $photo,
        $name_node === null || pr_is_leaf_model($name_node), pr_bag_colorway_valid((int)$m['colorway_node']), $verified,
        $seed === null || pr_bag_seed_mask_readable($seed)
    );
    return $verdict + ['photo' => $photo, 'seed' => $seed];
}

/**
 * Whether the seed's own cutout mask can be seen and read right now: the seed image a reviewer compares
 * against. A seed still waiting for its features is fine when its mask reads; one whose mask cannot be
 * confirmed is not. A seed that no longer binds to a cutout is not this function's question (its reason says so).
 *
 * @param array<string,mixed> $seed a product_bag_tag row
 */
function pr_bag_seed_mask_readable(array $seed): bool
{
    $resource = (int)$seed['resource'];
    $now = pr_bag_current_cutouts([$resource])[$resource] ?? null;
    $region = $now === null || (string)$seed['file_checksum'] !== $now['file_checksum'] ? null : pr_bag_bind_row($seed, $now);
    if ($region === null) {
        return true;
    }
    $mask = pr_mask_file($resource, $now['file_checksum'], $region);
    return pr_bag_mask_stat($mask) !== null && pr_bag_png_alpha($mask) !== null;
}

/**
 * A person's Accept: write an open match to its photo. Add the Name and Colorway nodes that are
 * missing, nothing else. This is the only code that writes a tag for a match.
 *
 * Edit access is checked. Everything runs in one transaction that starts with the photo's lock
 * (pr_bag_lock_photo), so a second Accept for the same cutout waits and then finds it answered.
 * The check (pr_bag_pre_write) and the plan come first, then the claim, which already records the
 * nodes it is about to add (name_written, colorway_written: only ones the photo does not have), so
 * whatever happens next leaves an accurate record. Then the nodes, the log, and a last UPDATE that
 * sets decided_at, which the claim leaves empty.
 *
 * Two things can end a transaction early. MySQL answers a deadlock by rolling the whole transaction
 * back, and ResourceSpace's ps_query then retries only the failed statement, outside any transaction.
 * And a failed query makes ps_query run check_db_structs() first, whose DDL commits the open
 * transaction, before errorhandler() ends the request. So use_error_exception is on for the call (the
 * failure throws), the last UPDATE must prove the claim still stands, and every failure goes to
 * pr_bag_accept_recover(), which re-reads the row instead of trusting the rollback.
 *
 * After the commit, and only when a Name was added, the photo's identity fields (family, category, ...) are
 * filled the way the other accept paths do it (pr_write_accept_identity: empty fields only, and added beside the
 * existing ones on a photo with several cutouts). That runs outside the transaction and cannot undo or
 * misreport the accepted match: if it fails it is logged and $identity_failed is set for a notice.
 *
 * @param bool $identity_failed set when the match is applied but its identity fields could not be filled
 * @return 'applied'|'blocked'|'gone'|'denied'|'failed'
 */
function pr_bag_accept_match(int $match_ref, int $user, bool &$identity_failed = false): string
{
    if (!pr_bag_tables_exist()) {
        return 'failed';
    }
    $identity_failed = false;
    $rows = ps_query("SELECT * FROM product_bag_match WHERE ref = ? AND state = 'open'", ['i', $match_ref]);
    if ($rows === []) {
        return 'gone';
    }
    $m = $rows[0];
    $resource = (int)$m['resource'];
    if (!pr_can_edit_resource($resource)) {
        return 'denied';
    }
    $name_node = $m['name_node'] === null ? null : (int)$m['name_node'];
    $colorway_node = (int)$m['colorway_node'];
    $cw_field = (int)$GLOBALS['product_recognition_colorway_field'];
    $had_exception_flag = array_key_exists('use_error_exception', $GLOBALS);
    $exception_flag = $GLOBALS['use_error_exception'] ?? null;
    # ResourceSpace helpers (debug() with logging, a cached ps_query, set_sysvar, try_unlink) unset the flag, so
    # it is set again before every write instead of once.
    $arm = static function (): void {
        $GLOBALS['use_error_exception'] = true;
    };
    $arm();
    $name_missing = [];
    $colorway_missing = [];
    # If the request ends in the middle (an exit nothing can catch), the shutdown handler does what the catch
    # below would have done. It is disarmed when this call returns, so it never runs after a normal end.
    $in_flight = false;
    register_shutdown_function(static function () use (&$in_flight, $match_ref, $resource, &$name_missing, &$colorway_missing): void {
        if ($in_flight) {
            $in_flight = false;
            $GLOBALS['use_error_exception'] = true;
            pr_bag_accept_recover($match_ref, $resource, $name_missing, $colorway_missing);
        }
    });
    try {
        $in_flight = true;
        $arm();
        db_begin_transaction('pr_bag_accept');
        $arm();
        pr_bag_lock_photo($resource);
        $arm();
        $check = pr_bag_pre_write($m, true);
        if ($check['status'] !== 'ok') {
            db_rollback_transaction('pr_bag_accept');
            $arm();
            if ($check['status'] === 'gone') {
                ps_query("UPDATE product_bag_match SET state = 'superseded', decided_at = NOW() WHERE ref = ? AND state = 'open'",
                         ['i', $match_ref]);
            }
            $in_flight = false;
            return $check['status'];
        }
        $plan = $check['plan'];
        $arm();
        $name_missing = $plan['add_name'] && $name_node !== null
            ? array_values(array_diff(pr_nodes_to_apply($name_node), pr_resource_field_nodes($resource, pr_product_field()))) : [];
        $colorway_missing = $plan['add_colorway']
            ? array_values(array_diff(pr_model_nodes_with_ancestors($colorway_node), pr_resource_field_nodes($resource, $cw_field))) : [];
        $arm();
        ps_query("UPDATE product_bag_match SET state = 'applied', decided_by = ?, decided_at = NULL, name_written = ?, colorway_written = ?
                   WHERE ref = ? AND state = 'open'",
                 ['i', $user, 's', pr_bag_ids_csv($name_missing), 's', pr_bag_ids_csv($colorway_missing), 'i', $match_ref]);
        if (sql_affected_rows() !== 1) {
            db_rollback_transaction('pr_bag_accept');
            $in_flight = false;
            return 'gone';
        }
        $note = sprintf(
            'Product recognition bag match %d accepted: seed tag %d, similarity %.3f, margin %s, %s',
            $match_ref, (int)$m['seed_ref'], (float)$m['similarity'],
            $m['margin'] === null ? 'n/a' : sprintf('%.3f', (float)$m['margin']),
            $name_node === null ? 'colorway' : 'product+colorway'
        );
        if ($name_missing !== []) {
            $arm();
            add_resource_nodes($resource, $name_missing, false, false);
            $arm();
            resource_log($resource, LOG_CODE_EDITED, pr_product_field(), $note);
        }
        if ($colorway_missing !== []) {
            $arm();
            add_resource_nodes($resource, $colorway_missing, false, false);
            $arm();
            resource_log($resource, LOG_CODE_EDITED, $cw_field, $note);
        }
        $arm();
        ps_query("UPDATE product_bag_match SET decided_at = NOW()
                   WHERE ref = ? AND state = 'applied' AND decided_by = ? AND decided_at IS NULL",
                 ['i', $match_ref, 'i', $user]);
        if (sql_affected_rows() !== 1) {
            $in_flight = false;
            return pr_bag_accept_recover($match_ref, $resource, $name_missing, $colorway_missing);
        }
        $arm();
        db_end_transaction('pr_bag_accept');
        $in_flight = false;
        if ($name_missing !== [] && $name_node !== null) {
            try {
                pr_write_accept_identity($resource, $name_node, '', pr_stored_region_count($resource) > 1 ? 'add' : 'keep');
            } catch (Throwable $e) {
                $identity_failed = true;
                pr_bag_log('bag match ' . $match_ref . ' accepted, but its identity fields were not filled: ' . $e->getMessage());
            }
        }
        return 'applied';
    } catch (Throwable $e) {
        $in_flight = false;
        $arm();
        pr_bag_log('bag match ' . $match_ref . ' accept failed: ' . $e->getMessage());
        return pr_bag_accept_recover($match_ref, $resource, $name_missing, $colorway_missing);
    } finally {
        $in_flight = false;
        if ($had_exception_flag) {
            $GLOBALS['use_error_exception'] = $exception_flag;
        } else {
            unset($GLOBALS['use_error_exception']);
        }
    }
}

/**
 * After a failed Accept: roll back if there is anything to roll back, then read the row again and act on
 * what is there (pr_bag_accept_recovery). Takes back only the nodes the Accept planned to add, and never
 * one that another accepted match on the photo wrote or stands on. Never throws.
 *
 * @param list<int> $name_planned
 * @param list<int> $colorway_planned
 * @return 'applied'|'failed'
 */
function pr_bag_accept_recover(int $match_ref, int $resource, array $name_planned, array $colorway_planned): string
{
    # Every step stands alone: one that fails is logged and the next still runs. The log line comes last, so
    # a failing log write can never leave the row applied with its nodes gone.
    $step = static function (callable $do) use ($match_ref) {
        $GLOBALS['use_error_exception'] = true;
        try {
            return $do();
        } catch (Throwable $e) {
            pr_bag_log('bag match ' . $match_ref . ' accept recovery step failed, check the row by hand: ' . $e->getMessage());
            return null;
        }
    };
    # Nothing to roll back when an implicit commit ended the transaction: the re-read says what is left.
    $step(static fn() => db_rollback_transaction('pr_bag_accept'));
    $read = $step(static fn() => ps_query('SELECT state, decided_at, decided_by FROM product_bag_match WHERE ref = ?', ['i', $match_ref]));
    if ($read === null) {
        return 'failed';      # cannot tell what state the row is in: touch nothing
    }
    $row = $read[0] ?? null;
    $action = pr_bag_accept_recovery($row === null ? null : ['state' => (string)$row['state'], 'decided_at_set' => $row['decided_at'] !== null]);
    if ($action === 'completed') {
        return 'applied';
    }
    $planned = array_values(array_unique(array_merge($name_planned, $colorway_planned)));
    # 'clean' (the claim was rolled back) takes the same path as 'reopen' below, minus the reopen: ps_query retries a
    # failed statement in autocommit after MySQL rolled the transaction back, so the planned nodes (absent before the
    # claim, planned under the photo's lock) can be on the photo with no claim to say so. The protect set keeps every
    # node another applied match wrote or stands on.
    $protect = [];
    $others = $step(static fn() => ps_query("SELECT name_node, colorway_node, name_written, colorway_written FROM product_bag_match
                                              WHERE resource = ? AND state = 'applied' AND ref <> ?", ['i', $resource, 'i', $match_ref]));
    foreach ($others ?? [] as $other) {
        $protect = array_merge(
            $protect, pr_bag_csv_ids((string)$other['name_written']), pr_bag_csv_ids((string)$other['colorway_written']),
            $other['name_node'] === null ? [] : pr_nodes_to_apply((int)$other['name_node']),
            pr_model_nodes_with_ancestors((int)$other['colorway_node'])
        );
    }
    $remove = [];
    if ($others !== null && $planned !== []) {
        $present = $step(static fn() => pr_bag_nodes_on_photo($resource, $planned));
        $remove = $present === null ? [] : pr_bag_recover_plan($planned, $present, $protect)['remove'];
    }
    $removed = $remove === [] || $step(static function () use ($resource, $remove) {
        delete_resource_nodes($resource, $remove, false);
        return true;
    }) === true;
    if ($action === 'reopen' && $removed) {
        $step(static fn() => ps_query("UPDATE product_bag_match SET state = 'open', decided_by = NULL, decided_at = NULL, name_written = '', colorway_written = ''
                                        WHERE ref = ? AND state = 'applied' AND decided_at IS NULL", ['i', $match_ref]));
    }
    if ($remove !== [] && $removed) {
        $step(static fn() => resource_log($resource, LOG_CODE_EDITED, 0, sprintf('Product recognition bag match %d: accept did not complete, removed nodes %s',
                                                                                    $match_ref, pr_bag_ids_csv($remove))));
    }
    return 'failed';
}

/** @return 'rejected'|'denied'|'gone' */
function pr_bag_reject_match(int $match_ref, int $user): string
{
    if (!pr_bag_tables_exist()) {
        return 'gone';
    }
    $rows = ps_query("SELECT resource FROM product_bag_match WHERE ref = ? AND state = 'open'", ['i', $match_ref]);
    if ($rows === []) {
        return 'gone';
    }
    if (!pr_can_edit_resource((int)$rows[0]['resource'])) {
        return 'denied';
    }
    ps_query("UPDATE product_bag_match SET state = 'rejected', decided_by = ?, decided_at = NOW() WHERE ref = ? AND state = 'open'",
             ['i', $user, 'i', $match_ref]);
    # Someone else decided it between the read and the write: say so, not that this call did.
    return sql_affected_rows() === 1 ? 'rejected' : 'gone';
}

/**
 * Which of these node ids are on the photo, whatever field they belong to.
 *
 * @param list<int> $nodes
 * @return list<int>
 */
function pr_bag_nodes_on_photo(int $resource, array $nodes): array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique($nodes)), 500) as $chunk) {
        foreach (ps_query(
            "SELECT node FROM resource_node WHERE resource = ? AND node IN (" . pr_bag_marks($chunk) . ")",
            array_merge(['i', $resource], pr_bag_int_params($chunk))
        ) as $row) {
            $out[] = (int)$row['node'];
        }
    }
    return $out;
}

# ---------------------------------------------------------------------------------------------
# --bag-match-report
# ---------------------------------------------------------------------------------------------

/**
 * Read-only measurement on the seeds: each seed against all the others (leave-one-out, near
 * copies removed as prop3 did), and again with its own colorway hidden. The held-out half is
 * the shoots whose key checksum is odd; the verdict is on that half only.
 *
 * It runs the nightly pass's code: the same seeds, competitors and candidate list
 * (pr_bag_build_candidates), the same service call, the same pool result and gate numbers. The
 * hidden colorway is removed from seeds AND competitors by the service. A hidden query with no
 * margin cannot be tested against the gate and is counted as 'unmeasured', never as safe; the
 * verdict needs a minimum number of measurable ones.
 *
 * The near-copy test covers the seed cutouts of a photo, not every cutout the photo has.
 *
 * @param callable(array):void $on_row
 * @return array{tally:array<string,array<string,int>>,seeds:int,ignored:list<array>,half_applied:list<int>,verdict:array{ok:bool,reasons:list<string>},error:?string}
 */
function pr_bag_report(callable $on_row): array
{
    $cfg = pr_bag_config();
    if (pr_bag_disabled_reason() !== '') {
        return ['tally' => [], 'seeds' => 0, 'ignored' => [], 'error' => pr_bag_disabled_text(pr_bag_disabled_reason()),
                'verdict' => pr_bag_report_verdict([], $cfg['report_min_hidden'])];
    }
    $loaded = pr_bag_load_seeds($cfg);
    $seeds = array_values($loaded['usable']);
    $out = ['tally' => [], 'seeds' => count($seeds), 'ignored' => $loaded['ignored'], 'error' => null,
            'half_applied' => pr_bag_half_applied(), 'verdict' => ['ok' => false, 'reasons' => []]];
    if (count($seeds) < 2) {
        $out['error'] = 'fewer than two usable seeds';
        $out['verdict'] = pr_bag_report_verdict([], $cfg['report_min_hidden']);
        return $out;
    }
    if (!pr_service_health()) {
        $out['error'] = 'service did not answer';
        return $out;
    }
    $seed_resources = [];
    foreach ($seeds as $seed) {
        $seed_resources[$seed['resource']] = true;
    }
    $candidates = pr_bag_build_candidates($seeds, pr_bag_load_competitors($seed_resources, $cfg));
    $modes = ['loo' => false, 'hidden' => true];
    $missing = 0;      # queries or candidates the service had no features for: the numbers below leave them out
    foreach ($modes as $mode => $hide) {
        foreach (pr_bag_report_chunks($seeds) as $chunk) {
            $queries = array_map(static fn(array $s): array => [
                'resource' => $s['resource'], 'region_index' => $s['region_index'], 'checksum' => $s['checksum'],
                'shoot' => $s['shoot'], 'products' => [$s['product']], 'hide_colorway' => $hide ? $s['colorway'] : 0,
            ], $chunk);
            $response = pr_bag_match_request($queries, $candidates, ['near_copy' => 0.95]);
            if ($response === null || !isset($response['results'])) {
                $out['error'] = 'service did not answer';
                return $out;
            }
            $missing += (int)($response['missing_queries'] ?? 0) + (int)($response['missing_candidates'] ?? 0);
            foreach ($chunk as $at => $seed) {
                $scores = $response['results'][$at] ?? null;
                if (!is_array($scores)) {
                    continue;
                }
                $held = pr_bag_report_held_out($seed['shoot'] !== '' ? $seed['shoot'] : 'resource-' . $seed['resource']);
                foreach (['colorway' => (string)$seed['colorway'], 'pair' => $seed['product'] . '|' . $seed['colorway']] as $gate => $truth) {
                    $result = pr_bag_pool_result((array)($scores[$gate] ?? []));
                    $outcome = pr_bag_report_outcome($gate, $result, $truth, $cfg, $hide);
                    if ($held) {
                        $tally = &$out['tally']["$mode:$gate"];
                        $tally ??= ['right' => 0, 'wrong' => 0, 'none' => 0, 'unmeasured' => 0];
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
    $out['verdict'] = pr_bag_report_verdict($out['tally'], $cfg['report_min_hidden']);
    $out['missing'] = $missing;
    if ($missing > 0) {
        # The service scored fewer cutouts than were asked about, so the tally is of something smaller than the
        # pool the nightly pass uses: no verdict from it.
        $out['verdict']['ok'] = false;
        $out['verdict']['reasons'][] = sprintf('the service had no features for %d queried or candidate cutouts; run --cutout-features and measure again', $missing);
    }
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
    # ResourceSpace's ps_query ends a CLI run with exit() on a SQL error, which no try/catch sees and
    # which loses the night's summary. With use_error_exception it throws instead (errorhandler()). Some
    # ResourceSpace helpers unset the flag, so it is set again before each stage; pr_run_all() also keeps
    # the earlier stages' summary and last_run for a bag stage that still ends the process.
    $had_exception_flag = array_key_exists('use_error_exception', $GLOBALS);
    $exception_flag = $GLOBALS['use_error_exception'] ?? null;
    $GLOBALS['use_error_exception'] = true;
    try {
        try {
            $GLOBALS['use_error_exception'] = true;
            $features = pr_bag_backfill_features($cfg['batch']);
        } catch (Throwable $e) {
            $features = ['error' => 'bag features threw: ' . $e->getMessage()];
        }
        try {
            $GLOBALS['use_error_exception'] = true;
            $match = pr_bag_run_match();
        } catch (Throwable $e) {
            $match = ['error' => 'bag match threw: ' . $e->getMessage()];
        }
    } finally {
        if ($had_exception_flag) {
            $GLOBALS['use_error_exception'] = $exception_flag;
        } else {
            unset($GLOBALS['use_error_exception']);
        }
    }
    return ['features' => $features, 'match' => $match];
}

# ---------------------------------------------------------------------------------------------
# Screens: the resource panel and the review tab
# ---------------------------------------------------------------------------------------------

/**
 * Why an open match cannot be accepted on its photo as it is now, or '' when it can: the write plan's
 * reason, or 'product_gone' / 'colorway_gone' when its Product or Colorway is not a leaf choice any more.
 * The leaf lists can be passed in, so a page of rows loads them once. The review tab and the panel
 * both use it, so neither offers Accept on a row that Accept would refuse.
 *
 * @param array{products:list<int>,colorways:list<int>,cutouts:int} $photo
 */
function pr_bag_match_blocked(array $photo, ?int $name_node, int $colorway_node, int $seed_name_node,
                              ?array $product_leaves = null, ?array $colorway_leaves = null): string
{
    $product_leaves ??= pr_leaf_models();
    $colorway_leaves ??= pr_field_leaves((int)($GLOBALS['product_recognition_colorway_field'] ?? 0));
    $blocked = pr_bag_write_plan($photo, $name_node, $colorway_node, $seed_name_node)['blocked'];
    if ($blocked !== '') {
        return $blocked;
    }
    if ($name_node !== null && !isset($product_leaves[$name_node])) {
        return 'product_gone';
    }
    return isset($colorway_leaves[$colorway_node]) ? '' : 'colorway_gone';
}

/**
 * What the panel shows beside each cutout of one photo: the seed a person saved for it, and an
 * open match found for it.
 *
 * @return array<int,array{seed:?array{product:string,colorway:string},match:?array{ref:int,product:string,colorway:string,similarity:float,blocked:string}}>
 */
function pr_bag_panel_state(int $resource, string $checksum): array
{
    $out = [];
    $photo = null;
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
        "SELECT m.ref, m.resource, m.region_index, m.file_checksum, m.bbox, m.similarity, m.name_node, m.colorway_node,
                t.name_node AS seed_name_node, nn.name AS product, cn.name AS colorway
           FROM product_bag_match m
           JOIN product_bag_tag t ON t.ref = m.seed_ref
           LEFT JOIN node nn ON nn.ref = m.name_node JOIN node cn ON cn.ref = m.colorway_node
          WHERE m.resource = ? AND m.state = 'open'",
        ['i', $resource]
    ), $current) as $row) {
        if ($row['bound'] !== null && $row['newest']) {
            $photo ??= pr_bag_load_photo_states([$resource])[$resource];
            $out[$row['bound']]['match'] = [
                'ref' => (int)$row['ref'], 'product' => (string)($row['product'] ?? ''),
                'colorway' => (string)$row['colorway'], 'similarity' => (float)$row['similarity'],
                'blocked' => pr_bag_match_blocked($photo, $row['name_node'] === null ? null : (int)$row['name_node'],
                                                  (int)$row['colorway_node'], (int)$row['seed_name_node']),
            ];
        }
    }
    foreach ($out as $region => $parts) {
        $out[$region] += ['seed' => null, 'match' => null];
    }
    return $out;
}

/**
 * Open matches for the review tab: the ones Accept can write first, then the blocked ones, each group
 * best first. Active resources the person can open only, as pr_open_suggestions does. Blocked is
 * worked out for every row before the page is cut, so a long run of blocked rows never hides an
 * acceptable one behind it; access is asked only as far down that order as the page reaches. total and
 * acceptable count what was walked, and 'more' says there is a row beyond the page.
 *
 * @return array{rows:list<array<string,mixed>>,total:int,acceptable:int,more:bool}
 */
function pr_bag_open_matches(int $limit = 100, int $offset = 0): array
{
    $rows = ps_query(
        "SELECT m.ref, m.resource, m.region_index, m.file_checksum, m.bbox, m.similarity, m.margin, m.same_shoot, m.seed_ref, m.name_node,
                m.colorway_node, nn.name AS product, cn.name AS colorway,
                t.name_node AS seed_name_node, t.resource AS seed_resource, t.region_index AS seed_region, t.file_checksum AS seed_checksum,
                t.bbox AS seed_bbox, sn.name AS seed_product, sc.name AS seed_colorway
           FROM product_bag_match m
           JOIN resource r ON r.ref = m.resource AND " . pr_bag_live_sql('r') . "
           JOIN product_bag_tag t ON t.ref = m.seed_ref
           JOIN node cn ON cn.ref = m.colorway_node
           JOIN node sn ON sn.ref = t.name_node JOIN node sc ON sc.ref = t.colorway_node
           LEFT JOIN node nn ON nn.ref = m.name_node
          WHERE m.state = 'open'
          ORDER BY m.similarity DESC, m.ref"
    );
    $resources = array_values(array_unique(array_map(static fn(array $r): int => (int)$r['resource'], $rows)));
    # Only matches whose cutout (file checksum + box) is still on the photo; shown at its current index.
    $match_cuts = pr_bag_current_cutouts($resources);
    $bound = [];
    foreach (pr_bag_bind_rows($rows, $match_cuts) as $r) {
        if ($r['bound'] === null || !$r['newest']) {
            continue;
        }
        $r['region_index'] = $r['bound'];
        $bound[] = $r;
    }
    # Order first, from the database alone (acceptable before blocked, best first), then walk that order and
    # ask for access only as far as the page needs: one answer per photo, never one for every open row.
    $states = pr_bag_load_photo_states(array_values(array_unique(array_map(static fn(array $r): int => (int)$r['resource'], $bound))));
    $good = [];
    $blocked = [];
    $product_leaves = pr_leaf_models();
    $colorway_leaves = pr_field_leaves((int)($GLOBALS['product_recognition_colorway_field'] ?? 0));
    foreach ($bound as $r) {
        $r['blocked'] = pr_bag_match_blocked($states[(int)$r['resource']], $r['name_node'] === null ? null : (int)$r['name_node'],
                                             (int)$r['colorway_node'], (int)$r['seed_name_node'], $product_leaves, $colorway_leaves);
        if ($r['blocked'] === '') {
            $good[] = $r;
        } else {
            $blocked[] = $r;
        }
    }
    $access = [];
    $page = [];
    $seen = 0;
    $seen_good = 0;
    $more = false;
    foreach (array_merge($good, $blocked) as $r) {
        $res = (int)$r['resource'];
        $access[$res] ??= !function_exists('get_resource_access') || get_resource_access($res) <= 1;
        if (!$access[$res]) {
            continue;
        }
        if ($seen >= max(0, $offset) + $limit) {
            $more = true;
            break;
        }
        if ($seen >= max(0, $offset)) {
            $page[] = $r;
        }
        $seen++;
        $seen_good += $r['blocked'] === '' ? 1 : 0;
    }
    $seed_cuts = pr_bag_current_cutouts(array_values(array_unique(array_map(static fn(array $r): int => (int)$r['seed_resource'], $page))));
    $cfg = pr_bag_config();
    $withheld = pr_nodes_withheld_from_auto_apply();
    $out = [];
    $seed_access = [];
    foreach ($page as $r) {
        $now = $seed_cuts[(int)$r['seed_resource']] ?? null;
        # The seed's photo can be one this person may not open: its thumbnail and label stay out of the row then.
        $seed_res = (int)$r['seed_resource'];
        $seed_access[$seed_res] ??= !function_exists('get_resource_access') || get_resource_access($seed_res) <= 1;
        $seed_region = pr_bag_bind_row(['region_index' => $r['seed_region'], 'file_checksum' => $r['seed_checksum'], 'bbox' => $r['seed_bbox']], $now);
        # Which strict gate the numbers pass, and whether it is also the kind a later automatic step
        # could write. Shown, never acted on.
        $gate = $r['name_node'] === null ? 'colorway' : 'pair';
        $same_shoot = (int)$r['same_shoot'] === 1;
        $passes = pr_bag_gate_passes($gate, (float)$r['similarity'], $r['margin'] === null ? null : (float)$r['margin'], $cfg);
        $out[] = $r + ['gate' => $passes ? $gate : '',
                       'gate_auto' => $passes && $r['blocked'] === ''
                           && pr_bag_auto_ok($gate, $states[(int)$r['resource']], (int)$r['seed_name_node'], $same_shoot, $withheld),
                       'seed_visible' => $seed_access[$seed_res],
                       'seed_current_region' => $seed_region,
                       'seed_current_checksum' => $now['file_checksum'] ?? ''];
    }
    return ['rows' => $out, 'total' => $seen, 'acceptable' => $seen_good, 'more' => $more];
}

/**
 * The review tab body. Output is escaped here; the page owns the frame and the notices.
 */
function pr_bag_render_review(): void
{
    global $lang, $baseurl;

    if (!pr_bag_tables_exist()) {
        echo '<p>' . escape($lang['product_recognition_bag_no_tables']) . '</p>';
        return;
    }
    $per_page = 100;
    $page_req = max(1, (int)getval('bagpage', 1));
    $found = pr_bag_open_matches($per_page, ($page_req - 1) * $per_page);
    if ($found['rows'] === [] && $page_req > 1) {
        $page_req = 1;      # a page past the end
        $found = pr_bag_open_matches($per_page, 0);
    }
    $rows = $found['rows'];
    if ($rows === []) {
        echo '<p>' . escape($lang['product_recognition_bag_none']) . '</p>';
        return;
    }
    $review = $baseurl . '/plugins/product_recognition/pages/review.php';
    $page_now = $page_req;
    $pages = $found['more'] ? $page_now + 1 : $page_now;
    echo '<p>' . escape(sprintf($found['more'] ? $lang['product_recognition_bag_count_more'] : $lang['product_recognition_bag_count'],
                                $found['total'], $found['acceptable'])) . '</p>';
    $mask_url = static function (int $resource, int $region, string $checksum) use ($baseurl): string {
        # The mask's mtime is part of the URL: the page caches a mask for a day, and a rewritten one is another URL.
        return generateURL($baseurl . '/plugins/product_recognition/pages/mask.php',
                           ['ref' => $resource, 'i' => $region, 'c' => substr($checksum, 0, 12),
                            'm' => (int)pr_bag_mask_stat(pr_mask_file($resource, $checksum, $region))]);
    };
    foreach ($rows as $r) {
        $resource = (int)$r['resource'];
        $product = (string)($r['product'] ?? '') !== '' ? (string)$r['product'] : $lang['product_recognition_bag_product_on_photo'];
        ?>
        <div class="ListviewStyle" style="margin-bottom:1em;">
            <div style="display:flex;gap:1em;align-items:flex-start;flex-wrap:wrap;">
                <figure style="margin:0;">
                    <a href="<?php echo escape($baseurl . '/pages/view.php?ref=' . $resource); ?>" target="_blank">
                        <img src="<?php echo escape($mask_url($resource, (int)$r['region_index'], (string)$r['file_checksum'])); ?>"
                             alt="" style="max-height:180px;max-width:220px;" loading="lazy" />
                    </a>
                    <figcaption><?php echo escape($lang['product_recognition_bag_this_cutout']); ?></figcaption>
                </figure>
                <?php if ($r['seed_visible']) { ?>
                <figure style="margin:0;">
                    <?php if ($r['seed_current_region'] !== null) { ?>
                    <a href="<?php echo escape($baseurl . '/pages/view.php?ref=' . (int)$r['seed_resource']); ?>" target="_blank">
                        <img src="<?php echo escape($mask_url((int)$r['seed_resource'], (int)$r['seed_current_region'], (string)$r['seed_current_checksum'])); ?>"
                             alt="" style="max-height:180px;max-width:220px;" loading="lazy" />
                    </a>
                    <?php } ?>
                    <figcaption><?php echo escape(sprintf($lang['product_recognition_bag_seed_caption'], $r['seed_product'], $r['seed_colorway'])); ?></figcaption>
                </figure>
                <?php } ?>
                <div>
                    <p><strong><?php echo escape($product); ?></strong> / <strong><?php echo escape((string)$r['colorway']); ?></strong></p>
                    <p><?php echo escape($lang['product_recognition_bag_similarity']); ?>
                       <?php echo escape(sprintf('%.0f%%', 100 * (float)$r['similarity'])); ?>,
                       <?php echo escape($lang['product_recognition_bag_margin']); ?>
                       <?php echo $r['margin'] === null ? escape($lang['product_recognition_bag_no_rival']) : escape(sprintf('%.0f%%', 100 * (float)$r['margin'])); ?>,
                       <?php echo escape((int)$r['same_shoot'] === 1 ? $lang['product_recognition_bag_same_shoot'] : $lang['product_recognition_bag_other_shoot']); ?></p>
                    <p><?php
                        echo escape($r['gate'] === '' ? $lang['product_recognition_bag_gate_none']
                            : sprintf($r['gate_auto'] ? $lang['product_recognition_bag_gate_auto'] : $lang['product_recognition_bag_gate_passed'],
                                      $lang['product_recognition_bag_gate_' . $r['gate']]));
                    ?></p>
                    <?php if ($r['blocked'] !== '') { ?>
                    <p><?php echo escape($lang['product_recognition_bag_blocked_' . $r['blocked']] ?? $lang['product_recognition_bag_blocked']); ?></p>
                    <?php } ?>
                    <form method="post" action="<?php echo escape($review); ?>" style="display:inline;">
                        <?php generateFormToken('pr_bag_review'); ?>
                        <input type="hidden" name="tab" value="bags" />
                        <input type="hidden" name="bagpage" value="<?php echo (int)$page_now; ?>" />
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
    if ($pages > 1) {
        echo '<p>';
        foreach ([$page_now - 1 => 'product_recognition_bag_prev', $page_now + 1 => 'product_recognition_bag_next'] as $to => $label) {
            if ($to >= 1 && $to <= $pages) {
                echo '<a href="' . escape(generateURL($review, ['tab' => 'bags', 'bagpage' => $to])) . '">' . escape($lang[$label]) . '</a> ';
            }
        }
        echo escape(sprintf($lang['product_recognition_bag_page'], $page_now, $pages)) . '</p>';
    }
}
