<?php
/**
 * Product recognition: all logic. The CLI and the cron hook both call in here so the two
 * cannot drift.
 *
 * Embeddings live in resource_dinov2_vector (named for the model, not this plugin).
 */

/**
 * True when an admin has pointed the plugin at a real category-tree field.
 */
function pr_is_configured(): bool
{
    $field = (int)($GLOBALS["product_recognition_field"] ?? 0);
    if ($field <= 0) {
        return false;
    }
    return (int)ps_value(
        "SELECT COUNT(*) AS value FROM resource_type_field WHERE ref = ?",
        ["i", $field],
        0
    ) === 1;
}

/**
 * Resource types to embed/classify. Plugin config_json keeps arrays; a CSV
 * string is still accepted so a setup save cannot turn in_array() into a TypeError.
 *
 * @return list<int>
 */
function pr_resource_types(): array
{
    $raw = $GLOBALS["product_recognition_resource_types"] ?? [];
    if (is_string($raw)) {
        $raw = $raw === "" ? [] : explode(",", $raw);
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $n) {
        $n = (int)$n;
        if ($n > 0) {
            $out[] = $n;
        }
    }
    return $out;
}

/**
 * GET the sidecar liveness endpoint. False on connect/HTTP failure.
 */
function pr_service_health(): bool
{
    global $product_recognition_service_url, $product_recognition_api_key;

    $url = rtrim((string)$product_recognition_service_url, "/") . "/health";
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_CONNECTTIMEOUT => 1,
    ];
    $key = trim((string)($product_recognition_api_key ?? ""));
    if ($key !== "") {
        $opts[CURLOPT_HTTPHEADER] = ["X-API-Key: " . $key];
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) {
        return false;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) && !empty($decoded["ok"]);
}

/**
 * Shared CTE: most-specific product-field leaf per resource.
 *
 * Two `?` placeholders, both the product field. `candidate` is not named `specific`
 * (reserved in MySQL 9.7). Parent+leaf resources keep the leaf; "exactly one Model
 * node" would drop them.
 */
function pr_tagged_leaf_cte(): string
{
    return "WITH RECURSIVE closure AS (
             SELECT ref AS ancestor, ref AS descendant FROM node WHERE resource_type_field = ?
             UNION ALL
             SELECT c.ancestor, n.ref FROM closure c JOIN node n ON n.parent = c.descendant
         ),
         candidate AS (   -- not named specific: reserved word in MySQL 9.7
             SELECT rn.resource, rn.node
             FROM resource_node rn
             JOIN node nd ON nd.ref = rn.node AND nd.resource_type_field = ?
             WHERE NOT EXISTS (
                 SELECT 1 FROM resource_node rn2
                 JOIN closure c ON c.ancestor = rn.node AND c.descendant = rn2.node
                 WHERE rn2.resource = rn.resource AND rn2.node <> rn.node
             )
             AND NOT EXISTS (SELECT 1 FROM node ch WHERE ch.parent = rn.node)
         ),
         tagged AS (
             SELECT resource, MIN(node) AS model_node
             FROM candidate
             GROUP BY resource
             HAVING COUNT(*) = 1
         )";
}

/**
 * Every resource that qualifies as a reference, and the product it is a reference for.
 *
 * The rule, in two steps, and the order matters:
 *
 *   1. SELECT the resource's most specific product tag - the node on this resource with no
 *      descendant among this resource's OWN nodes. Category trees stamp parent and leaf
 *      together, so "exactly one node" would drop every parent+leaf resource.
 *
 *   2. REQUIRE that node to be a leaf of the field - no children at all. A resource whose most
 *      specific tag is still a branch names a category, not a product, and would pull every
 *      child toward one meaningless label.
 *
 * A resource left with anything other than exactly one such node is not a reference.
 *
 * @return array [['resource' => int, 'model_node' => int], ...] ordered by resource
 */
function pr_reference_rows(): array
{
    global $product_recognition_field;

    if (!pr_is_configured()) {
        return [];
    }

    return ps_query(
        pr_tagged_leaf_cte() . "
         SELECT resource, model_node FROM tagged ORDER BY resource",
        array("i", $product_recognition_field, "i", $product_recognition_field)
    );
}

/**
 * SQL + params for tagged leaves that have a stored vector.
 *
 * $limit 0 is unlimited: a MySQL LIMIT 0 would return no rows. Negative is rejected
 * so a typo cannot scan the library.
 *
 * @return array{0:string,1:list<mixed>}
 */
function pr_tagged_leaf_with_vector_sql(int $limit): array
{
    global $product_recognition_field;

    if ($limit < 0) {
        throw new InvalidArgumentException("pr_tagged_leaf_with_vector: limit is $limit");
    }

    $sql = pr_tagged_leaf_cte() . "
         SELECT t.resource, t.model_node
           FROM tagged t
           JOIN resource_dinov2_vector v ON v.resource = t.resource
           JOIN resource r ON r.ref = t.resource AND r.archive >= 0
          ORDER BY t.resource";
    $params = array("i", $product_recognition_field, "i", $product_recognition_field);
    if ($limit > 0) {
        $sql .= " LIMIT ?";
        $params[] = "i";
        $params[] = $limit;
    }
    return [$sql, $params];
}

/**
 * Tagged product leaves that have a stored vector, for the disagreement report.
 *
 * Same most-specific-leaf rule as pr_reference_rows.
 *
 * @return array [['resource' => int, 'model_node' => int], ...] ordered by resource
 */
function pr_tagged_leaf_with_vector(int $limit): array
{
    if (!pr_is_configured()) {
        return [];
    }

    [$sql, $params] = pr_tagged_leaf_with_vector_sql($limit);
    return ps_query($sql, $params);
}

/**
 * Resources with exactly two product-field tags, one an ancestor of the other.
 * Sibling leaf pairs (same parent, parent not tagged on the resource) are excluded.
 *
 * @return array resource refs (ints), ordered by resource
 */
function pr_parent_and_leaf_resources(): array
{
    global $product_recognition_field;

    # COUNT(*) is taken on tagged alone. Joining pairs first would multiply that
    # count if a resource has more than one directed ancestor edge.
    $rows = ps_query(
        "WITH RECURSIVE closure AS (
             SELECT ref AS ancestor, ref AS descendant FROM node WHERE resource_type_field = ?
             UNION ALL
             SELECT c.ancestor, n.ref FROM closure c JOIN node n ON n.parent = c.descendant
         ),
         tagged AS (
             SELECT rn.resource, rn.node
             FROM resource_node rn
             JOIN node nd ON nd.ref = rn.node AND nd.resource_type_field = ?
         ),
         two AS (
             SELECT resource FROM tagged
              GROUP BY resource
             HAVING COUNT(*) = 2
         )
         SELECT DISTINCT t.resource
         FROM two
         JOIN tagged t ON t.resource = two.resource
         JOIN tagged u ON u.resource = t.resource AND u.node <> t.node
         JOIN closure c ON c.ancestor = t.node AND c.descendant = u.node
         WHERE c.ancestor <> c.descendant
         ORDER BY t.resource",
        array("i", $product_recognition_field, "i", $product_recognition_field)
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = (int)$r['resource'];
    }
    return $out;
}

/**
 * Every leaf node of the product field: model_node => name.
 */
function pr_leaf_models(): array
{
    global $product_recognition_field;

    if (!pr_is_configured()) {
        return [];
    }

    return pr_field_leaves((int)$product_recognition_field);
}

/**
 * Reference count per model, counting derived and pinned rows.
 */
function pr_reference_counts(): array
{
    $rows = ps_query(
        "SELECT model_node, COUNT(*) AS n FROM product_reference
          WHERE status IN ('derived','pinned') GROUP BY model_node"
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['model_node']] = (int)$r['n'];
    }
    return $out;
}

/**
 * What pr_sync_references() should do with one existing product_reference row, given the model
 * the resource is tagged as now. Pure and DB-free, so the selfcheck can exercise every branch:
 * this decision is where a dry run and a live run once disagreed, classifying the same stale pin
 * as a CONFLICT in preview and a REVIVAL for real.
 *
 * @param array|null $current ['model_node' => int, 'status' => string], or null if no row exists
 * @return string 'add'|'revive'|'relabel'|'keep'|'defer'
 */
function pr_reference_action(?array $current, int $model_node): string
{
    if ($current === null) {
        return 'add';
    }
    if (in_array($current['status'], ['pinned', 'excluded'], true)) {
        return 'defer';                                   # an admin decided; leave it alone
    }
    if ($current['status'] === 'orphaned') {
        return 'revive';
    }
    return $current['model_node'] !== $model_node ? 'relabel' : 'keep';
}

/**
 * One line describing a revived reference. Lives here, not in the two callers, for the reason
 * this whole file exists: the CLI and the cron hook must not drift.
 *
 * $dry_run only changes the tense, matching the "WOULD ORPHAN"/"ORPHANED" distinction the CLI
 * makes beside it. The cron never previews, so it never passes it.
 */
function pr_format_revived(array $r, bool $dry_run = false): string
{
    return sprintf("%s resource %d - was pinned to deleted node %d, now derived as node %d",
                   $dry_run ? "WOULD REVIVE" : "REVIVED",
                   $r['resource'], $r['pinned_as'], $r['now_tagged']);
}

/**
 * Total orphaned rows in a pr_reconcile_vocabulary() result. Named rather than array_sum(),
 * which would also fold in the 'resources' map.
 */
function pr_reconcile_total(array $reconciled): int
{
    return $reconciled['thresholds'] + $reconciled['references'] + $reconciled['suggestions'];
}

