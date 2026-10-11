<?php
declare(strict_types=1);

/**
 * THE SQL EDITOR (slice 3 — query-service-editor.md, THE SECOND EXEMPLAR; query-service.md §7, §8). Every later screen that runs SQL for a
 * person (metrics in 7, the API's SQL in 9) copies run_sql(): ONE lake_sql() through the query service — the service compiles the person's
 * policy, masks, filters, runs, AUDITS (query_runs: PHP never writes it) — then the result or the refusal, the refusal's sentence the service's
 * own (never re-worded here), the masked columns and the truncation always said, the run's id always linked. Logged `query.run` with the run's
 * id, the first dataset, the rows and the status — never the SQL, never a value.
 *
 *   editor_catalog($pdo, $member)                         the datasets I may READ with their live columns, each column marked masked for me
 *   run_sql($pdo, $member, $sql, $asOf, $params, $opts)   one read: [status, columns, rows, truncated, masked_columns, datasets, ms,
 *                                                         query_run_id, refusal?, message, plan?, as_of]
 *   cancel_sql($member, $requestId)                       my running request interrupted (any instance)
 *   result_stats($columns, $rows)                         per column, on the SHOWN rows: nulls, distinct, min, max
 *   my_runs($pdo, $member, $limit)                        my last runs (mcp_query_runs — my own, the SQL's first line)
 *   recent_datasets($pdo, $member, $limit)                the datasets my last ok runs read (the home's Recent tile)
 *   editor_request_id()                                   a request id for one run (the page's, so Cancel can name it)
 */

const EDITOR_CATALOG_MAX = 300;
const RUN_STATUS = ['ok' => ['', 'Done'], 'refused' => ['bg-soft-warning text-warning', 'Refused'], 'timeout' => ['bg-soft-danger text-danger', 'Stopped'],
                    'error' => ['bg-soft-danger text-danger', 'Failed'], 'cancelled' => ['bg-soft-secondary text-secondary', 'Cancelled']];

/** A request id for one run: letters, digits and . _ : - (query-service §2), the member's id first so it reads in the audit. */
function editor_request_id(): string
{
    return 'sql-' . (int) current_member_id() . '-' . bin2hex(random_bytes(8));
}

/** A request id the browser sent, when it is one this member may use (its own prefix), else a new one. */
function editor_request_id_from(?string $given): string
{
    $given = (string) $given;
    $mine = 'sql-' . (int) current_member_id() . '-';
    return preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $given) && str_starts_with($given, $mine) ? $given : editor_request_id();
}

