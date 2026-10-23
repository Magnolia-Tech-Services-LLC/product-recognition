<?php
/**
 * Master Product List refresh and Dropbox-path head start.
 *
 * The grammar in product-category-map.php interprets a row. This file does not
 * copy that map. A resource view never calls Google. Nightly --all and the CLI do.
 *
 * Studio / ecomm / JPEG_HR paths can fill empty metadata. A path that names one
 * product becomes a Name reference only after leftover tree references are gone.
 * Until then the pixel index stays on the field classify still treats as the
 * product field. A path that only names a family (Pacific, not Pacific Cart Bag)
 * does not become a reference.
 */

const PR_SHEET_ID = '1tqzCrB3sQ-bhSdnggF9nrvLv6V3N0uWfhli7jRQFv3M';
const PR_SHEET_MIN_SELLABLE = 200;

function pr_sheet_credential_path(): string
{
    $configured = trim((string)($GLOBALS['product_recognition_sheet_credentials'] ?? ''));
    $env = getenv('PRODUCT_RECOGNITION_SHEET_JSON');
    $paths = [];
    if ($configured !== '') {
        $paths[] = $configured;
    }
    if (is_string($env) && $env !== '') {
        $paths[] = $env;
    }
    # Not under the web root. persist/ and filestore/ are mounted there.
    $paths[] = '/opt/rs-defaults/google-sheets.json';
    foreach ($paths as $path) {
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return '';
}

/**
 * @param list<array{source:string,sku:string,category:string,product_name:string,family:string,color:string,status:string}> $rows
 * @return array{identities:array<string,array>,skipped:int,sellable:int,smu:int}
 */
function pr_sheet_interpret_rows(array $rows): array
{
    $out = ['identities' => [], 'skipped' => 0, 'sellable' => 0, 'smu' => 0];
    if (!pr_load_identity_grammar()) {
        $out['skipped'] = count($rows);
        return $out;
    }
    $baked = product_identity_by_model();
    $grouped = [];
    foreach ($rows as $row) {
        $source = (string)($row['source'] ?? '');
        if ($source === 'sellable') {
            $out['sellable']++;
        } elseif ($source === 'smu') {
            $out['smu']++;
        }
        $category = trim((string)($row['category'] ?? ''));
        $product = trim((string)($row['product_name'] ?? ''));
        $family = trim((string)($row['family'] ?? ''));
        if ($category === '' && $product === '' && $family === '') {
            if ($source === 'sellable') {
                $out['sellable']--;
            } elseif ($source === 'smu') {
                $out['smu']--;
            }
            continue;
        }
        $identity = product_identity_from_sheet(
            $category,
            $family,
            $product,
            trim((string)($row['color'] ?? ''))
        );
        if (pr_sheet_reject_reason($identity, (string)($row['status'] ?? ''), $product, $baked) !== null) {
            $out['skipped']++;
            continue;
        }
        $name = pr_sheet_bind_name((string)$identity['name'], $baked);
        $identity['name'] = $name;
        $grouped[$name][] = $identity;
    }
    foreach ($grouped as $name => $ids) {
        $base = $ids[0];
        $conflict = false;
        foreach ($ids as $id) {
            if ($id['family'] !== $base['family']
                || $id['category'] !== $base['category']
                || (string)($id['edition'] ?? '') !== (string)($base['edition'] ?? '')) {
                $conflict = true;
                break;
            }
            if (($base['collaboration'] ?? '') === '' && ($id['collaboration'] ?? '') !== '') {
                $base['collaboration'] = $id['collaboration'];
            }
        }
        if ($conflict || pr_is_ignored_product_label($name)) {
            $out['skipped'] += count($ids);
            continue;
        }
        $out['identities'][$name] = $base;
    }
    return $out;
}

function pr_sheet_bind_name(string $name, array $baked): string
{
    $want = pr_fold_label($name);
    if ($want === '') {
        return $name;
    }
    $hits = [];
    foreach ($baked as $key => $_row) {
        if (pr_fold_label((string)$key) === $want) {
            $hits[] = (string)$key;
        }
    }
    return count($hits) === 1 ? $hits[0] : $name;
}

function pr_sheet_reject_reason(?array $identity, string $status, string $product, array $baked): ?string
{
    if ($identity === null) {
        return 'not a product';
    }
    $status = strtolower(trim($status));
    if (!in_array($status, ['active', 'depleting'], true)) {
        return 'status';
    }
    $name = trim((string)($identity['name'] ?? ''));
    $family = trim((string)($identity['family'] ?? ''));
    if ($name === '' || strlen($name) > 40) {
        return 'name';
    }
    if (preg_match('/\(|\)|\/|costco|dropship|mystery|tjx|tj maxx|packaging/i', $name . ' ' . $product)) {
        return 'junk';
    }
    if (preg_match('/-\s|\s-\S/u', $name)) {
        return 'hyphen';
    }
    if (preg_match('/\b(themed|sample|swatch|promotional)\b/i', $name)) {
        return 'theme';
    }
    if (in_array(pr_fold_label($family), ['promotional', 'mystery'], true)) {
        return 'family';
    }
    $fold = pr_fold_label($name);
    if (str_contains($fold, 'recess 5 8') || str_contains($fold, 'recess 9 12')) {
        return 'recess';
    }
    if (pr_is_ignored_product_label($name)) {
        return 'ignored';
    }
    if (function_exists('sg_collaboration_from_name')) {
        $collab = sg_collaboration_from_name($name);
        if ($collab !== '' && pr_fold_label($collab) === $fold) {
            return 'collab';
        }
    }
    $bound = pr_sheet_bind_name($name, $baked);
    if (isset($baked[$bound])) {
        return null;
    }
    if (pr_sheet_shares_token($name, $family) || pr_fold_label($name) === pr_fold_label($family)) {
        return null;
    }
    $name_fold = pr_fold_label($name);
    $family_fold = pr_fold_label($family);
    if ($family_fold !== '' && str_starts_with($name_fold, $family_fold . ' ')) {
        return null;
    }
    $known_family = false;
    if (function_exists('product_family_values')) {
        foreach (product_family_values() as $known) {
            if (pr_fold_label((string)$known) === $family_fold) {
                $known_family = true;
                break;
            }
        }
    }
    $words = $name_fold === '' ? 0 : count(explode(' ', $name_fold));
    if ($known_family && $words >= 1 && $words <= 4) {
        return null;
    }
    return 'unbound';
}

function pr_sheet_shares_token(string $name, string $family): bool
{
    $have = array_flip(array_filter(explode(' ', pr_fold_label($name))));
    foreach (explode(' ', pr_fold_label($family)) as $token) {
        if ($token !== '' && isset($have[$token])) {
            return true;
        }
    }
    return false;
}

function pr_sheet_pull_refused(int $previous_sellable, int $sellable, bool $fetch_ok): ?string
{
    if (!$fetch_ok) {
        return 'sheet fetch failed';
    }
    if ($previous_sellable > 0 && $sellable < (int)floor($previous_sellable * 0.8)) {
        return 'sheet pull dropped sharply';
    }
    if ($previous_sellable === 0 && $sellable < PR_SHEET_MIN_SELLABLE) {
        return 'sheet pull is too small';
    }
    return null;
}

/**
 * Baked grammar plus the last accepted sheet import. Sheet wins on a name it
 * still lists. A name the sheet does not mention stays on the baked map.
 *
 * @return array<string,array>
 */
function pr_identity_catalog(bool $reset = false): array
{
    static $map = null;
    if ($reset) {
        $map = null;
        pr_sheet_stored_identities(true);
        return [];
    }
    if ($map !== null) {
        return $map;
    }
    if (!pr_load_identity_grammar()) {
        $map = [];
        return $map;
    }
    $map = pr_sheet_merge_catalog(product_identity_by_model(), pr_sheet_stored_identities());
    return $map;
}

function pr_sheet_merge_catalog(array $baked, array $imported): array
{
    foreach ($imported as $name => $row) {
        $baked[$name] = $row;
    }
    return $baked;
}

function pr_sheet_table_ready(): bool
{
    if (!function_exists('ps_value')) {
        return false;
    }
    return (int)ps_value(
        "SELECT COUNT(*) AS value FROM information_schema.tables
          WHERE table_schema = DATABASE()
            AND table_name IN ('product_sheet_state', 'product_sheet_row')",
        [],
        0
    ) === 2;
}

/**
 * Create the sheet tables when they are absent.
 *
 * dbstruct lists NOT NULL, and CheckDBStruct ignores that flag, so a missing-table
 * install would otherwise come up nullable. This statement matches the live tables.
 * Existing tables are left alone. Do not call check_db_structs() from here.
 */
function pr_sheet_ensure_tables(): void
{
    if (!function_exists('ps_query') || pr_sheet_table_ready()) {
        return;
    }
    ps_query(
        "CREATE TABLE IF NOT EXISTS product_sheet_row (
            row_key char(40) NOT NULL,
            source varchar(16) NOT NULL DEFAULT '',
            sku varchar(64) NOT NULL DEFAULT '',
            category varchar(255) NOT NULL DEFAULT '',
            product_name varchar(255) NOT NULL DEFAULT '',
            family varchar(255) NOT NULL DEFAULT '',
            color varchar(255) NOT NULL DEFAULT '',
            sheet_status varchar(32) NOT NULL DEFAULT '',
            name varchar(255) NOT NULL DEFAULT '',
            edition varchar(64) NOT NULL DEFAULT '',
            collaboration varchar(255) NOT NULL DEFAULT '',
            clubtype varchar(64) NOT NULL DEFAULT '',
            retailchannel varchar(64) NOT NULL DEFAULT '',
            imported tinyint(1) NOT NULL DEFAULT '0',
            PRIMARY KEY (row_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
    );
    ps_query(
        "CREATE TABLE IF NOT EXISTS product_sheet_state (
            id tinyint NOT NULL,
            content_hash char(64) NOT NULL DEFAULT '',
            sellable_rows int NOT NULL DEFAULT '0',
            smu_rows int NOT NULL DEFAULT '0',
            imported_names int NOT NULL DEFAULT '0',
            fetched datetime DEFAULT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
    );
}

/**
 * @return array{content_hash:string,sellable_rows:int,smu_rows:int,imported_names:int}
 */
function pr_sheet_state(): array
{
    $empty = ['content_hash' => '', 'sellable_rows' => 0, 'smu_rows' => 0, 'imported_names' => 0];
    if (!pr_sheet_table_ready()) {
        return $empty;
    }
    $rows = ps_query(
        "SELECT content_hash, sellable_rows, smu_rows, imported_names
           FROM product_sheet_state WHERE id = 1"
    );
    if ($rows === []) {
        return $empty;
    }
    $row = $rows[0];
    return [
        'content_hash' => (string)$row['content_hash'],
        'sellable_rows' => (int)$row['sellable_rows'],
        'smu_rows' => (int)$row['smu_rows'],
        'imported_names' => (int)$row['imported_names'],
    ];
}

/**
 * @return array<string,array>
 */
function pr_sheet_stored_identities(bool $reset = false): array
{
    static $cached = null;
    if ($reset) {
        $cached = null;
        return [];
    }
    if ($cached !== null) {
        return $cached;
    }
    $cached = [];
    if (!pr_sheet_table_ready()) {
        return $cached;
    }
    $state = pr_sheet_state();
    $stored = (int)ps_value("SELECT COUNT(*) AS value FROM product_sheet_row", [], 0);
    $expect = $state['sellable_rows'] + $state['smu_rows'];
    if ($state['content_hash'] === '' || $stored !== $expect) {
        return $cached;
    }
    foreach (ps_query(
        "SELECT name, category, family, edition, collaboration, clubtype, retailchannel
           FROM product_sheet_row WHERE imported = 1 AND name <> ''"
    ) as $row) {
        $name = (string)$row['name'];
        $cached[$name] = [
            'category' => (string)$row['category'],
            'family' => (string)$row['family'],
            'name' => $name,
            'edition' => (string)$row['edition'],
            'collaboration' => (string)$row['collaboration'],
            'clubtype' => (string)$row['clubtype'],
            'retailchannel' => (string)$row['retailchannel'],
        ];
    }
    return $cached;
}

/**
 * @return array{error:?string,rows:list<array>,sellable:int,smu:int,hash:string}
 */
function pr_fetch_master_sheet(): array
{
    $empty = ['error' => 'no sheet credential', 'rows' => [], 'sellable' => 0, 'smu' => 0, 'hash' => ''];
    $path = pr_sheet_credential_path();
    if ($path === '') {
        return $empty;
    }
    $raw = file_get_contents($path);
    $cred = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($cred) || empty($cred['private_key']) || empty($cred['client_email'])) {
        return ['error' => 'sheet credential is not a service account', 'rows' => [], 'sellable' => 0, 'smu' => 0, 'hash' => ''];
    }
    if (isset($cred['type']) && $cred['type'] !== 'service_account') {
        return ['error' => 'sheet credential is not a service account', 'rows' => [], 'sellable' => 0, 'smu' => 0, 'hash' => ''];
    }
    $token = pr_sheet_access_token($cred);
    if ($token['error'] !== null) {
        return ['error' => $token['error'], 'rows' => [], 'sellable' => 0, 'smu' => 0, 'hash' => ''];
    }
    $ranges = [
        "'MPL - Sellable'!A2:F",
        "'MPL - SMU'!A2:F",
    ];
    $query = [];
    foreach ($ranges as $range) {
        $query[] = 'ranges=' . rawurlencode($range);
    }
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . PR_SHEET_ID
        . '/values:batchGet?' . implode('&', $query);
    $http = pr_sheet_http($url, 'GET', null, ['Authorization: Bearer ' . $token['token']]);
    if ($http['status'] !== 200) {
        return ['error' => 'sheet fetch failed', 'rows' => [], 'sellable' => 0, 'smu' => 0, 'hash' => ''];
    }
    $body = json_decode($http['body'], true);
    $value_ranges = is_array($body) ? ($body['valueRanges'] ?? null) : null;
    if (!is_array($value_ranges) || count($value_ranges) < 2) {
        return ['error' => 'sheet fetch failed', 'rows' => [], 'sellable' => 0, 'smu' => 0, 'hash' => ''];
    }
    $rows = [];
    $sellable = 0;
    $smu = 0;
    foreach ([0 => 'sellable', 1 => 'smu'] as $i => $source) {
        $values = $value_ranges[$i]['values'] ?? [];
        if (!is_array($values)) {
            return ['error' => 'sheet fetch failed', 'rows' => [], 'sellable' => 0, 'smu' => 0, 'hash' => ''];
        }
        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }
            $row = [
                'source' => $source,
                'category' => trim((string)($value[0] ?? '')),
                'sku' => trim((string)($value[1] ?? '')),
                'product_name' => trim((string)($value[2] ?? '')),
                'family' => trim((string)($value[3] ?? '')),
                'color' => trim((string)($value[4] ?? '')),
                'status' => trim((string)($value[5] ?? '')),
            ];
            if ($row['category'] === '' && $row['product_name'] === '' && $row['family'] === '') {
                continue;
            }
            $rows[] = $row;
            if ($source === 'sellable') {
                $sellable++;
            } else {
                $smu++;
            }
        }
    }
    return ['error' => null, 'rows' => $rows, 'sellable' => $sellable, 'smu' => $smu, 'hash' => pr_sheet_hash($rows)];
}