/**
 * Reconcile stored model_node refs against the vocabulary, and neutralise the dead ones.
 *
 * Three tables store model_node refs into `node` and MySQL enforces nothing, so a deleted node
 * leaves rows pointing at nothing. That is not merely untidy: pr_open_suggestions() INNER JOINs
 * `node`, so an orphaned suggestion silently disappears from the review queue while staying
 * state='open' forever. Nothing errors; the queue just gets shorter. Proven in production:
 * a node merge orphaned 26 suggestions that had to be re-pointed by hand.
 *
 * There is no hook to do this on instead. RS core's delete_node() never calls hook(), and its
 * call sites are raw: pages/admin/admin_manage_field_options.php, pages/tools/migrate_data_to_fixed.php,
 * and pages/tools/nodes_remove_duplicates.php - the last being RS's own merge tool, which
 * re-tags every resource to the replacement node, drops the duplicate tag, then deletes the node,
 * with no awareness of plugin side-tables. (The plugin does hook resource deletion, in
 * HookProduct_recognitionAllBeforedeleteresourcefromdb, because RS fires a hook there. The
 * asymmetry is RS's.) Nor is a foreign key available: the dbstruct format is a flat column dump
 * with no FK syntax, and two of the three columns are NOT NULL and want "flip to orphaned"
 * rather than CASCADE or SET NULL anyway.
 *
 * That merge tool is why the revival path below is the common case and not an afterthought:
 * after a merge the resource is already carrying the survivor node.
 *
 * What each table gets, and why it differs:
 *
 *   product_threshold  DELETE. A nonexistent node can never be a classification candidate, so
 *                      the row is pure garbage. This is also what stops pr_calibrate()'s
 *                      upsert-and-never-delete from accumulating rows across vocabulary edits.
 *
 *   product_reference  status='pinned' becomes 'orphaned', NOT deleted. Deleting would let the
 *                      derived path re-add the resource under whatever it is tagged as now with
 *                      no trace, which is the silent re-point the pin exists to prevent (see
 *                      this function's contract below). Marking drops it out of
 *                      pr_reference_counts() and out of the vector service's own reference query
 *                      (both filter status IN ('derived','pinned')), so it immediately stops
 *                      voting under a model that no longer exists. The service is the reason
 *                      this matters: its query checks only that model_node has no children, and
 *                      a deleted node has none, so an orphaned pin would otherwise load and vote
 *                      under a nonexistent label.
 *
 *                      'orphaned' is a waypoint, not a terminus. pr_sync_references() revives it
 *                      the moment the resource carries a product tag again - as 'derived', and
 *                      reported in stats['revived'] so the change is loud. The prohibition is on
 *                      SILENT re-pointing, not on re-deriving; an inert-forever row would lose a
 *                      correctly-retagged reference image permanently, because the writers of
 *                      'pinned' are pr_accept_suggestion() (needs an open suggestion) and
 *                      pr_apply_catalog_pick() (untagged or same-leaf only; never excluded).
 *                      pr_open_suggestions() excludes any resource that already carries a product
 *                      node. A re-tagged resource could therefore never be re-pinned from review.
 *
 *                      status='derived' needs nothing: pr_reference_rows() inner-joins `node`,
 *                      so the resource drops out of $wanted and the removal loop below deletes
 *                      the row on this same pass.
 *
 *                      ponytail: status='excluded' is left alone on purpose. Exclusion is a
 *                      per-resource decision and model_node is never read for those rows - the
 *                      loop below `continue`s before touching it. "Cleaning" them would silently
 *                      re-enable a reference an admin excluded, which is the dangerous direction.
 *
 *   product_suggestion state='open' becomes 'orphaned'. It is undecidable and already invisible.
 *                      Marking makes it countable, keeps pr_accept_suggestion() and
 *                      pr_reject_suggestion() (both require state='open') from acting on it, and
 *                      lets pr_classify() re-suggest the resource against live nodes because its
 *                      "already has an open suggestion" guard no longer matches. It does not
 *                      collide with the 'rejected' suppression, which matches that state only.
 *
 * Known limit, accepted: between a node being deleted and the next pass, affected suggestions
 * are still missing from the review queue, because pr_open_suggestions()'s inner join is
 * unchanged. Closing that window means a LEFT JOIN plus an "orphaned model" row in the UI. The
 * window is one cron interval, and the counts returned here make it detectable - which is the
 * part that was missing.
 *
 * @param bool $dry_run Report only.
 * @return array ['thresholds' => int, 'references' => int, 'suggestions' => int,
 *               'resources' => [resource => dead model_node]]  - the map is populated on a
 *               dry run too, which is what lets pr_sync_references() preview identically.
 */