function editor_catalog(PDO $pdo, int $member): array
{
    $st = $pdo->prepare("SELECT dataset_id, key, zone, title, my_level FROM mcp_datasets
                          WHERE NOT is_stub AND status <> 'retired' AND my_level IN ('read', 'read_unmasked', 'write', 'own')
                          ORDER BY zone = 'sandbox', key LIMIT " . EDITOR_CATALOG_MAX);
    $st->execute();
    $ds = $st->fetchAll();
    if ($ds === []) {
        return [];
    }
    $ids = array_map(static fn (array $d): int => (int) $d['dataset_id'], $ds);
    $cols = [];
    $st = $pdo->prepare('SELECT dataset_id, name, logical_type, physical_type FROM mcp_dataset_columns
                          WHERE dataset_id = ANY (CAST(:ids AS bigint[])) AND removed_snapshot_id IS NULL AND NOT is_system ORDER BY dataset_id, ordinal');
    $st->execute(['ids' => pg_array_literal($ids)]);
    foreach ($st->fetchAll() as $c) {
        $cols[(int) $c['dataset_id']][] = $c;
    }
    $out = [];
    foreach ($ds as $d) {
        $masks = in_array($d['my_level'], ['read'], true)
            ? (json_decode((string) one_value($pdo, 'SELECT dl_masks_for(:d, :l)::text', ['d' => (int) $d['dataset_id'], 'l' => $d['my_level']]), true) ?: [])
            : [];
        $out[] = ['dataset_id' => (int) $d['dataset_id'], 'key' => $d['key'], 'zone' => $d['zone'], 'title' => $d['title'], 'level' => $d['my_level'],
                  'columns' => array_map(static fn (array $c): array => ['name' => $c['name'], 'logical_type' => $c['logical_type'] ?? $c['physical_type'],
                      'masked' => isset($masks[$c['name']])], $cols[(int) $d['dataset_id']] ?? [])];
    }
    return $out;
}

/**
 * One read for a person (or an agent's relayed call). $params: name → value (bound by the service as literals). $opts: request_id, dry_run,
 * saved_query (the row from mcp_saved_queries — its id rides into the audit), source.
 */
function run_sql(PDO $pdo, int $member, string $sql, int|string|null $asOf, array $params, array $opts = []): array
{
    $o = ['as_of' => $asOf, 'params' => $params, 'request_id' => $opts['request_id'] ?? null, 'dry_run' => !empty($opts['dry_run'])];
    if (isset($opts['source'])) {
        $o['source'] = $opts['source'];
    }
    if (isset($opts['saved_query'])) {
        $o['saved_query_id'] = (int) $opts['saved_query']['saved_query_id'];
    }
    $a = lake_sql($sql, $o);
    $status = (string) ($a['status'] ?? 'error');
    $datasets = array_values(array_filter((array) ($a['datasets'] ?? []), 'is_string'));
    $out = ['status' => $status, 'columns' => (array) ($a['columns'] ?? []), 'rows' => (array) ($a['rows'] ?? []), 'truncated' => (bool) ($a['truncated'] ?? false),
            'masked_columns' => array_values(array_filter((array) ($a['masked_columns'] ?? []), 'is_string')), 'datasets' => $datasets,
            'ms' => (int) ($a['ms'] ?? 0), 'query_run_id' => isset($a['query_run_id']) ? (int) $a['query_run_id'] : null,
            'message' => $a['message'] ?? null, 'plan' => $a['plan'] ?? null, 'as_of' => isset($a['as_of_snapshot']) ? (int) $a['as_of_snapshot'] : null,
            'dry_run' => !empty($opts['dry_run']), 'refusal' => null, 'request_id' => $o['request_id']];
    if ($status !== 'ok') {
        $out['refusal'] = ['rule' => (string) ($a['refusal_rule'] ?? 'engine'), 'message' => lake_refusal_message($a),
                           'dataset' => $a['dataset'] ?? null, 'request_access' => lake_request_access($a),
                           'retry' => !empty($a['retry'])];
    }
    if ($out['query_run_id'] !== null) {
        $first = $datasets === [] ? null : one_value($pdo, 'SELECT id FROM datasets WHERE key = :k', ['k' => $datasets[0]]);
        log_activity($pdo, 'query.run', 'query_run', $out['query_run_id'], ['dataset_id' => $first === null ? null : (int) $first, 'query_run_id' => $out['query_run_id'],
            'after' => ['source' => $o['source'] ?? 'screen', 'status' => $status, 'rows' => count($out['rows']), 'datasets' => count($datasets),
                        'truncated' => $out['truncated'], 'masked_columns' => count($out['masked_columns']), 'dry_run' => $out['dry_run'],
                        'saved_query_id' => $o['saved_query_id'] ?? null, 'refusal_rule' => $out['refusal']['rule'] ?? null]]);
    }
    return $out;
}

function cancel_sql(int $member, string $requestId): bool
{
    return lake_cancel($requestId)['cancelled'];
}

/** Quick stats under each column header — on the SHOWN rows (the spec's rule), computed here: nulls, distinct, min, max (numbers as numbers). */
function result_stats(array $columns, array $rows): array
{
    $out = [];
    foreach ($columns as $i => $c) {
        $vals = [];
        $nulls = 0;
        $numeric = true;
        foreach ($rows as $r) {
            $v = $r[$i] ?? null;
            if ($v === null) {
                $nulls++;
                continue;
            }
            $v = is_scalar($v) ? (string) $v : json_encode($v);
            $vals[] = $v;
            $numeric = $numeric && is_numeric($v);
        }
        $min = $max = null;
        if ($vals !== []) {
            $sorted = $vals;
            $numeric ? usort($sorted, static fn ($a, $b) => (float) $a <=> (float) $b) : sort($sorted, SORT_STRING);
            [$min, $max] = [$sorted[0], $sorted[count($sorted) - 1]];
        }
        $out[(string) $c['name']] = ['nulls' => $nulls, 'distinct' => count(array_unique($vals)), 'min' => $min === null ? null : mb_substr($min, 0, 40),
                                     'max' => $max === null ? null : mb_substr($max, 0, 40)];
    }
    return $out;
}

function my_runs(PDO $pdo, int $member, int $limit): array
{
    $st = $pdo->prepare('SELECT query_run_id, source, status, refusal_rule, datasets, rows_returned, truncated, ms, created_at, saved_query_id,
                                split_part(COALESCE(sql, \'\'), E\'\n\', 1) AS first_line
                           FROM mcp_query_runs WHERE caller_member_id = :m AND source IN (\'screen\', \'api\') ORDER BY query_run_id DESC LIMIT :n');
    $st->bindValue('m', $member, PDO::PARAM_INT);
    $st->bindValue('n', max(1, min(100, $limit)), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function recent_datasets(PDO $pdo, int $member, int $limit): array
{
    $st = $pdo->prepare("SELECT d.dataset_id, d.key, d.title, d.zone, d.certification, max(q.query_run_id) AS last_run
                           FROM mcp_query_runs q CROSS JOIN LATERAL unnest(q.dataset_ids) x(id) JOIN mcp_datasets d ON d.dataset_id = x.id AND NOT d.is_stub
                          WHERE q.caller_member_id = :m AND q.status = 'ok' AND q.query_run_id > (SELECT COALESCE(max(query_run_id), 0) - 5000 FROM mcp_query_runs)
                          GROUP BY 1, 2, 3, 4, 5 ORDER BY last_run DESC LIMIT :n");
    $st->bindValue('m', $member, PDO::PARAM_INT);
    $st->bindValue('n', max(1, min(20, $limit)), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** The SQL a link asks the editor to start with: ?dataset= → SELECT * FROM {key} LIMIT 100 (a dataset I may read); ?saved_query= → its SQL. */
function editor_prefill(PDO $pdo, string $dataset, string $savedQuery): array
{
    if ($savedQuery !== '' && ctype_digit($savedQuery)) {
        $q = one_row($pdo, 'SELECT saved_query_id, title, sql FROM mcp_saved_queries WHERE saved_query_id = :id', ['id' => (int) $savedQuery]);
        if ($q !== null) {
            return ['sql' => (string) $q['sql'], 'saved_query' => (int) $q['saved_query_id'], 'title' => (string) $q['title']];
        }
    }
    if ($dataset !== '') {
        $d = dataset_by_ref($pdo, $dataset);
        if ($d !== null && dl_level_rank(dataset_level($pdo, (int) $d['id'])) >= DL_LEVELS['read']) {
            return ['sql' => 'SELECT * FROM ' . $d['key'] . ' LIMIT 100', 'saved_query' => null, 'title' => null];
        }
    }
    return ['sql' => '', 'saved_query' => null, 'title' => null];
}