function pr_sheet_hash(array $rows): string
{
    $copy = $rows;
    usort($copy, static function (array $a, array $b): int {
        return [$a['source'], $a['sku'], $a['product_name'], $a['color']]
            <=> [$b['source'], $b['sku'], $b['product_name'], $b['color']];
    });
    return hash('sha256', json_encode($copy));
}

/**
 * @return array{error:?string,token:string}
 */
function pr_sheet_access_token(array $cred): array
{
    $now = time();
    $header = pr_sheet_b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $aud = (string)($cred['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    $claim = pr_sheet_b64(json_encode([
        'iss' => $cred['client_email'],
        'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
        'aud' => $aud,
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    $input = $header . '.' . $claim;
    $sig = '';
    $ok = openssl_sign($input, $sig, (string)$cred['private_key'], OPENSSL_ALGO_SHA256);
    if (!$ok) {
        return ['error' => 'sheet credential could not be signed', 'token' => ''];
    }
    $jwt = $input . '.' . pr_sheet_b64($sig);
    $body = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]);
    $http = pr_sheet_http($aud, 'POST', $body, ['Content-Type: application/x-www-form-urlencoded']);
    $decoded = json_decode($http['body'], true);
    $token = is_array($decoded) ? (string)($decoded['access_token'] ?? '') : '';
    if ($http['status'] !== 200 || $token === '') {
        return ['error' => 'sheet fetch failed', 'token' => ''];
    }
    return ['error' => null, 'token' => $token];
}

