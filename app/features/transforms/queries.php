<?php
declare(strict_types=1);

/**
 * The transforms' reads (slice 7, transforms-products-lineage.md "Query functions"; replicates slice 1's catalog queries and slice 6's sources): EVERY row a person sees
 * comes through a walled view — mcp_transforms and mcp_transform_runs (shown to whoever DISCOVERS the output dataset), mcp_datasets (an input the person may not discover
 * is counted, never named), mcp_members. The SQL itself is shown only to someone who reads EVERY input (dl_level of each ≥ read): a transform's SQL names datasets
 * the viewer may not be allowed to know. transforms_due() is the WORKER's: it has no member, so it reads the base tables (the mcp_* views answer a member only).
 * The same functions answer the screens' JSON and (Phase 4) the records tools find_transforms and get_transform.
 *
 *   find_transforms($pdo, $filters, $page)      [rows, total] — filters: dataset (an id: its output OR one of its inputs), enabled (yes|no), q, owner (an id)
 *   find_transform($pdo, $id)                   one transform with its output, its visible inputs (and how many are hidden), the owner, the last run, `may_read_sql`
 *   transform_runs($pdo, $id, $limit)           its last runs, newest first
 *   transforms_due($pdo, $now)                  the worker's: [id, name, why] — a cron schedule with a matching minute since the last run, or after_inputs with an input newer
 *   transform_enqueue_run($pdo, $id, $by, $why) a transform_runs row (queued) and the writer's job — the in-flight one when there is one
 *   transforms_for_pick(…)                      the `transform` picker; transform_outputs_for_pick(…) the datasets a transform may write (the form's output)
 */

const TRANSFORM_PAGE = 24;
const TRANSFORM_MODES = ['replace' => 'Replace — the whole result each run', 'incremental_merge' => 'Incremental merge — only what changed, by the output\'s key (row-wise SQL)'];
const TRANSFORM_TABS = ['sql' => 'SQL', 'io' => 'Inputs and output', 'runs' => 'Runs', 'lineage' => 'Lineage'];
/** A run's status as a badge: [bootstrap colour, words]. */
const TRANSFORM_RUN_STATUS = ['queued' => ['secondary', 'Queued'], 'running' => ['primary', 'Running'], 'ok' => ['success', 'Done'], 'no_op' => ['light', 'Nothing new'], 'failed' => ['danger', 'Failed']];

require_once dirname(__DIR__) . '/catalog/queries.php';          // like_pattern, text_array_literal
require_once __DIR__ . '/cron.php';

/** The list's filters from the query string — anything unknown dropped: dataset (a key or id), enabled, q, owner. */
function transform_filters_from_request(PDO $pdo): array
{
    $f = [];
    if (($d = request_string('dataset')) !== '') {
        $ds = dataset_by_ref($pdo, $d);
        $f['dataset'] = $ds === null ? -1 : (int) $ds['id'];
    }
    $e = request_string('enabled');
    if ($e === 'yes' || $e === 'no') {
        $f['enabled'] = $e;
    }
    $q = mb_substr(trim(request_string('q')), 0, 120);
    if ($q !== '') {
        $f['q'] = $q;
    }
    if (ctype_digit(request_string('owner')) && strlen(request_string('owner')) <= 18) {
        $f['owner'] = (int) request_string('owner');
    }
    return $f;
}

/** The columns every transform query selects. `input_visible` / `input_hidden` count the inputs the caller may discover; `may_read_sql` is true when every input is readable. */
const TRANSFORM_SELECT = "t.transform_id, t.name, t.description, t.output_dataset_id, t.sql, t.input_dataset_ids, t.mode, t.schedule, t.version, t.owner_member_id, t.enabled,
       t.frozen_at, t.last_run_id, t.updated_at, o.key AS output_key, o.title AS output_title, o.zone AS output_zone, o.certification AS output_certification,
       o.row_count AS output_rows, o.data_updated_at AS output_updated_at, m.display_name AS owner_name,
       r.status AS last_status, r.rows_written AS last_rows, r.started_at AS last_started_at, r.finished_at AS last_finished_at, r.error AS last_error, r.version AS last_version,
       (SELECT count(*) FROM mcp_datasets i WHERE i.dataset_id = ANY (t.input_dataset_ids) AND NOT i.is_stub) AS input_visible,
       cardinality(t.input_dataset_ids) AS input_total,
       NOT EXISTS (SELECT 1 FROM unnest(t.input_dataset_ids) x WHERE dl_level_rank(dl_level(x)) < 2) AS may_read_sql,
       (SELECT COALESCE(json_agg(json_build_object('dataset_id', i.dataset_id, 'key', i.key, 'title', i.title, 'zone', i.zone, 'certification', i.certification) ORDER BY i.key), '[]')
          FROM mcp_datasets i WHERE i.dataset_id = ANY (t.input_dataset_ids) AND NOT i.is_stub)::text AS inputs_json";