function pr_reconcile_vocabulary(bool $dry_run = false): array
{
    # The reference rows come back as resources, not just a count. pr_sync_references() needs to
    # know WHICH resources these are even on a dry run, when the UPDATE below never happens -
    # otherwise a stale pin classifies one way in preview and the opposite way for real.
    $stats = ['thresholds' => 0, 'references' => 0, 'suggestions' => 0, 'resources' => []];

    foreach (ps_query(
        "SELECT resource, model_node FROM product_reference r
          WHERE r.status = 'pinned'
            AND NOT EXISTS (SELECT 1 FROM node n WHERE n.ref = r.model_node)") as $row) {
        $stats['resources'][(int)$row['resource']] = (int)$row['model_node'];
    }
    $stats['references'] = count($stats['resources']);

    $stats['thresholds'] = (int)ps_value(
        "SELECT COUNT(*) AS value FROM product_threshold t
          WHERE NOT EXISTS (SELECT 1 FROM node n WHERE n.ref = t.model_node)", [], 0);
    $stats['suggestions'] = (int)ps_value(
        "SELECT COUNT(*) AS value FROM product_suggestion s
          WHERE s.state = 'open'
            AND NOT EXISTS (SELECT 1 FROM node n WHERE n.ref = s.model_node)", [], 0);

    if ($dry_run || pr_reconcile_total($stats) === 0) {
        return $stats;
    }

    ps_query("DELETE FROM product_threshold
               WHERE NOT EXISTS (SELECT 1 FROM node n WHERE n.ref = product_threshold.model_node)");

    ps_query("UPDATE product_reference r SET r.status = 'orphaned'
               WHERE r.status = 'pinned'
                 AND NOT EXISTS (SELECT 1 FROM node n WHERE n.ref = r.model_node)");

    ps_query("UPDATE product_suggestion s
                 SET s.state = 'orphaned', s.decided_at = NOW()
               WHERE s.state = 'open'
                 AND NOT EXISTS (SELECT 1 FROM node n WHERE n.ref = s.model_node)");

    return $stats;
}

/**
 * Materialise the derived reference set into product_reference.
 *
 * Curation is corrective: 'pinned' rows are always references for the model recorded ON THE
 * PIN, 'excluded' rows never are, and everything else follows the tags automatically. A pin
 * whose resource now carries a different product tag is reported as a conflict rather than
 * silently re-pointed.
 *
 * A fourth status exists: 'orphaned', written by pr_reconcile_vocabulary() when a pin's model
 * node is deleted. It is inert for counting and for the vector service, and this function revives
 * it to 'derived' - reported in stats['revived'] - once the resource carries a product tag again.
 *
 * @param bool $dry_run Report only.
 * @return array stats
 */
function pr_sync_references(bool $dry_run = false): array
{
    # Before anything reads product_reference: this can flip 'pinned' to 'orphaned', and the
    # loops below branch on status.
    $reconciled = pr_reconcile_vocabulary($dry_run);

    $derived = pr_reference_rows();
    $wanted = [];
    foreach ($derived as $row) {
        $wanted[(int)$row['resource']] = (int)$row['model_node'];
    }

    $existing = [];
    foreach (ps_query("SELECT resource, model_node, status FROM product_reference") as $row) {
        $existing[(int)$row['resource']] = ['model_node' => (int)$row['model_node'], 'status' => $row['status']];
    }

    # A dry run wrote nothing, so those rows are still 'pinned' in this snapshot. Overlay them,
    # so --dry-run classifies exactly as the real run does. Without this a stale pin previews as a
    # CONFLICT and then executes as a REVIVAL - opposite answers from the one tool whose entire
    # job is to predict the other.
    foreach ($reconciled['resources'] as $resource => $dead_node) {
        if (isset($existing[$resource])) {
            $existing[$resource]['status'] = 'orphaned';
        }
    }

    $stats = ['derived' => count($wanted), 'added' => 0, 'updated' => 0, 'removed' => 0,
              'pinned' => 0, 'excluded' => 0, 'orphaned' => 0, 'conflicts' => [],
              'revived' => [], 'reconciled' => $reconciled];

    foreach ($existing as $resource => $row) {
        if ($row['status'] === 'pinned') {
            $stats['pinned']++;
            if (isset($wanted[$resource]) && $wanted[$resource] !== $row['model_node']) {
                $stats['conflicts'][] = ['resource' => $resource, 'pinned_as' => $row['model_node'],
                                         'tagged_as' => $wanted[$resource]];
            }
        } elseif ($row['status'] === 'excluded') {
            $stats['excluded']++;
        }
    }

    foreach ($wanted as $resource => $model_node) {
        $current = $existing[$resource] ?? null;
        switch (pr_reference_action($current, $model_node)) {
            case 'defer':
                break;

            case 'revive':
                # The pin's model node was deleted and the resource is tagged again. Revive it as
                # 'derived' and SAY SO. Leaving it inert would strand it: nothing can ever write
                # 'pinned' for a resource that already carries a product node (see
                # pr_reconcile_vocabulary). Reporting it is what keeps this from being the silent
                # re-point the contract forbids.
                $stats['revived'][] = ['resource' => $resource,
                                       'pinned_as' => $current['model_node'],
                                       'now_tagged' => $model_node];
                if (!$dry_run) {
                    ps_query("UPDATE product_reference SET model_node = ?, status = 'derived'
                               WHERE resource = ? AND status = 'orphaned'",
                             array("i", $model_node, "i", $resource));
                }
                break;

            case 'add':
                $stats['added']++;
                if (!$dry_run) {
                    ps_query("INSERT INTO product_reference (resource, model_node, status) VALUES (?,?,'derived')",
                             array("i", $resource, "i", $model_node));
                }
                break;

            case 'relabel':
                $stats['updated']++;
                if (!$dry_run) {
                    # Relabelling in place is safe ONLY because load_references detects change
                    # with a digest over (resource, model_node, status) as well as count and max
                    # ref. It is not a general licence: resource_dinov2_vector has no such
                    # digest, so re-embedding there must still be DELETE then INSERT.
                    ps_query("UPDATE product_reference SET model_node = ? WHERE resource = ? AND status = 'derived'",
                             array("i", $model_node, "i", $resource));
                }
                break;
        }
    }

    foreach ($existing as $resource => $row) {
        if ($row['status'] === 'derived' && !isset($wanted[$resource])) {
            $stats['removed']++;
            if (!$dry_run) {
                ps_query("DELETE FROM product_reference WHERE resource = ? AND status = 'derived'",
                         array("i", $resource));
            }
        }
    }

    # Counted here, not in the first loop: 'orphaned' is the standing backlog of references
    # waiting for a product tag, so a row revived above belongs to 'revived' and not to this.
    # Adding it to both would have the CLI print "N awaiting a product tag" above "REVIVED N".
    $revived = array_column($stats['revived'], 'resource');
    foreach ($existing as $resource => $row) {
        if ($row['status'] === 'orphaned' && !in_array($resource, $revived, true)) {
            $stats['orphaned']++;
        }
    }

    return $stats;
}

/**
 * Smallest reference count at which the target precision is observable at all.
 *
 * At n=5 the only observable precisions are 80% and 100%, so nothing can land on 95% - a
 * model would either never auto-apply or auto-apply on 5-of-5 while carrying a "measured 95%
 * precision" label. The floor is the smallest n where a single error still clears the target:
 * (n-1)/n >= target, i.e. n >= 1/(1-target). 0.95 gives 20; 0.99 gives 100.
 */
function pr_min_references(float $target): int
{
    if ($target <= 0.0 || $target >= 1.0) {
        return 1;
    }
    # round() before ceil() is not decoration. 1.0 - 0.90 is 0.09999999999999998 in IEEE 754,
    # so the quotient is 10.000000000000002 and a bare ceil() returns 11 - a floor one higher
    # than the target actually implies, which would exclude a whole band of models from ever
    # auto-applying. 0.95 and 0.99 happen to round the other way and look fine, which is
    # exactly why the self-check asserts three targets rather than only the default.
    return (int)ceil(round(1.0 / (1.0 - $target), 6));
}

function pr_upload_mime(string $file): string
{
    return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => 'image/jpeg',
    };
}

/**
 * POST to the vector service. Returns the decoded JSON, or null on any failure - callers
 * must treat null as "the service did not answer" and stop, never as "no result".
 *
 * $http_status is the curl HTTP code (0 if the request never landed). Classify still
 * treats every null as a hard stop; the disagreement report maps 404 to "no comparable
 * leaf" because JOIN already required a vector row.
 */
function pr_service_post(string $path, array $fields, ?string $file = null,
                         int $timeout = 600, int $connect_timeout = 10,
                         ?int &$http_status = null): ?array
{
    global $product_recognition_service_url;

    $http_status = 0;
    $post = $fields;
    if ($file !== null) {
        if (!file_exists($file)) {
            return null;
        }
        $post['image'] = new CURLFile($file, pr_upload_mime($file), basename($file));
    }

    $ch = curl_init(rtrim($product_recognition_service_url, '/') . $path);
    $curl_opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,          # /calibrate over the whole reference set is not fast
        CURLOPT_CONNECTTIMEOUT => $connect_timeout,
    ];
    $key = trim((string)($GLOBALS["product_recognition_api_key"] ?? ""));
    if ($key !== "") {
        $curl_opts[CURLOPT_HTTPHEADER] = ["X-API-Key: " . $key];
    }
    curl_setopt_array($ch, $curl_opts);
    $body = curl_exec($ch);
    $http_status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $http_status !== 200) {
        logScript("product_recognition: POST $path failed (HTTP $http_status) $err");
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Embed one resource's preview into resource_dinov2_vector, keyed on file_checksum.
 *
 * DELETE then INSERT, like the clip plugin - but the table's UNIQUE key on resource means the
 * replacement cannot coexist with what it replaced, and the service's loader rebuilds on
 * change rather than appending. Two ends of the same trap: a stale vector that still votes.
 *
 * The UNIQUE key makes `INSERT ... ON DUPLICATE KEY UPDATE` the tempting spelling here. DO NOT
 * use it: the service detects change by (row count, max ref), and an in-place update moves
 * neither, so the cache would serve the superseded vector forever.
 */
function pr_embed_resource(int $ref): bool
{
    global $product_recognition_space;

    $resource = get_resource_data($ref);
    if ($resource === false) {
        return false;
    }
    $path = get_resource_path($ref, true, 'pre', false, 'jpg');
    if (!file_exists($path)) {
        logScript("product_recognition: resource $ref has no preview at $path");
        return false;
    }

    $vector = pr_service_post('/vector', ['space' => $product_recognition_space], $path);
    if ($vector === null || count($vector) === 0) {
        return false;
    }

    $floats = array_map('floatval', $vector);
    $blob = pack('f*', ...$floats);

    ps_query("DELETE FROM resource_dinov2_vector WHERE resource = ?", array("i", $ref));
    ps_query(
        "INSERT INTO resource_dinov2_vector (resource, vector_blob, checksum) VALUES (?,?,?)",
        # blob as 's': ps_query does not handle 'b' (no send_long_data), same as the clip plugin
        array("i", $ref, "s", $blob, "s", (string)$resource['file_checksum'])
    );
    return true;
}

/**
 * Embed up to $limit resources that need it, then record where it stopped.
 *
 * Needs embedding = eligible resource type, has a preview, and either has no vector or has one
 * whose checksum no longer matches the file. A full pass is hours, not minutes, so this runs
 * as a bounded nightly slice and picks up where it left off.
 *
 * A kill costs no repeated EMBEDDING - the forward scan filters on `v.ref IS NULL`, so the
 * cursor is a scan-range optimisation, not the correctness mechanism. It does cost a wider
 * scan on the next run, which is invisible at this size and unmeasured at full scale.
 */
function pr_backfill(int $limit, bool $dry_run = false): array
{
    # A zero limit is LIMIT 0 - it embeds nothing and reports a clean run, which is the worst
    # possible failure here. Fail loudly instead.
    if ($limit <= 0) {
        throw new RuntimeException("pr_backfill: batch limit is $limit; is \$product_recognition_batch set?");
    }

    $types = implode(',', pr_resource_types());
    if ($types === '') {
        throw new RuntimeException("pr_backfill: no resource types configured");
    }
    $resumed_from = (int)get_sysvar('product_recognition_backfill_position', 0);

    # Stale rows (already embedded, but the file changed since) are NOT cursor-gated. The
    # forward scan below only reaches a given ref once per lap, so a file replaced behind the
    # cursor would otherwise wait for the whole backlog to drain - potentially days at nightly
    # cadence - while its superseded vector keeps voting. Staleness is what the checksum column
    # exists to catch, so it is checked over the whole table every slice, cursor or no cursor.
    #
    # Capped at half the slice, which is a floor for the forward scan rather than politeness.
    # The stale query has no cursor and no backoff, so a row whose re-embed keeps FAILING - a
    # preview that will not regenerate, a corrupt file - stays stale and gets re-selected at
    # the front of every slice forever. Uncapped, enough of those starve the forward scan
    # indefinitely and part of the library is never embedded at all, silently.
    $stale_limit = max(1, intdiv($limit, 2));
    $stale = ps_query(
        "SELECT r.ref, r.file_checksum
           FROM resource r JOIN resource_dinov2_vector v ON v.resource = r.ref
          WHERE r.resource_type IN ($types) AND r.archive >= 0
            AND (v.checksum IS NULL OR v.checksum <> r.file_checksum)
          ORDER BY r.ref
          LIMIT ?",
        array("i", $stale_limit)
    );

    # The forward scan now only looks for never-embedded resources - staleness is handled above.
    $sql = "SELECT r.ref, r.file_checksum
              FROM resource r
              LEFT JOIN resource_dinov2_vector v ON v.resource = r.ref
             WHERE r.ref > ?
               AND r.resource_type IN ($types)
               AND r.archive >= 0
               AND v.ref IS NULL
             ORDER BY r.ref
             LIMIT ?";

    $remaining = max(0, $limit - count($stale));
    $rows = $remaining > 0 ? ps_query($sql, array("i", $resumed_from, "i", $remaining)) : [];
    if ($rows === [] && $remaining > 0 && $resumed_from > 0) {
        # End of the library: wrap to the start so new arrivals below the cursor are picked up.
        $resumed_from = 0;
        $rows = ps_query($sql, array("i", 0, "i", $remaining));
    }

    $stats = ['embedded' => 0, 'skipped_no_preview' => 0, 'failed' => 0,
              'resumed_from' => $resumed_from, 'last' => $resumed_from];

    # Shared per-row embed step. $advances_cursor is false for stale rows: a stale row can have
    # any ref, including one below the forward-scan cursor, and must not drag $stats['last']
    # backwards - that would make the NEXT run's forward scan redo ground it already covered.
    $embed_row = function (array $row, bool $advances_cursor) use (&$stats, $dry_run): void {
        $ref = (int)$row['ref'];
        if ($advances_cursor) {
            $stats['last'] = $ref;
        }
        if ($dry_run) {
            $stats['embedded']++;
            return;
        }
        $path = get_resource_path($ref, true, 'pre', false, 'jpg');
        if (!file_exists($path)) {
            # A preview-less resource is counted and skipped, never allowed to stall a batch.
            # Library-wide there are 9 such resources; 4 of them are of an eligible resource
            # type and so are the ones this backfill actually meets. Both numbers are real -
            # they describe different populations.
            $stats['skipped_no_preview']++;
            return;
        }
        try {
            if (pr_embed_resource($ref)) {
                $stats['embedded']++;
            } else {
                # One corrupt file (the known IDAT CRC error PNG) must never abort a batch -
                # the lesson of #35 and #36.
                $stats['failed']++;
            }
        } catch (Throwable $e) {
            logScript("product_recognition: resource $ref failed: " . $e->getMessage());
            $stats['failed']++;
        }
    };

    foreach ($stale as $row) {
        $embed_row($row, false);
    }
    foreach ($rows as $row) {
        $embed_row($row, true);
    }

    if (!$dry_run) {
        set_sysvar('product_recognition_backfill_position', (string)$stats['last']);
    }

    # Floors at the count of permanently preview-less resources OF AN ELIGIBLE TYPE (4 today,
    # of 9 preview-less library-wide) and never reaches zero - nothing downstream may gate
    # "backfill complete" on remaining == 0. The floor drifts upward with ordinary use.
    $stats['remaining'] = (int)ps_value(
        "SELECT COUNT(*) AS value
           FROM resource r LEFT JOIN resource_dinov2_vector v ON v.resource = r.ref
          WHERE r.resource_type IN ($types) AND r.archive >= 0
            AND (v.ref IS NULL OR v.checksum IS NULL OR v.checksum <> r.file_checksum)",
        array(), 0
    );
    return $stats;
}

/**
 * Wilson score interval, lower bound, at 95% confidence (z=1.96).
 *
 * Gate on the interval, not the point estimate. 20-of-20 is a point estimate of 100% and a
 * lower bound of 83.9%, so it does not clear a 95% target on its own; a perfect record needs
 * about 73 observations to. Deliberately conservative: being slow costs a longer review
 * queue, being wrong costs a mislabelled reference that pulls further images wrong.
 */
function pr_wilson_lower(int $successes, int $n, float $z = 1.96): float
{
    if ($n <= 0) {
        return 0.0;
    }
    $p = $successes / $n;
    $z2 = $z * $z;
    $denominator = 1.0 + $z2 / $n;
    $centre = $p + $z2 / (2 * $n);
    $margin = $z * sqrt(($p * (1 - $p) + $z2 / (4 * $n)) / $n);
    return max(0.0, ($centre - $margin) / $denominator);
}

/**
 * Lowest confidence at which this model's predictions clear the target.
 *
 * $observations is every leave-one-out query PREDICTED as this model:
 *   [['confidence' => float, 'correct' => bool], ...]
 *
 * Sweeps candidate thresholds from the highest observed confidence downward, keeping the
 * lowest one whose Wilson lower bound still meets the target - so a model with 40 clean
 * references lands on a higher threshold than one with 200, which is correct rather than a
 * special case.
 *
 * @return array|null null when no threshold clears the target at any confidence.
 */
function pr_threshold_for_model(array $observations, float $target): ?array
{
    if ($observations === []) {
        return null;
    }
    usort($observations, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

    $successes = 0;
    $best = null;
    foreach ($observations as $i => $obs) {
        if ($obs['correct']) {
            $successes++;
        }
        $n = $i + 1;
        # Only evaluate at a real boundary, so ties in confidence are never split.
        if ($i + 1 < count($observations)
            && $observations[$i + 1]['confidence'] === $obs['confidence']) {
            continue;
        }
        $lower = pr_wilson_lower($successes, $n);
        if ($lower >= $target) {
            $best = [
                'min_confidence' => (float)$obs['confidence'],
                'measured_precision' => $successes / $n,
                'wilson_lower' => $lower,
                'sample_size' => $n,
            ];
        }
    }
    return $best;
}

/**
 * Models whose references vanished because the model gained children.
 *
 * This must never present as "no references yet". A vocabulary edit that puts leaves under
 * a former product makes every existing reference for it under-specified at once and takes
 * its threshold with it. That is the correct behaviour and it is a cliff - the difference
 * between a five-minute fix and a week of "why did tagging stop working".
 *
 * Deliberately NOT filtered on reference_count > 0: the first calibration after the edit
 * writes that count down to zero, so filtering on it would make the condition report itself
 * exactly once and then fall silent while still being true.
 */
function pr_models_losing_references_to_children(): array
{
    global $product_recognition_field;

    return ps_query(
        "SELECT t.model_node, n.name, t.reference_count AS previous_count
           FROM product_threshold t
           JOIN node n ON n.ref = t.model_node AND n.resource_type_field = ?
          WHERE EXISTS (SELECT 1 FROM node ch WHERE ch.parent = t.model_node)
          ORDER BY t.reference_count DESC",
        array("i", $product_recognition_field)
    );
}

/**
 * Pinned reference count per model. Separate from pr_reference_counts() because pins and
 * derived references behave differently under a vocabulary change: the derived ones follow the
 * tags automatically, the pins do not and need a person.
 */
function pr_pinned_reference_counts(): array
{
    $out = [];
    foreach (ps_query(
        "SELECT model_node, COUNT(*) AS n FROM product_reference
          WHERE status = 'pinned' GROUP BY model_node") as $r) {
        $out[(int)$r['model_node']] = (int)$r['n'];
    }
    return $out;
}

/**
 * Recalibrate every model from one leave-one-out pass.
 *
 * The spec's "recalibrate only models whose reference set changed" optimisation is skipped:
 * leave-one-out is one dense matmul on the service - NOT a FAISS index search, see /calibrate's
 * own docstring - so the whole pass is cheaper than the bookkeeping to avoid it. Measured
 * 2026-08-07: 2s wall, 1.0s service-side, for 2,841 observations.
 *
 * That measurement is decisive for today's reference set and NOT extrapolable. Cost is O(Q x N):
 * max_queries_per_model caps Q, but N - the reference set every query is compared against - is
 * uncapped and grows with the library. Re-measure after the phase-2 import rather than assuming
 * the cap holds it down; if it no longer holds, the per-model filter goes back in.
 */
function pr_calibrate(bool $dry_run = false): array
{
    global $product_recognition_space, $product_recognition_k, $product_recognition_target_precision;

    $target = (float)$product_recognition_target_precision;
    $floor = pr_min_references($target);
    $counts = pr_reference_counts();
    $leaves = pr_leaf_models();

    # Models that lost their references to new children - detected BEFORE the thresholds are
    # rewritten, because the old reference_count is the evidence.
    #
    # NOT gated on the reference count reaching zero. pr_sync_references() deletes only
    # `derived` rows, and pr_accept_suggestion() creates `pinned` ones - so a model that gained
    # children while holding even one accepted reference would keep a non-zero count, fall out
    # of both $leaves and $lost, and never be visited again. Its threshold row would sit at
    # status='ok' with a stale min_confidence that pr_classify() still reads, and its pinned
    # vector would keep voting under what is now a category rather than a product. That is the
    # same cliff the union below exists to prevent, reached through the accept path instead of
    # the tagging one. A node with children is under-specified whatever the source of its
    # references, so it is always recalibrated.
    $lost = [];
    $pinned_on_branch = pr_pinned_reference_counts();
    foreach (pr_models_losing_references_to_children() as $row) {
        $node = (int)$row['model_node'];
        $row['pinned_remaining'] = $pinned_on_branch[$node] ?? 0;
        $lost[$node] = $row;
    }

    $response = pr_service_post('/calibrate', [
        'db' => $GLOBALS['mysql_db'],
        'space' => $product_recognition_space,
        'k' => (int)$product_recognition_k,
    ]);
    if ($response === null) {
        throw new RuntimeException("calibration: vector service did not answer");
    }

    $by_prediction = [];
    foreach ($response['observations'] as $obs) {
        $by_prediction[(int)$obs['predicted_model']][] = [
            'confidence' => (float)$obs['confidence'],
            'correct' => (int)$obs['predicted_model'] === (int)$obs['true_model'],
        ];
    }

    # Iterate leaves UNION lost, not just leaves. The moment a model gains a child it stops
    # being a leaf, so pr_leaf_models() no longer returns it - and a loop over leaves alone
    # would never visit it again. Its threshold row would then keep its stale `ok` status and
    # its old reference count forever, and classification would go on auto-applying a model
    # whose every reference has just been invalidated. The whole gained-children report
    # computed above is worthless if the loop never reaches the nodes it names.
    $to_calibrate = $leaves;
    foreach ($lost as $node => $row) {
        if (!isset($to_calibrate[$node])) {
            $to_calibrate[$node] = $row['name'];
        }
    }

    # Leaves only. $to_calibrate also has parents that gained children; folding those
    # in can make a listed leaf ambiguous and skip the flag.
    $never_nodes = array_fill_keys(pr_never_auto_apply_nodes($leaves), true);

    $results = [];
    foreach ($to_calibrate as $node => $name) {
        $reference_count = $counts[$node] ?? 0;
        $row = [
            'model_node' => $node, 'name' => $name, 'reference_count' => $reference_count,
            'min_confidence' => null, 'measured_precision' => null, 'wilson_lower' => null,
            'sample_size' => count($by_prediction[$node] ?? []), 'status' => 'ok',
        ];

        if (isset($lost[$node])) {
            $row['status'] = 'references_lost_to_children';
            $row['previous_count'] = (int)$lost[$node]['previous_count'];
            # Pins survive the sync, so they outlive the tags that justified them. Surfaced
            # because only a human can re-point a pin at the right child.
            $row['pinned_remaining'] = (int)$lost[$node]['pinned_remaining'];
        } elseif ($reference_count < $floor) {
            $row['status'] = 'too_few_references';
        } else {
            $threshold = pr_threshold_for_model($by_prediction[$node] ?? [], $target);
            if ($threshold === null) {
                $row['status'] = 'no_threshold_clears_target';
            } else {
                $row = array_merge($row, $threshold);
            }
        }
        $never = isset($never_nodes[$node]) ? 1 : 0;
        if ($never) {
            $row['never_auto_apply'] = 1;
        }
        $results[$node] = $row;

        if ($dry_run) {
            continue;
        }
        # Listed names write 1. Unlisted names insert 0 but ON DUPLICATE keeps an existing 1
        # (never_auto_apply is a product decision, not a calibration result).
        ps_query(
            "INSERT INTO product_threshold
                (model_node, min_confidence, measured_precision, wilson_lower, sample_size,
                 reference_count, status, never_auto_apply, calibrated_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE
                min_confidence=VALUES(min_confidence), measured_precision=VALUES(measured_precision),
                wilson_lower=VALUES(wilson_lower), sample_size=VALUES(sample_size),
                reference_count=VALUES(reference_count), status=VALUES(status),
                never_auto_apply=IF(VALUES(never_auto_apply)=1, 1, never_auto_apply),
                calibrated_at=VALUES(calibrated_at)",
            array("i", $node,
                  "d", $row['min_confidence'], "d", $row['measured_precision'],
                  "d", $row['wilson_lower'], "i", $row['sample_size'],
                  "i", $reference_count, "s", $row['status'],
                  "i", $never)
        );
    }
    return $results;
}

/**
 * Classify resources that have a vector and no product tag.
 *
 * Only ever adds. A resource that already carries a product node is skipped outright, so
 * hand-tagging wins over the recogniser - the path can know size or variant that is not
 * in the pixels.
 */
function pr_classify(int $limit, bool $dry_run = false): array
{
    global $product_recognition_field, $product_recognition_space, $product_recognition_k,
           $product_recognition_min_similarity, $product_recognition_auto_apply;

    # Skip fully-rejected resources: every prior candidate was rejected, nothing is open or
    # accepted. Re-running /identify every night would only re-hit the same rejections. Delete
    # the rejected rows (or accept a new suggestion after a re-queue) to put the resource back
    # in play - e.g. after the vocabulary or reference set moves enough that a new top-k is
    # plausible.
    $candidates = ps_query(
        "SELECT v.resource
           FROM resource_dinov2_vector v
           JOIN resource r ON r.ref = v.resource AND r.archive >= 0
          WHERE NOT EXISTS (
                  SELECT 1 FROM resource_node rn
                    JOIN node n ON n.ref = rn.node AND n.resource_type_field = ?
                   WHERE rn.resource = v.resource)
            AND NOT EXISTS (
                  SELECT 1 FROM product_suggestion s
                   WHERE s.resource = v.resource AND s.state = 'open')
            AND NOT (
                  EXISTS (
                      SELECT 1 FROM product_suggestion s
                       WHERE s.resource = v.resource AND s.state = 'rejected')
                  AND NOT EXISTS (
                      SELECT 1 FROM product_suggestion s
                       WHERE s.resource = v.resource AND s.state IN ('open','accepted'))
                )
          ORDER BY v.resource
          LIMIT ?",
        array("i", $product_recognition_field, "i", $limit)
    );

    $thresholds = [];
    foreach (ps_query("SELECT * FROM product_threshold") as $row) {
        $thresholds[(int)$row['model_node']] = $row;
    }
    # Config list is the lock even before the next calibrate has written the column.
    $never_nodes = array_fill_keys(pr_never_auto_apply_nodes(pr_leaf_models()), true);

    $stats = ['considered' => 0, 'suggested' => 0, 'skipped_low_similarity' => 0,
              'no_candidates' => 0, 'auto_applied' => 0, 'skipped_rejected' => 0];

    foreach ($candidates as $row) {
        $resource = (int)$row['resource'];
        $stats['considered']++;

        $result = pr_service_post('/identify', [
            'db' => $GLOBALS['mysql_db'],
            'space' => $product_recognition_space,
            'resource' => $resource,
            'k' => (int)$product_recognition_k,
            'top_n' => 3,
        ]);
        if ($result === null) {
            throw new RuntimeException("classification: vector service did not answer");
        }
        if (empty($result['candidates'])) {
            $stats['no_candidates']++;
            continue;
        }
        if ((float)$result['top_similarity'] < (float)$product_recognition_min_similarity) {
            # Nothing in the reference set looks like this, whatever the vote says.
            $stats['skipped_low_similarity']++;
            continue;
        }

        $top = $result['candidates'][0];
        $node = (int)$top['model_node'];
        $confidence = (float)$top['confidence'];
        $threshold = $thresholds[$node] ?? null;

        # Computed BEFORE the eligibility test, because auto-apply must respect a rejection
        # too. A human who rejected a model for this resource has said so; auto-applying it as
        # a tag the next night would be the same failure as re-suggesting it, in a form that
        # writes to the asset instead of to a queue. Fully-rejected resources are also excluded
        # from the outer candidate query above, so this path is only for partial rejections
        # (some candidates still open or none yet written).
        $rejected = [];
        foreach (ps_query(
            "SELECT model_node FROM product_suggestion WHERE resource = ? AND state = 'rejected'",
            array("i", $resource)) as $r) {
            $rejected[(int)$r['model_node']] = true;
        }

        $eligible = $product_recognition_auto_apply
            && !isset($rejected[$node])
            && $threshold !== null
            && (int)$threshold['never_auto_apply'] === 0
            && !isset($never_nodes[$node])
            && $threshold['status'] === 'ok'
            && $threshold['min_confidence'] !== null
            && $confidence >= (float)$threshold['min_confidence'];

        if ($eligible) {
            if (!$dry_run) {
                pr_apply_model($resource, $node, $confidence,
                               (int)($top['top_reference'] ?? $result['top_reference']),
                               (float)$threshold['min_confidence']);
            }
            $stats['auto_applied']++;
            continue;
        }

        # The candidate loop runs in dry-run too, with only the write guarded. Two reasons:
        # --dry-run's preview is then exact rather than approximate, and `suggested` can count
        # resources that actually produced a row. Incrementing it unconditionally would inflate
        # the number forever for any resource whose every candidate has been rejected - it
        # writes nothing, but would report as a suggestion on every run from then on.
        $rank = 0;
        foreach ($result['candidates'] as $candidate) {
            if (isset($rejected[(int)$candidate['model_node']])) {
                $stats['skipped_rejected']++;
                continue;
            }
            $rank++;
            if (!$dry_run) {
                ps_query(
                    "INSERT INTO product_suggestion
                        (resource, model_node, confidence, top_similarity, top_reference, rank_order, state)
                     VALUES (?,?,?,?,?,?,'open')",
                    array("i", $resource, "i", (int)$candidate['model_node'],
                          "d", (float)$candidate['confidence'], "d", (float)$result['top_similarity'],
                          # This candidate's OWN nearest reference, not the query's global
                          # nearest - a rank-2 row citing the rank-1 model's photo would make
                          # "why was this suggested" point at the wrong product.
                          "i", (int)($candidate['top_reference'] ?? $result['top_reference']),
                          "i", $rank)
                );
            }
        }
        if ($rank > 0) {
            $stats['suggested']++;
        }
    }
    return $stats;
}

/**
 * Rank-1 /identify vs a path-derived leaf. One predicate chain; the report must
 * not re-derive these reasons.
 *
 * kind: disagreed | agreed | no_candidates | skipped_low_similarity | service_empty
 *
 * @param array|null $identify decoded /identify JSON
 * @param array<int,string> $leaf_models model_node => name
 * @param array<int,array> $thresholds model_node => product_threshold row (wilson_lower key)
 * @return array{kind:string,row:?array}
 */
function pr_disagreement_outcome(
    int $resource,
    int $tagged_node,
    ?array $identify,
    array $leaf_models,
    array $thresholds,
    float $min_similarity
): array
{
    if ($identify === null || empty($identify['candidates']) || !is_array($identify['candidates'])) {
        return ['kind' => 'no_candidates', 'row' => null];
    }
    $top = $identify['candidates'][0];
    if (!is_array($top)) {
        return ['kind' => 'service_empty', 'row' => null];
    }
    if ((float)($identify['top_similarity'] ?? 0) < $min_similarity) {
        return ['kind' => 'skipped_low_similarity', 'row' => null];
    }

    $recognised_node = (int)($top['model_node'] ?? 0);
    if ($recognised_node === $tagged_node) {
        return ['kind' => 'agreed', 'row' => null];
    }
    if (!array_key_exists($recognised_node, $leaf_models)) {
        return ['kind' => 'service_empty', 'row' => null];
    }

    $wilson = $thresholds[$recognised_node]['wilson_lower'] ?? null;
    $raw_ref = $top['top_reference'] ?? ($identify['top_reference'] ?? null);

    return [
        'kind' => 'disagreed',
        'row' => [
            'resource' => $resource,
            'tagged_node' => $tagged_node,
            'tagged_name' => (string)($leaf_models[$tagged_node] ?? "node $tagged_node"),
            'recognised_node' => $recognised_node,
            'recognised_name' => (string)$leaf_models[$recognised_node],
            'confidence' => (float)($top['confidence'] ?? 0),
            'wilson_lower' => $wilson === null || $wilson === '' ? null : (float)$wilson,
            'top_similarity' => (float)$identify['top_similarity'],
            'top_reference' => $raw_ref === null || $raw_ref === '' ? null : (int)$raw_ref,
        ],
    ];
}

/**
 * TSV fields for one disagreement. Nulls are `-` so a trailing top_reference cannot vanish.
 * Names are not escaped here; the CLI writes via fputcsv.
 *
 * @return list<int|string>
 */
function pr_disagreement_tsv_fields(array $row): array
{
    return [
        $row['resource'],
        $row['tagged_node'],
        $row['tagged_name'],
        $row['recognised_node'],
        $row['recognised_name'],
        sprintf('%.3f', $row['confidence']),
        $row['wilson_lower'] === null ? '-' : sprintf('%.3f', $row['wilson_lower']),
        sprintf('%.3f', $row['top_similarity']),
        $row['top_reference'] === null ? '-' : $row['top_reference'],
    ];
}

/**
 * Compare path-derived Model tags with /identify (stored vector, no image upload).
 *
 * Read-only: never writes tags, suggestions, or references, and does not take the
 * process lock (must be runnable while classify holds it). A null /identify is a
 * hard stop unless HTTP 404 (JOIN already required a vector row). $on_row is
 * called as each disagreement is found so a later throw does not drop the TSV.
 *
 * @param callable(array):void $on_row
 * @return array{considered:int,disagreed:int,agreed:int,no_candidates:int,skipped_low_similarity:int,service_empty:int}
 */
function pr_disagreement_report(int $limit, callable $on_row): array
{
    global $product_recognition_space, $product_recognition_k, $product_recognition_min_similarity;

    $leaf_models = pr_leaf_models();
    $thresholds = [];
    foreach (ps_query("SELECT * FROM product_threshold") as $row) {
        $thresholds[(int)$row['model_node']] = $row;
    }
    $min_similarity = (float)$product_recognition_min_similarity;

    $stats = [
        'considered' => 0,
        'disagreed' => 0,
        'agreed' => 0,
        'no_candidates' => 0,
        'skipped_low_similarity' => 0,
        'service_empty' => 0,
    ];

    foreach (pr_tagged_leaf_with_vector($limit) as $tagged) {
        $resource = (int)$tagged['resource'];
        $tagged_node = (int)$tagged['model_node'];
        $stats['considered']++;

        # Stored vector only: omit image so /identify reads resource_dinov2_vector
        # (exclude_resource=query, so a tagged reference is not compared to itself).
        $http_status = 0;
        $identify = pr_service_post('/identify', [
            'db' => $GLOBALS['mysql_db'],
            'space' => $product_recognition_space,
            'resource' => $resource,
            'k' => (int)$product_recognition_k,
            'top_n' => 3,
        ], null, 600, 10, $http_status);
        if ($identify === null) {
            if ($http_status === 404) {
                $stats['service_empty']++;
                continue;
            }
            throw new RuntimeException("disagreement report: vector service did not answer");
        }

        $outcome = pr_disagreement_outcome(
            $resource, $tagged_node, $identify, $leaf_models, $thresholds, $min_similarity
        );
        $stats[$outcome['kind']]++;
        if ($outcome['row'] !== null) {
            $on_row($outcome['row']);
        }
    }

    return $stats;
}

/**
 * Product leaf plus its ancestors in the product field (parent → … → leaf).
 *
 * Category-tree writers typically stamp parent+leaf. Recognition must match that shape so an
 * accepted leaf is not a single orphan under a tree field. Flat products (no parent) return
 * just the leaf.
 *
 * @return list<int>
 */
function pr_model_nodes_with_ancestors(int $node): array
{
    # get_parent_nodes($ref, detailed=false, include_child=true) returns [ref => name], leaf first.
    $tree = get_parent_nodes($node, false, true);
    if ($tree === [] || $tree === null) {
        return [$node];
    }
    $refs = array_map('intval', array_keys($tree));
    # Walk root → leaf so add_resource_nodes sees parents before children.
    return array_reverse($refs);
}

/**
 * Add a product node (and its ancestors) to a resource and log why.
 *
 * Every auto-applied tag records its confidence, its threshold and the reference image that
 * drove it, so a wrong one can be traced back and that reference excluded. A tag whose origin
 * cannot be reconstructed cannot be corrected.
 */
function pr_apply_model(int $resource, int $node, float $confidence, int $top_reference,
                        float $threshold): void
{
    global $product_recognition_field;

    $nodes = pr_model_nodes_with_ancestors($node);
    add_resource_nodes($resource, $nodes, false, false);
    resource_log(
        $resource, LOG_CODE_EDITED, $product_recognition_field,
        sprintf("Product recognition: node %d at confidence %.3f (threshold %.3f), "
                . "nearest reference resource %d", $node, $confidence, $threshold, $top_reference)
    );
}

/**
 * Product-field node refs currently on a resource (any depth - parent and leaf both count).
 *
 * @return list<int>
 */
function pr_resource_model_nodes(int $resource): array
{
    global $product_recognition_field;

    return pr_resource_field_nodes($resource, (int)$product_recognition_field);
}

/**
 * True when the node is a leaf of the product field (no children). A non-leaf is a category,
 * not a product; pinning one teaches every later k-NN vote the wrong grain.
 */
function pr_is_leaf_model(int $node): bool
{
    global $product_recognition_field;

    $rows = ps_query(
        "SELECT n.ref FROM node n
          WHERE n.ref = ? AND n.resource_type_field = ?
            AND NOT EXISTS (SELECT 1 FROM node ch WHERE ch.parent = n.ref)",
        array("i", $node, "i", $product_recognition_field)
    );
    return $rows !== [];
}

/**
 * Open suggestions for resources that still have no product tag, best candidate first.
 *
 * Suggestions can outlive an external tag. Showing those rows would invite an accept that
 * dual-tags the asset; hide them instead. Closing them is left to accept/reject or the next
 * classify pass (which skips resources with a product node).
 */
function pr_open_suggestions(int $limit = 100): array
{
    global $product_recognition_field;

    return ps_query(
        "SELECT s.ref, s.resource, s.model_node, s.confidence, s.top_similarity,
                s.top_reference, s.rank_order, n.name AS model_name
           FROM product_suggestion s
           JOIN node n ON n.ref = s.model_node
          WHERE s.state = 'open'
            AND NOT EXISTS (
                  SELECT 1 FROM resource_node rn
                    JOIN node mn ON mn.ref = rn.node AND mn.resource_type_field = ?
                   WHERE rn.resource = s.resource)
          ORDER BY s.resource, s.rank_order
          LIMIT ?",
        array("i", $product_recognition_field, "i", $limit)
    );
}

/**
 * Accept a suggestion: tag the resource, pin it as a reference, close its siblings.
 *
 * The pin records the product explicitly rather than re-deriving it from the resource's tag.
 * If that tag later changes, a pin that silently re-points at a different product is a
 * reference that quietly teaches the wrong thing.
 *
 * Order matters: claim the open row before writing the tag. Two concurrent accepts of
 * different candidates for the same resource must not both add product nodes. Writing the
 * tag first left a window where both succeeded and the last pin won.
 */
function pr_accept_suggestion(int $suggestion, int $user): bool
{
    global $product_recognition_field;

    $rows = ps_query("SELECT * FROM product_suggestion WHERE ref = ? AND state = 'open'",
                     array("i", $suggestion));
    if ($rows === []) {
        return false;
    }
    $row = $rows[0];
    $resource = (int)$row['resource'];
    $node = (int)$row['model_node'];

    # A non-leaf is a category. Accepting it would tag the asset with a branch and pin
    # that category into the voting set.
    if (!pr_is_leaf_model($node)) {
        return false;
    }

    $existing = pr_resource_model_nodes($resource);
    # Already tagged with a different model: do not dual-tag. Leave the open row alone so an
    # operator can reject it; the review queue no longer lists it (pr_open_suggestions).
    if ($existing !== [] && !in_array($node, $existing, true)) {
        return false;
    }

    # Claim this candidate first. If another accept already took it (or superseded it as a
    # sibling), affected rows is 0 and we write nothing.
    ps_query(
        "UPDATE product_suggestion SET state = 'accepted', decided_by = ?, decided_at = NOW()
          WHERE ref = ? AND state = 'open'",
        array("i", $user, "i", $suggestion)
    );
    if (sql_affected_rows() !== 1) {
        return false;
    }
    ps_query(
        "UPDATE product_suggestion SET state = 'superseded', decided_by = ?, decided_at = NOW()
          WHERE resource = ? AND state = 'open'",
        array("i", $user, "i", $resource)
    );

    if ($existing === []) {
        pr_apply_model($resource, $node, (float)$row['confidence'], (int)$row['top_reference'], 0.0);
    } else {
        # Same leaf already present (tagged while the suggestion was open): pin only,
        # but fill any missing ancestors so a lone leaf under a tree field still matches
        # the parent+leaf shape.
        $missing = array_values(array_diff(pr_model_nodes_with_ancestors($node), $existing));
        if ($missing !== []) {
            add_resource_nodes($resource, $missing, false, false);
        }
    }

    ps_query(
        "INSERT INTO product_reference (resource, model_node, status, created_by)
         VALUES (?,?,'pinned',?)
         ON DUPLICATE KEY UPDATE model_node=VALUES(model_node), status='pinned',
                                 created_by=VALUES(created_by)",
        array("i", $resource, "i", $node, "i", $user)
    );
    return true;
}

/**
 * Reject one candidate. The resource's other candidates stay open - rejecting one product
 * is not a statement about another.
 */
function pr_reject_suggestion(int $suggestion, int $user): bool
{
    $rows = ps_query("SELECT ref FROM product_suggestion WHERE ref = ? AND state = 'open'",
                     array("i", $suggestion));
    if ($rows === []) {
        return false;
    }
    ps_query(
        "UPDATE product_suggestion SET state='rejected', decided_by=?, decided_at=NOW() WHERE ref=?",
        array("i", $user, "i", $suggestion)
    );
    return true;
}

/**
 * Shared process lock name for every writer (nightly --all and manual CLI stages).
 *
 * Manual --backfill / --calibrate / --classify used to skip this lock, so an operator run
 * could collide with cron mid-pass. One name, every writer.
 */
function pr_lock_name(): string
{
    return 'product_recognition';
}

/**
 * Run $fn under the product_recognition process lock, or return ['locked' => true].
 *
 * Dry-run callers should not take the lock: they write nothing and must not block the nightly
 * pass for a read-only preview.
 *
 * @param callable():array $fn
 * @return array{locked:bool}|array
 */
function pr_with_lock(callable $fn): array
{
    $lock = pr_lock_name();
    if (is_process_lock($lock)) {
        logScript("product_recognition: another run holds the lock, exiting");
        return ['locked' => true];
    }
    set_process_lock($lock);
    try {
        $out = $fn();
        if (!is_array($out)) {
            $out = [];
        }
        $out['locked'] = false;
        return $out;
    } finally {
        clear_process_lock($lock);
    }
}

/**
 * The nightly pass: references, then a bounded embedding slice, then calibration, then
 * classification. One lock across all four.
 *
 * The backfill can outrun its window by design - the full library is ~12 hours - so a slow
 * night must not overlap the next. A second run exits immediately rather than embedding the
 * same resources twice or calibrating against a half-written reference set. The backfill
 * resumes from its recorded position; it does not restart.
 */
function pr_run_all(): array
{
    global $product_recognition_batch, $product_recognition_classify_batch;

    if (!pr_is_configured()) {
        return ["unconfigured" => true];
    }

    return pr_with_lock(function () use ($product_recognition_batch, $product_recognition_classify_batch) {
        return [
            'references' => pr_sync_references(),
            'backfill' => pr_backfill((int)$product_recognition_batch),
            'calibration' => pr_calibrate(),
            'classification' => pr_classify((int)$product_recognition_classify_batch),
        ];
    });
}

/**
 * ---------------------------------------------------------------------------------------
 * Product catalogue ingest (#429)
 *
 * The client's DAM SKU sheet is the authoritative SKU -> model + colorway + UPC mapping, and
 * it lives in the DAM as a PDF. This reads it and keeps `product_catalog` in step with it.
 *
 * Deliberately a faithful transcript, not a bound taxonomy: rows carry the raw strings the
 * sheet uses, not node refs. Matching those onto the product tree is the review-queue half of
 * the ingest design and is not built - so a sheet that says `Olive/Yellow` where the
 * tree says `Olive` records `Olive/Yellow` here and nothing tries to reconcile it.
 * That is what makes this stage independently useful and the next one optional.
 * ---------------------------------------------------------------------------------------
 */

/**
 * Split a sheet PRODUCT string into model text and colorway text.
 *
 * The sheet uses three separators depending on section - `NORTH-Matte Black` (hyphen),
 * `Cooler Bag | Frost Box | BLACK` (pipe) and `Example Cap I BLACK` (a capital I
 * standing in for a pipe, in the source data itself). Splitting on the LAST separator is
 * what handles the multi-part hat and cooler rows, where the colorway is the final segment
 * and everything before it is the product name.
 *
 * With no separator the string is a colorway, not a model: the Towels section names its
 * products by colorway, and the model for all of them is the
 * section. So an unsplittable string goes to colorway and `model_text` is left empty for the
 * section to supply - putting it in `model_text` would file colorways as model names.
 *
 * ponytail: heuristic with a known ceiling. It is wrong where the last segment is a size
 * rather than a colour (`North Rainhood | Black | Magnum` yields colorway `Magnum`), where the
 * tail is a variant (`Double Straps - Universal Backpack Straps`), and it would split a
 * hyphenated word if the sheet ever contained one - no row does today, but `Pro-Fit Hat` would
 * become `Pro` / `Fit Hat` the day one appears. All three are why `product` is stored raw
 * alongside the split: a bad split is recoverable without re-reading the PDF, and the review
 * queue that resolves them is the next stage's job, not this one's.
 *
 * @return array{0:string,1:string} [model_text, colorway_text]
 */
function pr_split_product(string $product): array
{
    $product = trim($product);
    if (preg_match_all('/\s*\|\s*|\s+I\s+|\s*-\s*/', $product, $m, PREG_OFFSET_CAPTURE) < 1) {
        return ['', $product];
    }
    $last = end($m[0]);
    $model = rtrim(substr($product, 0, $last[1]));
    $colorway = ltrim(substr($product, $last[1] + strlen($last[0])));
    if ($model === '' || $colorway === '') {
        return ['', $product];                     # a leading/trailing separator, not a split
    }
    return [$model, $colorway];
}

/**
 * Parse `pdftotext -layout` output of the DAM SKU sheet into catalogue rows.
 *
 * Structure: a `SKU  <section name>  UPC ...` header opens a section and every following row
 * whose first column is a bare code belongs to it. The sheet is two pages concatenated, each
 * with its own master `SKU  PRODUCT  UPC CODE ...` header and its own `North Bag` section, so
 * sections repeat and the parser must not treat a second header as the end of anything.
 *
 * Column positions are not stable between pages or sections, so nothing is read positionally
 * beyond the first two: UPC is recognised by shape, and the description is whatever readable
 * column follows it. Rows legitimately lack both - `RH201` has no UPC, `STRP101` has neither.
 *
 * @return array<string,array<string,mixed>> keyed by SKU, last occurrence wins
 */
function pr_parse_sku_sheet(string $text): array
{
    $section = '';
    $rows = [];

    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $cols = preg_split('/\s{2,}/', $line);

        if ($cols[0] === 'SKU') {
            $label = trim($cols[1] ?? '');
            # The master header names the column, not a section; keep the section it interrupts.
            if ($label !== '' && strcasecmp($label, 'PRODUCT') !== 0) {
                $section = $label;
            }
            continue;
        }

        # A code, not necessarily numeric (`ICEBRICK`) and not necessarily unsuffixed
        # (`HAT140-712`, where the suffix is the hat size and each size is its own SKU).
        if (!preg_match('/^[A-Z][A-Z0-9]{2,}(?:-[A-Z0-9]+)*$/', $cols[0]) || count($cols) < 2) {
            continue;
        }
        $sku = $cols[0];
        $product = trim($cols[1]);
        if ($product === '') {
            continue;
        }

        $upc = null;
        $next = 2;
        if (isset($cols[2]) && preg_match('/^\d{11,14}$/', trim($cols[2]))) {
            $upc = trim($cols[2]);
            $next = 3;
        }

        $description = null;
        $candidate = trim($cols[$next] ?? '');
        if (strlen($candidate) >= 4
            && preg_match('/[A-Za-z]/', $candidate)
            && !preg_match('/^(none|lbs|ounces|oz)$/i', $candidate)) {
            $description = $candidate;
        }

        [$model_text, $colorway_text] = pr_split_product($product);
        $rows[$sku] = ['sku' => $sku, 'section' => $section, 'product' => $product,
                       'model_text' => $model_text, 'colorway_text' => $colorway_text,
                       'upc' => $upc, 'description' => $description];
    }

    return $rows;
}

/**
 * Extract text from a PDF resource, or null if it has no PDF file or poppler fails.
 *
 * `-layout` is not optional: the parser reads columns as runs of two or more spaces, and
 * without it pdftotext emits one token per line and every row silently disappears.
 */
function pr_pdf_text(int $resource): ?string
{
    $path = get_resource_path($resource, true, '', false, 'pdf');
    if ($path === '' || !file_exists($path)) {
        return null;
    }
    $cmd = get_utility_path('pdftotext');
    if ($cmd === false) {
        return null;
    }
    $text = shell_exec($cmd . ' -layout ' . escapeshellarg($path) . ' - 2>/dev/null');
    return (is_string($text) && trim($text) !== '') ? $text : null;
}

/**
 * Find the SKU sheet to read.
 *
 * Not a fixed resource ref on purpose. The sheet is re-imported, and the copies are not
 * equal: one may be a superset of another. Rather than encode which ref is best and go
 * stale, parse every candidate and keep the one that yields the most rows.
 *
 * Known cost of that rule: the superset by SKU is not always the superset by quality.
 * A second copy can label a section with the wrong name. This stays a faithful transcript
 * of the sheet rather than second-guessing a header. Fix that in the sheet, not here.
 *
 * @return array{resource:int,rows:array}|null
 */
function pr_catalog_source(): ?array
{
    $match = trim((string)($GLOBALS["product_recognition_catalog_title_match"] ?? ""));
    if ($match === "") {
        return null;
    }
    $title_field = (int)($GLOBALS['view_title_field'] ?? 8);
    # RS 11 has no resource_data table - every field value, free text included, is a node
    # (r19945). Joining resource_data here is the obvious-looking query and it is a fatal.
    $candidates = ps_query(
        "SELECT DISTINCT r.ref
           FROM resource r
           JOIN resource_node rn ON rn.resource = r.ref
           JOIN node n ON n.ref = rn.node AND n.resource_type_field = ?
          WHERE r.file_extension = 'pdf' AND r.archive = 0 AND n.name LIKE ?
          ORDER BY r.ref",
        array("i", $title_field, "s", "%" . $match . "%")
    );

    $best = null;
    foreach ($candidates as $row) {
        $resource = (int)$row['ref'];
        $text = pr_pdf_text($resource);
        if ($text === null) {
            continue;
        }
        $rows = pr_parse_sku_sheet($text);
        if ($best === null || count($rows) > count($best['rows'])) {
            $best = ['resource' => $resource, 'rows' => $rows];
        }
    }

    return ($best === null || $best['rows'] === []) ? null : $best;
}

/**
 * Bring `product_catalog` into step with the sheet.
 *
 * Adds and updates; never deletes. A SKU dropped from a newer sheet is a discontinued product,
 * not a mistake, and the resources tagged from it still need the row to resolve. Removal is a
 * deliberate act, not a side effect of a re-import.
 *
 * Deliberately not part of pr_run_all(): the sheet changes a few times a year, and the nightly
 * pass is already sized against an embedding budget. Run --catalog when a new sheet lands.
 *
 * @param int|null $resource Read this resource instead of discovering the sheet.
 * @return array stats
 */
function pr_sync_catalog(bool $dry_run = false, ?int $resource = null): array
{
    if ($resource !== null) {
        $text = pr_pdf_text($resource);
        $source = ($text === null) ? null : ['resource' => $resource, 'rows' => pr_parse_sku_sheet($text)];
    } else {
        $source = pr_catalog_source();
    }
    if ($source === null || $source['rows'] === []) {
        return ['source' => 0, 'parsed' => 0, 'added' => 0, 'updated' => 0, 'unchanged' => 0,
                'sections' => [], 'error' => 'no readable SKU sheet found'];
    }

    $existing = [];
    foreach (ps_query("SELECT sku, section, product, model_text, colorway_text, upc, description
                         FROM product_catalog") as $row) {
        $existing[$row['sku']] = $row;
    }

    $stats = ['source' => $source['resource'], 'parsed' => count($source['rows']),
              'added' => 0, 'updated' => 0, 'unchanged' => 0, 'sections' => [], 'error' => null];

    foreach ($source['rows'] as $sku => $row) {
        $stats['sections'][$row['section']] = ($stats['sections'][$row['section']] ?? 0) + 1;
        $current = $existing[$sku] ?? null;

        if ($current !== null) {
            $same = true;
            foreach (['section', 'product', 'model_text', 'colorway_text', 'upc', 'description'] as $col) {
                if ((string)$current[$col] !== (string)$row[$col]) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                $stats['unchanged']++;
                continue;
            }
            $stats['updated']++;
            if (!$dry_run) {
                ps_query("UPDATE product_catalog
                             SET section = ?, product = ?, model_text = ?, colorway_text = ?,
                                 upc = ?, description = ?, source_resource = ?, updated = NOW()
                           WHERE sku = ?",
                         array("s", $row['section'], "s", $row['product'], "s", $row['model_text'],
                               "s", $row['colorway_text'], "s", $row['upc'], "s", $row['description'],
                               "i", $source['resource'], "s", $sku));
            }
            continue;
        }

        $stats['added']++;
        if (!$dry_run) {
            ps_query("INSERT INTO product_catalog
                          (sku, section, product, model_text, colorway_text, upc, description,
                           source_resource, updated)
                      VALUES (?,?,?,?,?,?,?,?,NOW())",
                     array("s", $sku, "s", $row['section'], "s", $row['product'],
                           "s", $row['model_text'], "s", $row['colorway_text'], "s", $row['upc'],
                           "s", $row['description'], "i", $source['resource']));
        }
    }

    ksort($stats['sections']);
    return $stats;
}

/**
 * Fold a sheet string or node name for unique binding. Case, punctuation, and extra
 * spaces drop out so `NORTH` matches `North` without matching `North XL`.
 */
function pr_fold_label(string $name): string
{
    $name = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
    $name = strtolower(trim($name));
    $name = preg_replace("/['’`]/u", '', $name) ?? $name;
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;
    return trim($name);
}

/**
 * Unique folded-name match against a node-id => name map. Zero or two hits is unbound.
 */
function pr_unique_folded_node(string $text, array $nodes): ?int
{
    $want = pr_fold_label($text);
    if ($want === '') {
        return null;
    }
    $hits = [];
    foreach ($nodes as $id => $name) {
        if (pr_fold_label((string)$name) === $want) {
            $hits[] = (int)$id;
        }
    }
    return count($hits) === 1 ? $hits[0] : null;
}

/**
 * Folded never-auto-apply names. Split on comma, semicolon, or newline.
 *
 * @return list<string>
 */
function pr_never_auto_apply_names(?string $raw = null): array
{
    $raw ??= (string)($GLOBALS['product_recognition_never_auto_apply'] ?? '');
    $out = [];
    $seen = [];
    foreach (preg_split('/[,;\r\n]+/', $raw) ?: [] as $part) {
        $folded = pr_fold_label((string)$part);
        if ($folded === '' || isset($seen[$folded])) {
            continue;
        }
        $seen[$folded] = true;
        $out[] = $folded;
    }
    return $out;
}

/**
 * Unique leaf ids for never-auto-apply names. Misses and ambiguous hits are skipped.
 *
 * @param array<int,string> $leaves node_id => name
 * @return list<int>
 */
function pr_never_auto_apply_nodes(array $leaves, ?string $raw = null): array
{
    $ids = [];
    foreach (pr_never_auto_apply_names($raw) as $name) {
        $id = pr_unique_folded_node($name, $leaves);
        if ($id !== null) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * Folded config names that did not unique-bind against $leaves.
 *
 * @param array<int,string> $leaves node_id => name
 * @return list<string>
 */
function pr_never_auto_apply_unmatched(array $leaves, ?string $raw = null): array
{
    $miss = [];
    foreach (pr_never_auto_apply_names($raw) as $name) {
        if (pr_unique_folded_node($name, $leaves) === null) {
            $miss[] = $name;
        }
    }
    return $miss;
}

function pr_bind_catalog_model(string $model_text, array $leaves): ?int
{
    return pr_unique_folded_node($model_text, $leaves);
}

/**
 * Slash colorways (`Olive/Yellow`) stay unbound — a person picks `Olive`.
 */
function pr_bind_catalog_colorway(string $colorway_text, array $leaves): ?int
{
    if (str_contains($colorway_text, '/')) {
        return null;
    }
    return pr_unique_folded_node($colorway_text, $leaves);
}

/**
 * Leaf nodes of any fixed-list field: node => name.
 *
 * @return array<int,string>
 */
function pr_field_leaves(int $field): array
{
    if ($field <= 0) {
        return [];
    }
    $rows = ps_query(
        "SELECT n.ref, n.name FROM node n
          WHERE n.resource_type_field = ?
            AND NOT EXISTS (SELECT 1 FROM node ch WHERE ch.parent = n.ref)
          ORDER BY n.name",
        array("i", $field)
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['ref']] = $r['name'];
    }
    return $out;
}

/**
 * @return list<int>
 */
function pr_resource_field_nodes(int $resource, int $field): array
{
    if ($field <= 0) {
        return [];
    }
    $rows = ps_query(
        "SELECT rn.node FROM resource_node rn
           JOIN node n ON n.ref = rn.node AND n.resource_type_field = ?
          WHERE rn.resource = ?",
        array("i", $field, "i", $resource)
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = (int)$r['node'];
    }
    return $out;
}

/**
 * Sheet model for pickers: empty model_text uses the section (Towels).
 */
function pr_catalog_model_text(array $row): string
{
    $model = (string)($row['model_text'] ?? '');
    return $model !== '' ? $model : (string)($row['section'] ?? '');
}

/**
 * Picker rows. Empty sheet model_text uses the section (Towels). Bind happens on Apply/Find.
 *
 * @return list<array{sku:string,model_text:string,colorway_text:string}>
 */
function pr_catalog_picker_rows(): array
{
    if (!pr_is_configured()) {
        return [];
    }
    $out = [];
    foreach (ps_query(
        "SELECT sku, section, model_text, colorway_text FROM product_catalog ORDER BY section, sku"
    ) as $row) {
        $out[] = [
            'sku' => (string)$row['sku'],
            'model_text' => pr_catalog_model_text($row),
            'colorway_text' => (string)$row['colorway_text'],
        ];
    }
    return $out;
}

/**
 * Decide catalog writes without touching the DB. Path-derived Model is kept (no dual-tag,
 * no replace). Colorway is add-only.
 *
 * @param list<int> $existing_leaves
 * @param list<int> $existing_colorway
 * @return array{add_model:bool,skip_model:bool,pin:bool,add_colorway:bool}
 */
function pr_catalog_apply_plan(
    array $existing_leaves,
    ?int $model_node,
    ?int $colorway_node,
    array $existing_colorway
): array {
    $others = $model_node === null
        ? $existing_leaves
        : array_values(array_diff($existing_leaves, [$model_node]));
    $has_other = $others !== [];
    $add_model = $model_node !== null && !$has_other;
    return [
        'add_model' => $add_model,
        'skip_model' => !$add_model,
        'pin' => $add_model,
        'add_colorway' => $colorway_node !== null
            && !in_array($colorway_node, $existing_colorway, true),
    ];
}

function pr_apply_colorway(int $resource, int $node): void
{
    global $product_recognition_colorway_field;

    add_resource_nodes($resource, pr_model_nodes_with_ancestors($node), false, false);
    resource_log(
        $resource,
        LOG_CODE_EDITED,
        $product_recognition_colorway_field,
        sprintf("Product recognition: catalog colorway node %d", $node)
    );
}

/**
 * Apply a catalog Model + Colorway pick. Add-only. SKU is an audit string from the
 * matching catalog row, not an input the operator posts.
 *
 * @param list<array{sku:string,model_text:string,colorway_text:string}> $catalog
 * @return array{sku:string,add_model:bool,skip_model:bool,pin:bool,add_colorway:bool}
 */
function pr_apply_catalog_pick(
    int $resource,
    string $model_text,
    string $colorway_text,
    int $user,
    array $catalog
): array {
    global $product_recognition_field, $product_recognition_colorway_field;

    $sku = pr_catalog_sku_for_pick($model_text, $colorway_text, $catalog);
    $blank = pr_catalog_apply_plan([], null, null, []);
    $blank['sku'] = $sku;
    if ($resource <= 0 || $sku === '' || !pr_is_configured()) {
        return $blank;
    }
    $leaves = pr_leaf_models();
    $model_node = pr_bind_catalog_model($model_text, $leaves);
    $cw_field = (int)($product_recognition_colorway_field ?? 0);
    $colorway_node = $cw_field > 0
        ? pr_bind_catalog_colorway($colorway_text, pr_field_leaves($cw_field))
        : null;

    $existing_model = pr_resource_model_nodes($resource);
    $existing_leaves = array_values(array_intersect($existing_model, array_keys($leaves)));
    $existing_cw = $cw_field > 0 ? pr_resource_field_nodes($resource, $cw_field) : [];
    $plan = pr_catalog_apply_plan($existing_leaves, $model_node, $colorway_node, $existing_cw);
    $plan['sku'] = $sku;

    if ($plan['add_model'] && $model_node !== null) {
        $missing = array_values(array_diff(pr_model_nodes_with_ancestors($model_node), $existing_model));
        if ($missing !== []) {
            add_resource_nodes($resource, $missing, false, false);
            resource_log(
                $resource,
                LOG_CODE_EDITED,
                $product_recognition_field,
                sprintf("Product recognition: catalog SKU %s → node %d", $sku, $model_node)
            );
        }
        $existing_ref = (int)ps_value(
            "SELECT model_node AS value FROM product_reference WHERE resource = ?",
            array("i", $resource),
            0
        );
        # UNIQUE(resource): never retarget a pin/derived row for a different model.
        if ($existing_ref === 0) {
            ps_query(
                "INSERT INTO product_reference (resource, model_node, status, created_by)
                 VALUES (?,?,'pinned',?)",
                array("i", $resource, "i", $model_node, "i", $user)
            );
        } elseif ($existing_ref === $model_node) {
            ps_query(
                "UPDATE product_reference SET status = 'pinned', created_by = ?
                  WHERE resource = ? AND model_node = ? AND status IN ('derived','orphaned')",
                array("i", $user, "i", $resource, "i", $model_node)
            );
        }
    }
    if ($plan['add_colorway'] && $colorway_node !== null) {
        pr_apply_colorway($resource, $colorway_node);
    }
    return $plan;
}

/**
 * !list token (colon-separated). Empty input is empty — do not emit a bare `!list`.
 *
 * @param list<int> $refs
 */
function pr_other_match_search_token(array $refs): string
{
    $refs = array_values(array_filter(array_map('intval', $refs), fn($n) => $n > 0));
    if ($refs === []) {
        return '';
    }
    return '!list' . implode(':', $refs);
}

/**
 * Other resources in this product's reference set (the k-NN index), then open/accepted
 * suggestions at or above the model's calibrated threshold. Not a live /identify of the crop.
 * Caps so the !list URL stays short.
 *
 * @return list<int>
 */
function pr_other_match_refs(int $model_node, int $exclude, int $limit = 80): array
{
    global $product_recognition_field;

    if ($model_node <= 0 || $limit <= 0) {
        return [];
    }
    $refs = ps_array(
        "SELECT pr.resource AS value
           FROM product_reference pr
           JOIN resource r ON r.ref = pr.resource AND r.archive >= 0
          WHERE pr.model_node = ? AND pr.status IN ('derived','pinned') AND pr.resource <> ?
          ORDER BY pr.resource DESC LIMIT ?",
        array("i", $model_node, "i", $exclude, "i", $limit)
    );
    $refs = array_map('intval', $refs);
    if (count($refs) >= $limit) {
        return $refs;
    }
    $more = $limit - count($refs);
    $sql = "SELECT s.resource AS value
              FROM product_suggestion s
              JOIN product_threshold t ON t.model_node = s.model_node
              JOIN resource r ON r.ref = s.resource AND r.archive >= 0
             WHERE s.model_node = ? AND s.resource <> ?
               AND t.status = 'ok' AND s.confidence >= t.min_confidence
               AND (
                    s.state = 'accepted'
                 OR (s.state = 'open' AND NOT EXISTS (
                        SELECT 1 FROM resource_node rn
                          JOIN node mn ON mn.ref = rn.node AND mn.resource_type_field = ?
                         WHERE rn.resource = s.resource))
               )";
    $params = array("i", $model_node, "i", $exclude, "i", $product_recognition_field);
    if ($refs !== []) {
        $sql .= " AND s.resource NOT IN (" . ps_param_insert(count($refs)) . ")";
        $params = array_merge($params, ps_param_fill($refs, "i"));
    }
    $sql .= " ORDER BY s.confidence DESC, s.resource DESC LIMIT ?";
    $params[] = "i";
    $params[] = $more;
    foreach (ps_array($sql, $params) as $n) {
        $refs[] = (int)$n;
    }
    return $refs;
}

/**
 * First catalog SKU whose picker labels match the posted selects. Empty model is
 * nothing to apply. Exact on the already-resolved picker texts (section fills an
 * empty sheet model). Caller passes pr_catalog_picker_rows() — do not take a SKU.
 *
 * @param list<array{sku:string,model_text:string,colorway_text:string}> $catalog
 */
function pr_catalog_sku_for_pick(string $model_text, string $colorway_text, array $catalog): string
{
    if ($model_text === '') {
        return '';
    }
    foreach ($catalog as $row) {
        if ($row['model_text'] === $model_text && $row['colorway_text'] === $colorway_text) {
            return (string)$row['sku'];
        }
    }
    return '';
}

/**
 * @param list<array{sku:string,model_text:string,colorway_text:string}> $catalog
 * @return array{sku:string,model_text:string,colorway_text:string}
 */
function pr_picker_preselect(string $model_name, string $colorway, array $catalog): array
{
    $empty = ['sku' => '', 'model_text' => '', 'colorway_text' => ''];
    $want_model = pr_fold_label($model_name);
    if ($want_model === '' || $catalog === []) {
        return $empty;
    }
    $want_cw = pr_fold_label($colorway);
    $first = $empty;
    foreach ($catalog as $row) {
        if (pr_fold_label($row['model_text']) !== $want_model) {
            continue;
        }
        $pick = [
            'sku' => $row['sku'],
            'model_text' => $row['model_text'],
            'colorway_text' => $row['colorway_text'],
        ];
        if ($first['sku'] === '') {
            $first = $pick;
        }
        if ($want_cw !== '' && pr_fold_label($row['colorway_text']) === $want_cw) {
            return $pick;
        }
    }
    return $first;
}

include_once __DIR__ . '/product_recognition_view.php';