function pr_sheet_b64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * @return array{status:int,body:string}
 */
function pr_sheet_http(string $url, string $method, ?string $body, array $headers): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => is_string($resp) ? $resp : ''];
}

/**
 * @return array{added:int,updated:int,removed:int,unchanged:int,imported:int,skipped:int,sellable:int,smu:int,error:?string}
 */
function pr_import_master_sheet(bool $dry_run): array
{
    $stats = [
        'added' => 0, 'updated' => 0, 'removed' => 0, 'unchanged' => 0,
        'imported' => 0, 'skipped' => 0, 'sellable' => 0, 'smu' => 0, 'error' => null,
    ];
    if (!pr_load_identity_grammar()) {
        $stats['error'] = 'identity grammar not loaded';
        return $stats;
    }
    pr_sheet_ensure_tables();
    if (!pr_sheet_table_ready()) {
        $stats['error'] = 'sheet table missing';
        return $stats;
    }
    $fetch = pr_fetch_master_sheet();
    $stats['sellable'] = $fetch['sellable'];
    $stats['smu'] = $fetch['smu'];
    if ($fetch['error'] !== null) {
        $stats['error'] = $fetch['error'];
        return $stats;
    }
    $state = pr_sheet_state();
    $refused = pr_sheet_pull_refused($state['sellable_rows'], $fetch['sellable'], true);
    if ($refused !== null) {
        $stats['error'] = $refused;
        return $stats;
    }
    $interpreted = pr_sheet_interpret_rows($fetch['rows']);
    $stats['skipped'] = $interpreted['skipped'];
    $stats['imported'] = count($interpreted['identities']);
    $previous = pr_sheet_stored_identities();
    foreach ($interpreted['identities'] as $name => $row) {
        if (!isset($previous[$name])) {
            $stats['added']++;
        } elseif (($previous[$name]['family'] ?? '') !== $row['family']
            || ($previous[$name]['category'] ?? '') !== $row['category']
            || (string)($previous[$name]['edition'] ?? '') !== (string)($row['edition'] ?? '')) {
            $stats['updated']++;
        } else {
            $stats['unchanged']++;
        }
    }
    foreach ($previous as $name => $_row) {
        if (!isset($interpreted['identities'][$name])) {
            $stats['removed']++;
        }
    }
    $stored_rows = (int)ps_value("SELECT COUNT(*) AS value FROM product_sheet_row", [], 0);
    $consistent = $state['content_hash'] !== ''
        && $stored_rows === ($state['sellable_rows'] + $state['smu_rows']);
    if ($dry_run || ($fetch['hash'] === $state['content_hash'] && $consistent)) {
        return $stats;
    }
    pr_sheet_replace($fetch, $interpreted);
    pr_identity_catalog(true);
    return $stats;
}