const TRANSFORM_FROM = "mcp_transforms t JOIN mcp_datasets o ON o.dataset_id = t.output_dataset_id AND NOT o.is_stub
       LEFT JOIN mcp_members m ON m.member_id = t.owner_member_id LEFT JOIN mcp_transform_runs r ON r.transform_run_id = t.last_run_id";

function transform_unpack(array $r): array
{
    $r['inputs'] = json_decode((string) $r['inputs_json'], true) ?: [];
    unset($r['inputs_json']);
    $r['input_ids'] = pg_int_array((string) $r['input_dataset_ids']);
    $r['input_hidden'] = max(0, (int) $r['input_total'] - (int) $r['input_visible']);
    $r['may_read_sql'] = (bool) $r['may_read_sql'];
    $r['enabled'] = (bool) $r['enabled'];
    return $r;
}

/** Which transforms — one page (TRANSFORM_PAGE): the not-frozen and failing first? No: by name. `limit` (≤ 100) for the records tool. Returns ['rows', 'total']. */
function find_transforms(PDO $pdo, array $f, int $page): array
{
    $w = ['true'];
    $a = [];
    if (($f['dataset'] ?? null) !== null) {
        $w[] = '(t.output_dataset_id = :ds OR :ds2 = ANY (t.input_dataset_ids))';
        $a['ds'] = $a['ds2'] = (int) $f['dataset'];
    }
    if (($f['enabled'] ?? null) === 'yes') {
        $w[] = 't.enabled';
    } elseif (($f['enabled'] ?? null) === 'no') {
        $w[] = 'NOT t.enabled';
    }
    if (($f['q'] ?? '') !== '') {
        $w[] = '(t.name ILIKE :q OR t.description ILIKE :q OR o.key ILIKE :q OR o.title ILIKE :q)';
        $a['q'] = like_pattern((string) $f['q']);
    }
    if (($f['owner'] ?? null) !== null) {
        $w[] = 't.owner_member_id = :owner';
        $a['owner'] = (int) $f['owner'];
    }
    $where = implode(' AND ', $w);
    $limit = max(1, min(100, (int) ($f['limit'] ?? TRANSFORM_PAGE)));
    $total = (int) one_value($pdo, 'SELECT count(*) FROM ' . TRANSFORM_FROM . " WHERE $where", $a);
    $st = $pdo->prepare('SELECT ' . TRANSFORM_SELECT . ' FROM ' . TRANSFORM_FROM . " WHERE $where ORDER BY (NOT t.enabled), t.name, t.transform_id LIMIT $limit OFFSET " . (max(0, $page - 1) * $limit));
    $st->execute($a);
    return ['rows' => array_map('transform_unpack', $st->fetchAll()), 'total' => $total];
}

function find_transform(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . TRANSFORM_SELECT . ' FROM ' . TRANSFORM_FROM . ' WHERE t.transform_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r = transform_unpack($r);
    // what the application's own role may add: the output's key columns (for an incremental merge), the row's sha, the output's level for the caller
    $r += one_row($pdo, 'SELECT d.dedupe_key AS output_key_columns, t.sql_sha256 FROM transforms t JOIN datasets d ON d.id = t.output_dataset_id WHERE t.id = :id', ['id' => $id])
        ?? ['output_key_columns' => '{}', 'sql_sha256' => ''];
    $r['output_key_columns'] = array_values(array_filter(pg_text_array((string) $r['output_key_columns'])));
    $r['output_level'] = dataset_level($pdo, (int) $r['output_dataset_id']);
    return $r;
}