function pr_sheet_replace(array $fetch, array $interpreted): void
{
    $transaction = function_exists('db_begin_transaction')
        && function_exists('db_end_transaction')
        && db_begin_transaction('pr_sheet_replace') !== false;
    try {
        pr_sheet_replace_rows($fetch, $interpreted);
        if ($transaction) {
            db_end_transaction('pr_sheet_replace');
        }
    } catch (Throwable $e) {
        if ($transaction && function_exists('db_rollback_transaction')) {
            db_rollback_transaction('pr_sheet_replace');
        }
        throw $e;
    }
}

function pr_sheet_replace_rows(array $fetch, array $interpreted): void
{
    $keep = [];
    $baked = product_identity_by_model();
    foreach ($fetch['rows'] as $row) {
        $key = sha1($row['source'] . "\0" . $row['sku'] . "\0" . $row['product_name'] . "\0" . $row['color']);
        $keep[$key] = true;
        $got = product_identity_from_sheet($row['category'], $row['family'], $row['product_name'], $row['color']);
        $reason = pr_sheet_reject_reason($got, $row['status'], $row['product_name'], $baked);
        $bound = is_array($got) ? pr_sheet_bind_name((string)$got['name'], $baked) : '';
        $use = ($reason === null) ? ($interpreted['identities'][$bound] ?? null) : null;
        $flag = $use !== null ? 1 : 0;
        $name = $flag ? (string)$use['name'] : '';
        ps_query(
            "INSERT INTO product_sheet_row
                (row_key, source, sku, category, product_name, family, color, sheet_status,
                 name, edition, collaboration, clubtype, retailchannel, imported)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                source = VALUES(source), sku = VALUES(sku), category = VALUES(category),
                product_name = VALUES(product_name), family = VALUES(family), color = VALUES(color),
                sheet_status = VALUES(sheet_status), name = VALUES(name), edition = VALUES(edition),
                collaboration = VALUES(collaboration), clubtype = VALUES(clubtype),
                retailchannel = VALUES(retailchannel), imported = VALUES(imported)",
            [
                "s", $key,
                "s", substr($row['source'], 0, 16),
                "s", substr($row['sku'], 0, 64),
                "s", substr($row['category'], 0, 255),
                "s", substr($row['product_name'], 0, 255),
                "s", substr($flag ? (string)$use['family'] : $row['family'], 0, 255),
                "s", substr($row['color'], 0, 255),
                "s", substr($row['status'], 0, 32),
                "s", substr($name, 0, 255),
                "s", substr($flag ? (string)($use['edition'] ?? '') : '', 0, 64),
                "s", substr($flag ? (string)($use['collaboration'] ?? '') : '', 0, 255),
                "s", substr($flag ? (string)($use['clubtype'] ?? '') : '', 0, 64),
                "s", substr($flag ? (string)($use['retailchannel'] ?? '') : '', 0, 64),
                "i", $flag,
            ]
        );
    }
    $existing = ps_query("SELECT row_key FROM product_sheet_row");
    foreach ($existing as $row) {
        $key = (string)$row['row_key'];
        if (!isset($keep[$key])) {
            ps_query("DELETE FROM product_sheet_row WHERE row_key = ?", ["s", $key]);
        }
    }
    $imported = count($interpreted['identities']);
    $have = (int)ps_value("SELECT COUNT(*) AS value FROM product_sheet_state WHERE id = 1", [], 0);
    if ($have === 1) {
        ps_query(
            "UPDATE product_sheet_state
                SET content_hash = ?, sellable_rows = ?, smu_rows = ?, imported_names = ?, fetched = NOW()
              WHERE id = 1",
            ["s", $fetch['hash'], "i", $fetch['sellable'], "i", $fetch['smu'], "i", $imported]
        );
    } else {
        ps_query(
            "INSERT INTO product_sheet_state
                (id, content_hash, sellable_rows, smu_rows, imported_names, fetched)
             VALUES (1, ?, ?, ?, ?, NOW())",
            ["s", $fetch['hash'], "i", $fetch['sellable'], "i", $fetch['smu'], "i", $imported]
        );
    }
}

/**
 * @return list<string>
 */
function pr_path_text_tokens(string $text): array
{
    $parts = preg_split('/\s+/', pr_fold_label(str_replace('_', ' ', $text))) ?: [];
    $out = [];
    $count = count($parts);
    for ($i = 0; $i < $count; $i++) {
        if ($parts[$i] === 's' && (($parts[$i + 1] ?? '') === 'class')) {
            $out[] = 's-class';
            $i++;
            continue;
        }
        if ($parts[$i] !== '') {
            $out[] = $parts[$i];
        }
    }
    return $out;
}

function pr_path_segment_display(string $seg): string
{
    $seg = trim((string)(preg_replace('/^\s*\d+\s*\)\s*/', '', $seg) ?? $seg));
    $seg = (string)(preg_replace('/_\d{4}$/', '', $seg) ?? $seg);
    return trim(str_replace('_', ' ', $seg));
}

/**
 * @param array<string,array> $catalog
 * @return array{names:list<array>,families:list<array>}
 */
function pr_path_index(array $catalog): array
{
    $names = [];
    $by_family = [];
    foreach ($catalog as $row) {
        if (!is_array($row) || trim((string)($row['name'] ?? '')) === '') {
            continue;
        }
        $tokens = pr_path_text_tokens((string)$row['name']);
        if ($tokens === []) {
            continue;
        }
        $names[] = ['tokens' => $tokens, 'row' => $row];
        $family = trim((string)($row['family'] ?? ''));
        if ($family !== '') {
            $by_family[$family][] = $row;
        }
    }
    usort($names, static fn(array $a, array $b): int => count($b['tokens']) <=> count($a['tokens']));
    $families = [];
    foreach ($by_family as $family => $rows) {
        $tokens = pr_path_text_tokens($family);
        if ($tokens === []) {
            continue;
        }
        $cats = array_unique(array_map(static fn(array $r): string => (string)($r['category'] ?? ''), $rows));
        $families[] = [
            'tokens' => $tokens,
            'family' => $family,
            'category' => count($cats) === 1 ? (string)reset($cats) : '',
            'only' => count($rows) === 1 ? $rows[0] : null,
        ];
    }
    usort($families, static fn(array $a, array $b): int => count($b['tokens']) <=> count($a['tokens']));
    return ['names' => $names, 'families' => $families];
}

function pr_tokens_in_order(array $need, array $have): bool
{
    $i = 0;
    $last = count($need);
    foreach ($have as $token) {
        if ($i < $last && $token === $need[$i]) {
            $i++;
        }
    }
    return $last > 0 && $i === $last;
}

function pr_path_skip_segment(string $fold): bool
{
    if ($fold === '' || $fold === 's class') {
        return true;
    }
    static $skip = [
        'photography' => true, 'studio' => true, 'lifestyle' => true, 'ecomm' => true,
        'e comm' => true, 'jpeg' => true, 'jpeg hr' => true, 'png' => true, 'png hr' => true,
        'psd' => true, 'psd hr' => true, 'tif' => true, 'tiff' => true, 'tif hr' => true,
        'hr' => true, 'web' => true, 'web hr' => true, 'output' => true, 'wip' => true,
        'archive' => true, 'color graded' => true, 'docs' => true, 'sell sheets' => true,
        'sell sheet' => true, 'video' => true, 'videos' => true, 'fonts' => true, 'font' => true,
        'logo' => true, 'brand guideline' => true, 'brand collab' => true,
        'marketing materials' => true, 'retail marketing' => true,
    ];
    return isset($skip[$fold]) || str_contains($fold, 'sell sheet');
}

function pr_path_file_skipped(string $file): bool
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return !in_array($ext, ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'webp'], true);
}

/**
 * @param array{names:list<array>,families:list<array>} $index
 * @return array{name:string,family:string,category:string,edition:string,colorway:string,collaboration:string,clubtype:string,retailchannel:string,reference:bool}|null
 */
function pr_path_resolve(string $path, array $index): ?array
{
    $path = str_replace('\\', '/', $path);
    $segs = array_values(array_filter(explode('/', $path), static fn(string $s): bool => $s !== ''));
    if ($segs === []) {
        return null;
    }
    $file = (string)array_pop($segs);
    if (pr_path_file_skipped($file)) {
        return null;
    }
    $clean = false;
    $tokens = [];
    $aliases = ['loma bag' => 'Loma', 'kids golf bags' => 'Recess'];
    foreach ($segs as $seg) {
        $display = pr_path_segment_display($seg);
        $fold = pr_fold_label($display);
        if ($fold === 'lifestyle' || str_contains($fold, 'sell sheet')
            || in_array($fold, ['docs', 'wip', 'fonts', 'font', 'logo'], true)) {
            return null;
        }
        if ($fold === 'studio' || $fold === 'ecomm' || $fold === 'jpeg hr' || $fold === 'png hr'
            || str_contains($fold, 'ecomm')) {
            $clean = true;
        }
        if (str_contains($fold, 'ecomm')) {
            continue;
        }
        if (isset($aliases[$fold])) {
            foreach (pr_path_text_tokens($aliases[$fold]) as $token) {
                $tokens[] = $token;
            }
        }
        if (!pr_path_skip_segment($fold)) {
            foreach (pr_path_text_tokens($display) as $token) {
                if (!preg_match('/^a\d+$/', $token)) {
                    $tokens[] = $token;
                }
            }
        }
    }
    foreach (pr_path_text_tokens(pathinfo($file, PATHINFO_FILENAME)) as $token) {
        if (!preg_match('/^a\d+$/', $token) && $token !== 'hr' && $token !== 'web') {
            $tokens[] = $token;
        }
    }
    if (!$clean) {
        return null;
    }
    $name_hit = pr_path_best($index['names'], $tokens);
    $family_hit = pr_path_best($index['families'], $tokens);
    $row = null;
    $family = '';
    $category = '';
    if ($name_hit !== null) {
        $row = $name_hit['row'];
        $family = (string)$row['family'];
        $category = (string)$row['category'];
    } elseif ($family_hit !== null) {
        $family = (string)$family_hit['family'];
        $category = (string)$family_hit['category'];
        if (is_array($family_hit['only'])) {
            $row = $family_hit['only'];
        }
    }
    if ($row === null && $family === '') {
        return null;
    }
    $name = $row !== null ? (string)$row['name'] : '';
    if ($name !== '' && pr_is_ignored_product_label($name)) {
        $name = '';
        $row = null;
    }
    if ($name === '' && $family === '') {
        return null;
    }
    $collab = function_exists('sg_collaboration_from_name') ? sg_collaboration_from_name($path) : '';
    if ($collab === '' && $row !== null) {
        $collab = (string)($row['collaboration'] ?? '');
    }
    $club = $row !== null ? (string)($row['clubtype'] ?? '') : '';
    if ($club === '' && $name !== '' && function_exists('sg_clubtype_from_name')) {
        $club = sg_clubtype_from_name($name);
    }
    $retail = $row !== null ? (string)($row['retailchannel'] ?? '') : '';
    if ($retail === '' && $name !== '' && function_exists('sg_retail_from_name')) {
        $retail = sg_retail_from_name($path);
    }
    return [
        'name' => $name,
        'family' => $family,
        'category' => $category,
        'edition' => $row !== null ? (string)($row['edition'] ?? '') : '',
        'colorway' => pr_path_colorway($segs, pr_path_text_tokens($name), pr_path_text_tokens($family), $collab),
        'collaboration' => $collab,
        'clubtype' => $club,
        'retailchannel' => $retail,
        'reference' => $name !== '',
    ];
}