/** A transform's last runs (mcp_transform_runs), newest first. */
function transform_runs(PDO $pdo, int $id, int $limit): array
{
    $st = $pdo->prepare('SELECT transform_run_id, transform_id, version, status, input_snapshots, output_snapshot, rows_written, error, started_at, finished_at
                           FROM mcp_transform_runs WHERE transform_id = :t ORDER BY transform_run_id DESC LIMIT ' . max(1, min(200, $limit)));
    $st->execute(['t' => $id]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['input_snapshots'] = json_decode((string) $r['input_snapshots'], true) ?: [];
    }
    unset($r);
    return $rows;
}

/** Is a run queued or running for it (mcp_transform_runs, newest first)? Returns that run's id and status, or null. */
function transform_in_flight(PDO $pdo, int $id): ?array
{
    return one_row($pdo, "SELECT transform_run_id, status FROM mcp_transform_runs WHERE transform_id = :t AND status IN ('queued', 'running') ORDER BY transform_run_id DESC LIMIT 1", ['t' => $id]);
}

/**
 * The transforms the worker queues now (it has no member: base tables): enabled, frozen, with a schedule, no run queued or running (one older than six hours is abandoned
 * by the pass first), and either a CRON schedule with a matching minute since the last run (or since it was last saved) in the business's time zone, or `after_inputs`
 * with every input holding data and any input newer than the snapshot the last run read it at (or no run yet). Rows: id, name, why (cron | after_inputs).
 */
function transforms_due(PDO $pdo, DateTimeImmutable $now): array
{
    $tz = (string) (one_value($pdo, 'SELECT timezone FROM dl_settings WHERE id = 1') ?: 'UTC');
    $st = $pdo->query("SELECT t.id, t.name, t.schedule, t.updated_at, t.input_dataset_ids,
                              (SELECT max(r.created_at) FROM transform_runs r WHERE r.transform_id = t.id) AS last_run_at,
                              (SELECT r.input_snapshots FROM transform_runs r WHERE r.transform_id = t.id AND r.status IN ('ok', 'no_op', 'failed') ORDER BY r.id DESC LIMIT 1) AS last_snapshots,
                              EXISTS (SELECT 1 FROM transform_runs r WHERE r.transform_id = t.id AND r.status IN ('queued', 'running')) AS in_flight
                         FROM transforms t JOIN datasets o ON o.id = t.output_dataset_id
                        WHERE t.enabled AND t.frozen_policy IS NOT NULL AND t.schedule IS NOT NULL AND o.status <> 'retired' ORDER BY t.id");
    $due = [];
    foreach ($st->fetchAll() as $t) {
        if ($t['in_flight']) {
            continue;
        }
        if ($t['schedule'] === 'after_inputs') {
            $ids = pg_int_array((string) $t['input_dataset_ids']);
            if ($ids === []) {
                continue;
            }
            $in = $pdo->prepare('SELECT key, last_snapshot_id FROM datasets WHERE id = ANY (CAST(:ids AS bigint[]))');
            $in->execute(['ids' => pg_array_literal($ids)]);
            $inputs = $in->fetchAll();
            $seen = json_decode((string) ($t['last_snapshots'] ?? 'null'), true);
            $ready = $inputs !== [] && count($inputs) === count($ids);
            $newer = $seen === null;
            foreach ($inputs as $i) {
                $ready = $ready && $i['last_snapshot_id'] !== null;
                $newer = $newer || ($i['last_snapshot_id'] !== null && (int) $i['last_snapshot_id'] > (int) ($seen[$i['key']] ?? 0));
            }
            if ($ready && $newer) {
                $due[] = ['id' => (int) $t['id'], 'name' => $t['name'], 'why' => 'after_inputs'];
            }
            continue;
        }
        $last = $t['last_run_at'] === null ? new DateTimeImmutable((string) $t['updated_at']) : new DateTimeImmutable((string) $t['last_run_at']);
        if ($t['last_run_at'] !== null && strtotime((string) $t['updated_at']) > strtotime((string) $t['last_run_at'])) {
            $last = new DateTimeImmutable((string) $t['updated_at']);        // saved after its last run: its next matching minute, not one already past
        }
        if (cron_due((string) $t['schedule'], $last, $now, $tz)) {
            $due[] = ['id' => (int) $t['id'], 'name' => $t['name'], 'why' => 'cron'];
        }
    }
    return $due;
}

/**
 * A run for the writer: the transform_runs row (queued, with the version and who asked) and the `transform` job (dataset = the output, ref = the transform). A run already
 * queued or running is that run (one at a time). Returns ['run' => id, 'job' => id, 'new' => bool]. $by null is the worker.
 */
function transform_enqueue_run(PDO $pdo, int $id, ?int $by): array
{
    $t = one_row($pdo, 'SELECT id, output_dataset_id, version, frozen_policy IS NOT NULL AS frozen FROM transforms WHERE id = :id FOR UPDATE', ['id' => $id]);
    if ($t === null) {
        throw new DomainException('Not found.');
    }
    if (!$t['frozen']) {
        throw new DomainException('This transform is not frozen: its SQL changed and its owner\'s level was not frozen again. Open Edit and save it.');
    }
    $live = one_row($pdo, "SELECT id, status FROM transform_runs WHERE transform_id = :id AND status IN ('queued', 'running') AND created_at > now() - interval '6 hours' ORDER BY id DESC LIMIT 1", ['id' => $id]);
    if ($live !== null) {
        $job = (int) (one_value($pdo, "SELECT id FROM lake_jobs WHERE kind = 'transform' AND ref_id = :id AND status IN ('queued', 'running') ORDER BY id DESC LIMIT 1", ['id' => $id]) ?? 0);
        return ['run' => (int) $live['id'], 'job' => $job, 'new' => false];
    }
    $st = $pdo->prepare("INSERT INTO transform_runs (transform_id, version, status, requested_by) VALUES (:t, :v, 'queued', :by) RETURNING id");
    $st->execute(['t' => $id, 'v' => (int) $t['version'], 'by' => $by]);
    $run = (int) $st->fetchColumn();
    $job = lake_enqueue($pdo, 'transform', (int) $t['output_dataset_id'], $id, ['transform_id' => $id, 'transform_run_id' => $run], 5);
    return ['run' => $run, 'job' => $job, 'new' => true];
}

/** The `transform` picker: a transform I may see, by name or its output. */
function transforms_for_pick(PDO $pdo, string $q, array $params, int $page = 1): array
{
    $where = $q === '' ? 'true' : '(t.name ILIKE :q OR o.key ILIKE :q OR o.title ILIKE :q)';
    $args = $q === '' ? [] : ['q' => picker_like($q)];
    return picker_page($pdo, 'SELECT count(*) FROM ' . TRANSFORM_FROM . " WHERE $where",
        "SELECT t.transform_id AS id, t.name AS label, o.key AS detail, CASE WHEN NOT t.enabled THEN 'Disabled' END AS badge, CASE WHEN NOT t.enabled THEN 'secondary' END AS color
           FROM " . TRANSFORM_FROM . " WHERE $where ORDER BY t.name, t.transform_id", $args, $page);
}

/**
 * The `transform_output` picker: the datasets the caller may make a transform write — curated and product ones at `write` or above (a data engineer's: transforms.manage), and
 * the caller's OWN sandbox datasets (sandbox.transform) — that no other transform already makes (one transform makes one dataset; `except` leaves the one being edited in).
 */
function transform_outputs_for_pick(PDO $pdo, string $q, array $params, int $page = 1): array
{
    $me = (int) current_member_id();
    $zones = [];
    if (has_right('transforms.manage')) {
        $zones[] = "(d.zone IN ('curated', 'product') AND d.my_level IN ('write', 'own'))";
    }
    if (has_right('sandbox.transform') || has_right('transforms.manage')) {
        $zones[] = "(d.zone = 'sandbox' AND d.owner_member_id = $me)";
    }
    if ($zones === []) {
        return ['rows' => [], 'total' => 0, 'page' => 1, 'page_size' => PICKER_PAGE, 'pages' => 1];
    }
    $where = "NOT d.is_stub AND d.status <> 'retired' AND (" . implode(' OR ', $zones) . ") AND (:q = '' OR d.key ILIKE :like OR d.title ILIKE :like)
              AND (NOT EXISTS (SELECT 1 FROM mcp_transforms x WHERE x.output_dataset_id = d.dataset_id)" . (($params['except'] ?? null) !== null ? ' OR d.dataset_id = :except' : '') . ')';
    $args = ['q' => $q, 'like' => like_pattern($q)] + (($params['except'] ?? null) !== null ? ['except' => (int) $params['except']] : []);
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_datasets d WHERE $where",
        "SELECT d.dataset_id, d.key, d.title, d.zone, d.certification FROM mcp_datasets d WHERE $where ORDER BY d.zone, d.title, d.dataset_id", $args, $page);
    $r['rows'] = array_map(static fn (array $d): array => ['id' => (int) $d['dataset_id'], 'label' => (string) $d['title'], 'detail' => (string) $d['key'],
        'badge' => (string) $d['zone'], 'color' => zone_color((string) $d['zone'])], $r['rows']);
    return $r;
}

/** The datasets the caller may read, as the form lists them beside the SQL — the key and its title, nothing more (the editor's catalog has the columns). */
function readable_dataset_keys(PDO $pdo, int $limit = 300): array
{
    $st = $pdo->prepare("SELECT dataset_id, key, zone, title FROM mcp_datasets WHERE NOT is_stub AND status <> 'retired' AND my_level IN ('read', 'read_unmasked', 'write', 'own')
                          ORDER BY zone = 'sandbox', key LIMIT " . max(1, min(500, $limit)));
    $st->execute();
    return $st->fetchAll();
}