function pr_path_best(array $cands, array $tokens): ?array
{
    $best = null;
    $best_n = 0;
    $tie = false;
    foreach ($cands as $cand) {
        if (!pr_tokens_in_order($cand['tokens'], $tokens)) {
            continue;
        }
        $n = count($cand['tokens']);
        if ($best === null || $n > $best_n) {
            $best = $cand;
            $best_n = $n;
            $tie = false;
            continue;
        }
        if ($n < $best_n) {
            break;
        }
        $left = (string)($best['row']['name'] ?? $best['family'] ?? '');
        $right = (string)($cand['row']['name'] ?? $cand['family'] ?? '');
        if ($left !== $right) {
            $tie = true;
        }
    }
    return $tie ? null : $best;
}

function pr_path_colorway(array $segments, array $name_tokens, array $family_tokens, string $collab): string
{
    $name_set = array_flip($name_tokens);
    $collab_fold = pr_fold_label($collab);
    $past = false;
    foreach ($segments as $seg) {
        $display = pr_path_segment_display($seg);
        $fold = pr_fold_label($display);
        if (pr_path_skip_segment($fold) || str_contains($fold, 'ecomm')
            || ($collab_fold !== '' && $fold === $collab_fold)) {
            continue;
        }
        if (pr_is_ignored_product_label($display)) {
            continue;
        }
        $seg_tokens = pr_path_text_tokens($display);
        $in_name = $seg_tokens !== [] && count(array_filter(
            $seg_tokens,
            static fn(string $t): bool => !isset($name_set[$t])
        )) === 0 && $name_tokens !== [];
        $anchors = $in_name
            || ($name_tokens !== [] && pr_tokens_in_order($name_tokens, $seg_tokens))
            || ($family_tokens !== [] && $seg_tokens === $family_tokens);
        if (!$past) {
            if ($anchors) {
                $past = true;
            }
            continue;
        }
        if ($in_name || preg_match('/^(a\d+|hr|web|\d{4})$/', str_replace(' ', '', $fold))) {
            continue;
        }
        return $display;
    }
    return '';
}

/**
 * @param list<array> $plans
 * @return array|null
 */
function pr_path_choose(array $plans): ?array
{
    if ($plans === []) {
        return null;
    }
    $by_name = [];
    $families = [];
    $colors = [];
    $collabs = [];
    foreach ($plans as $plan) {
        if ($plan['name'] !== '') {
            $by_name[$plan['name']] = $plan;
        }
        if ($plan['family'] !== '') {
            $families[$plan['family']] = true;
        }
        if ($plan['colorway'] !== '') {
            $colors[$plan['colorway']] = true;
        }
        if ($plan['collaboration'] !== '') {
            $collabs[$plan['collaboration']] = true;
        }
    }
    if (count($families) > 1) {
        return null;
    }
    if (count($by_name) === 1) {
        $chosen = array_values($by_name)[0];
    } elseif (count($by_name) > 1) {
        $chosen = $plans[0];
        $chosen['name'] = '';
        $chosen['edition'] = '';
        $chosen['reference'] = false;
        $chosen['clubtype'] = '';
    } else {
        $chosen = $plans[0];
        $chosen['reference'] = false;
    }
    if (count($colors) !== 1) {
        $chosen['colorway'] = '';
    }
    if (count($collabs) === 1) {
        $chosen['collaboration'] = (string)array_key_first($collabs);
    } elseif (count($collabs) > 1) {
        $chosen['collaboration'] = '';
    }
    return $chosen;
}

/**
 * hold: a Name reference would teach the pixel index a node classify does not
 * treat as a product leaf, and never-auto-apply is keyed by the current leaf ids.
 * skip: nothing to add. insert: safe to add a Name reference.
 */
function pr_studio_reference_decision(bool $pending_tree, bool $want_reference, bool $have_ref): string
{
    if (!$want_reference || $have_ref) {
        return 'skip';
    }
    if ($pending_tree) {
        return 'hold';
    }
    return 'insert';
}

function pr_dropbox_location_ready(): bool
{
    if (!function_exists('ps_value')) {
        return false;
    }
    return (int)ps_value(
        "SELECT COUNT(*) AS value FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        ["s", "dropbox_location"],
        0
    ) === 1;
}

/**
 * @return array{exact:int,family_only:int,tagged:int,referenced:int,references_held:int,skipped:int,error:?string}
 */
function pr_studio_seed(bool $dry_run): array
{
    $stats = [
        'exact' => 0, 'family_only' => 0, 'tagged' => 0, 'referenced' => 0,
        'references_held' => 0, 'skipped' => 0, 'error' => null,
    ];
    if (!function_exists('ps_query') || !pr_load_identity_grammar()) {
        $stats['error'] = 'identity grammar not loaded';
        return $stats;
    }
    if (!pr_dropbox_location_ready()) {
        $stats['error'] = 'dropbox_location table missing';
        return $stats;
    }
    $hold_name_refs = pr_pending_tree_field() > 0;
    $index = pr_path_index(pr_identity_catalog());
    $rows = ps_query(
        "SELECT l.resource, l.path
           FROM dropbox_location l
           JOIN resource r ON r.ref = l.resource AND r.archive = 0
          WHERE LOWER(l.path) NOT LIKE '%/lifestyle/%'
            AND (LOWER(l.path) LIKE '%/studio/%'
              OR LOWER(l.path) LIKE '%ecomm%'
              OR LOWER(l.path) LIKE '%jpeg_hr%'
              OR LOWER(l.path) LIKE '%png_hr%')"
    );
    $by_resource = [];
    foreach ($rows as $row) {
        $by_resource[(int)$row['resource']][] = (string)$row['path'];
    }
    $have_ref = [];
    foreach (ps_query("SELECT resource FROM product_reference") as $row) {
        $have_ref[(int)$row['resource']] = true;
    }
    $occupied = [];
    foreach (['family', 'productcategory', 'modelkeyword', 'edition', 'collaboration', 'clubtype', 'retailchannel', 'colorway'] as $key) {
        $field = pr_sheet_field($key);
        $occupied[$key] = pr_resources_with_field($field);
    }
    foreach ($by_resource as $resource => $paths) {
        $plans = [];
        foreach ($paths as $path) {
            $plan = pr_path_resolve($path, $index);
            if ($plan !== null) {
                $plans[] = $plan;
            }
        }
        $chosen = pr_path_choose($plans);
        if ($chosen === null) {
            $stats['skipped']++;
            continue;
        }
        if ($chosen['name'] !== '') {
            $stats['exact']++;
        } else {
            $stats['family_only']++;
        }
        $wrote = pr_sheet_write_empty($resource, $chosen, $occupied, $dry_run);
        if ($wrote) {
            $stats['tagged']++;
        }
        $decision = pr_studio_reference_decision(
            $hold_name_refs,
            (bool)$chosen['reference'] && $chosen['name'] !== '',
            isset($have_ref[$resource])
        );
        if ($decision === 'hold') {
            $stats['references_held']++;
            continue;
        }
        if ($decision !== 'insert') {
            continue;
        }
        if ($dry_run) {
            $stats['referenced']++;
            continue;
        }
        $node = pr_ensure_named_node(pr_sheet_field('modelkeyword'), $chosen['name']);
        if ($node <= 0) {
            continue;
        }
        ps_query(
            "INSERT INTO product_reference (resource, model_node, status) VALUES (?,?,'derived')",
            ["i", $resource, "i", $node]
        );
        $have_ref[$resource] = true;
        $stats['referenced']++;
    }
    return $stats;
}

function pr_sheet_field(string $key): int
{
    $name = [
        'family' => 'family',
        'productcategory' => 'productcategory',
        'modelkeyword' => 'modelkeyword',
        'edition' => 'edition',
        'collaboration' => 'collaboration',
        'clubtype' => 'clubtype',
        'retailchannel' => 'retailchannel',
        'colorway' => 'colorway',
    ][$key] ?? '';
    $field = $name !== '' ? pr_field_ref_by_name($name) : 0;
    if ($field <= 0 && $key === 'clubtype') {
        $field = pr_field_ref_by_name('club_type');
    }
    if ($field <= 0 && $key === 'retailchannel') {
        $field = pr_field_ref_by_name('retail_channel');
    }
    if ($field <= 0 && $key === 'colorway') {
        $field = (int)($GLOBALS['product_recognition_colorway_field'] ?? 0);
    }
    return $field;
}

function pr_resources_with_field(int $field): array
{
    if ($field <= 0 || !function_exists('ps_query')) {
        return [];
    }
    $out = [];
    foreach (ps_query(
        "SELECT rn.resource FROM resource_node rn
           JOIN node n ON n.ref = rn.node AND n.resource_type_field = ?",
        ["i", $field]
    ) as $row) {
        $out[(int)$row['resource']] = true;
    }
    return $out;
}

function pr_sheet_write_empty(int $resource, array $plan, array &$occupied, bool $dry_run): bool
{
    $map = [
        'family' => (string)($plan['family'] ?? ''),
        'productcategory' => (string)($plan['category'] ?? ''),
        'modelkeyword' => (string)($plan['name'] ?? ''),
        'edition' => (string)($plan['edition'] ?? ''),
        'collaboration' => (string)($plan['collaboration'] ?? ''),
        'clubtype' => (string)($plan['clubtype'] ?? ''),
        'retailchannel' => (string)($plan['retailchannel'] ?? ''),
        'colorway' => (string)($plan['colorway'] ?? ''),
    ];
    $wrote = false;
    foreach ($map as $key => $value) {
        if ($value === '' || isset($occupied[$key][$resource])) {
            continue;
        }
        $field = pr_sheet_field($key);
        if ($field <= 0) {
            continue;
        }
        if ($dry_run) {
            $wrote = true;
            $occupied[$key][$resource] = true;
            continue;
        }
        if (!function_exists('add_resource_nodes')) {
            continue;
        }
        $node = pr_ensure_named_node($field, $value);
        if ($node <= 0) {
            continue;
        }
        add_resource_nodes($resource, [$node], false, false);
        $wrote = true;
        $occupied[$key][$resource] = true;
    }
    if ($wrote && !$dry_run && function_exists('resource_log') && defined('LOG_CODE_EDITED')) {
        resource_log($resource, LOG_CODE_EDITED, pr_sheet_field('modelkeyword'),
            'Product recognition: tagged from Dropbox path metadata');
    }
    return $wrote;
}

function pr_sheet_fixture_failure(): string
{
    if (!pr_load_identity_grammar()) {
        return 'grammar missing';
    }
    $rows = [
        ['source' => 'sellable', 'sku' => 'BAG1001-BLK', 'category' => 'Golf Bags', 'product_name' => 'BIG KAHUNA - Black', 'family' => 'Big Kahuna', 'color' => 'Black', 'status' => 'Active'],
        ['source' => 'sellable', 'sku' => 'BAG450-14W-BLK', 'category' => 'Golf Bags', 'product_name' => 'S-CLASS RYDER 14 - Black', 'family' => 'Ryder', 'color' => 'Black', 'status' => 'Active'],
        ['source' => 'sellable', 'sku' => 'BAG250', 'category' => 'Golf Bags', 'product_name' => 'S-CLASS LOMA XL- Black', 'family' => 'Loma XL', 'color' => 'Black', 'status' => 'Depleting'],
        ['source' => 'sellable', 'sku' => 'R58', 'category' => 'Golf Bags', 'product_name' => 'RECESS 5-8 - Black/White Checker', 'family' => 'Recess 5-8', 'color' => '', 'status' => 'Active'],
        ['source' => 'sellable', 'sku' => 'CLUB58', 'category' => 'Golf Clubs', 'product_name' => 'Clubs Recess 5-8', 'family' => 'Recess 5-8', 'color' => '', 'status' => 'Active'],
        ['source' => 'smu', 'sku' => 'WB', 'category' => 'SMU', 'product_name' => 'Whataburger', 'family' => 'Whataburger', 'color' => '', 'status' => 'Active'],
    ];
    $got = pr_sheet_interpret_rows($rows);
    if (!isset($got['identities']['Big Kahuna'], $got['identities']['Ryder 14 S-Class'])) {
        return 'sheet import missed Big Kahuna or Ryder 14 S-Class';
    }
    foreach (['Loma XL- Black S-Class', 'Recess 5-8', 'Clubs Recess 5-8', 'Whataburger'] as $banned) {
        if (isset($got['identities'][$banned])) {
            return 'sheet import kept ' . $banned;
        }
    }
    if (pr_sheet_pull_refused(0, 50, true) === null
        || pr_sheet_pull_refused(0, 514, true) !== null
        || pr_sheet_pull_refused(514, 100, true) === null
        || pr_sheet_pull_refused(514, 450, true) !== null) {
        return 'sheet pull guard rejected a healthy pull or accepted a short one';
    }
    $merged = pr_sheet_merge_catalog(product_identity_by_model(), [
        'Porter Lite Hat' => ['category' => 'Headwear', 'family' => 'Rope Hats', 'name' => 'Porter Lite Hat', 'edition' => ''],
        'Big Kahuna' => $got['identities']['Big Kahuna'],
    ]);
    if (($merged['Porter Lite Hat']['family'] ?? '') !== 'Rope Hats' || !isset($merged['Loma'], $merged['Big Kahuna'])) {
        return 'sheet merge dropped a baked name or ignored a sheet family';
    }
    $index = pr_path_index(product_identity_by_model());
    $cases = [
        ['Sunday Golf DAM/4) Ryder/Photography/Studio/Augusta/Ryder_Yellow_Green_a7_HR.png', 'Ryder', 'Augusta', true],
        ['Sunday Golf DAM/4) Ryder/Photography/Studio/S-Class/White/Ryder_S_Class_White_a3_HR.jpg', 'Ryder S-Class', 'White', true],
        ['Sunday Golf DAM/4) Ryder/Photography/Studio/Ryder Lefty/Ryder_lefty_a2_WEB.png', 'Ryder Lefty', '', true],
        ['Sunday_Golf_Master_Ecomm_Folder/4) Ryder/Ryder 14/Black/JPEG_HR/Ryder_14_Black.jpg', 'Ryder 14', 'Black', true],
        ['Sunday_Golf_Master_Ecomm_Folder/12) Hats/Rope Hats/Green/JPEG_HR/SG_Rope_Hat_Green_a1_HR.jpg', 'Rope Hat', 'Green', true],
        ['Sunday Golf DAM/13) Golf Accessories/Photography/Studio/Koozie/Loma_Koozie_a1_HR.jpg', 'Loma Koozie', '', true],
        ['Sunday Golf DAM/14) Brand Collab/Whataburger/JPEG_HR/Loma_Bag_Whataburger_a2_HR.jpg', 'Loma', '', true],
        ['Sunday_Golf_Master_Ecomm_Folder/Pacific/Blue/JPEG_HR/Pacific_Blue_a1_HR.jpg', '', 'Blue', false],
    ];
    foreach ($cases as [$path, $name, $color, $reference]) {
        $plan = pr_path_resolve($path, $index);
        if ($plan === null) {
            return 'path did not resolve: ' . $path;
        }
        if ($plan['name'] !== $name || $plan['colorway'] !== $color || $plan['reference'] !== $reference) {
            return 'path ' . $name . ' got ' . $plan['name'] . ' / ' . $plan['colorway'];
        }
    }
    $loma = pr_path_resolve('Sunday Golf DAM/14) Brand Collab/Whataburger/JPEG_HR/Loma_Bag_Whataburger_a2_HR.jpg', $index);
    if ($loma === null || $loma['collaboration'] !== 'Whataburger') {
        return 'brand collab path did not keep Whataburger';
    }
    $pacific = pr_path_resolve('Sunday_Golf_Master_Ecomm_Folder/Pacific/Blue/JPEG_HR/Pacific_Blue_a1_HR.jpg', $index);
    if ($pacific === null || $pacific['family'] !== 'Pacific' || $pacific['name'] !== '') {
        return 'Pacific path guessed a sub-class';
    }
    foreach ([
        'Sunday Golf DAM/4) Ryder/Photography/Lifestyle/SG_2024_c01_7259_HR.jpg',
        'Sunday Golf DAM/4) Ryder/Photography/Studio/Augusta/foo.lrprev',
        'Sunday Golf DAM/3) El Camino/Docs/Sell Sheets/out/El Camino Superlite_Sell Sheet_v3.jpg',
    ] as $path) {
        if (pr_path_resolve($path, $index) !== null) {
            return 'path should have been skipped: ' . $path;
        }
    }
    return '';
}
