<?php
declare(strict_types=1);

/**
 * The ONE file through which PHP reaches the lake's engine (query-service.md §8; sso-shell.md). PHP never opens DuckDB, the Parquet
 * files or the `ducklake` schema: a page's rows come from the QUERY SERVICE over its Unix sockets, and anything that writes the lake is
 * a job for the WRITER (`dl_job_enqueue`). Every answer is an array — a dead or busy service is an answer in words, never an exception.
 *
 *   lake_query($request, $timeoutS)   one request on the query service: the instances `<DL_QUERY_SOCKET>-N.sock` (N = 1…DL_QUERY_WORKERS)
 *                                     tried from a random one; `busy` → the next; all busy → again every 200 ms for 2 s; a 2 s connect and
 *                                     the request's own timeout + 5 s read. On any socket failure: status `error`, refusal_rule
 *                                     `engine_unavailable`, a sentence, retry true. (`lake_request()` is query-service §8's name for it.)
 *   lake_caller()                     who asks: the session's member, and for an agent's relayed run the person it acts for (K38) or the
 *                                     fail-closed ceiling, the run, the request id, the source — every request carries them
 *   lake_refusal_message($answer)     the person's sentence for a refusal (slice 3 adds the Request access links per rule)
 *   lake_enqueue($pdo, …)             a job for the writer (dl_job_enqueue, db/009) — its id
 *   lake_ping()                       ['writer' => ok|down, 'query' => ok|down, 'instances' => n answering]: the query service by `ping`
 *                                     on each instance; the writer by its connection (application_name datalake-writer in pg_stat_activity
 *                                     — it has no socket). The health endpoint and the admin's engine tile read it.
 *   lake_preview($key, $asOf)         slice 2: the `preview` kind — 100 rows of a dataset as the caller may read them (source `preview`)
 *   lake_sql($sql, $opts)             slice 3: the `sql` kind — the caller's SQL (params bound as literals by the service; dry_run = the plan and
 *                                     the datasets it reads, no rows); opts: source, as_of, params, request_id, dry_run, saved_query_id
 *   lake_saved_query($saved, $params) slice 3: the `saved_query` kind — a saved query the caller read under its own wall, run as `sql`
 *   lake_cancel($requestId)           slice 3: the `cancel` kind on EVERY instance (the one running it answers ok; another caller's id not_yours)
 *   lake_request_access($answer)      slice 3: the Request access link a refusal offers (the policy's rules), or null
 *   lake_timeout($source)             the request's own limit in seconds (dl_settings — the read waits 5 s longer)
 * Slices 7 and 9 add theirs here (lake_metric, lake_product_rows — query-service §8).
 */

const LAKE_PROTOCOL = 1;
const LAKE_CONNECT_TIMEOUT_S = 2.0;
const LAKE_BUSY_WINDOW_S = 2.0;
const LAKE_MAX_ANSWER_BYTES = 67108864;          // 64 MB: the result cap (50 MB) and the envelope
const LAKE_UNAVAILABLE = 'The lake\'s query service is not answering — try again in a moment.';

/** The query service's sockets: `<prefix>-1.sock` … `<prefix>-N.sock`. */
function lake_sockets(): array
{
    $prefix = (string) env('DL_QUERY_SOCKET', '');
    if ($prefix === '') {
        return [];
    }
    $n = max(1, min(16, (int) (env('DL_QUERY_WORKERS', '3') ?: 3)));
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $out[] = $prefix . '-' . $i . '.sock';
    }
    return $out;
}

/** Who asks — filled into every request (the service compiles the policy from it; a caller never sends a policy). */
function lake_caller(): array
{
    $agent = is_action_authed() && (current_member()['member_kind'] ?? '') === 'agent';
    return [
        'caller_member_id' => current_member_id(),
        'on_behalf_of'     => lake_on_behalf_of(),
        'ceiling'          => lake_ceiling(),
        'agent_run_id'     => current_agent_run_id(),
        'request_id'       => request_id(),
        'source'           => $agent ? 'agent' : 'screen',
    ];
}

/** One answer from one socket, or null when it could not be reached or answered nothing parseable. */
function lake_socket_call(string $path, string $line, int $readTimeoutS): ?array
{
    if (!file_exists($path)) {
        return null;
    }
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client('unix://' . $path, $errno, $errstr, LAKE_CONNECT_TIMEOUT_S);
    if ($fp === false) {
        return null;
    }
    stream_set_timeout($fp, $readTimeoutS);
    if (@fwrite($fp, $line) === false) {
        fclose($fp);
        return null;
    }
    $buf = '';
    while (!feof($fp) && !str_ends_with($buf, "\n") && strlen($buf) < LAKE_MAX_ANSWER_BYTES) {
        $chunk = fread($fp, 65536);
        if ($chunk === false) {
            break;
        }
        if ($chunk === '') {
            if (stream_get_meta_data($fp)['timed_out']) {
                break;
            }
            continue;
        }
        $buf .= $chunk;
    }
    $timedOut = stream_get_meta_data($fp)['timed_out'];
    fclose($fp);
    if ($timedOut || !str_ends_with($buf, "\n")) {
        return null;
    }
    $answer = json_decode($buf, true);
    return is_array($answer) ? $answer : null;
}

/**
 * One request to the query service. $request: the kind and its fields (query-service §2); the caller's fields are filled from
 * lake_caller() when absent. $timeoutS: the request's own limit (the screen's 30 s, an agent's 60 s, …); the read waits 5 s longer.
 */
function lake_query(array $request, int $timeoutS = 30): array
{
    $request = ['v' => LAKE_PROTOCOL] + $request + lake_caller();
    $line = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (strlen($line) > 1048576) {
        return ['status' => 'refused', 'refusal_rule' => 'too_long', 'message' => 'The request is over 1 MB.'];
    }
    $unavailable = ['status' => 'error', 'refusal_rule' => 'engine_unavailable', 'message' => LAKE_UNAVAILABLE, 'retry' => true];
    $sockets = lake_sockets();
    if ($sockets === []) {
        return $unavailable;
    }
    $start = random_int(0, count($sockets) - 1);
    $order = array_merge(array_slice($sockets, $start), array_slice($sockets, 0, $start));
    $deadline = microtime(true) + LAKE_BUSY_WINDOW_S;
    $reached = false;
    do {
        foreach ($order as $path) {
            $answer = lake_socket_call($path, $line, $timeoutS + 5);
            if ($answer === null) {
                continue;
            }
            $reached = true;
            if (($answer['status'] ?? '') === 'refused' && ($answer['refusal_rule'] ?? '') === 'busy') {
                continue;
            }
            return $answer;
        }
        if (!$reached) {
            return $unavailable;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    return ['status' => 'refused', 'refusal_rule' => 'busy', 'message' => 'The lake is busy answering other questions — try again in a moment.', 'retry' => true];
}

/** query-service.md §8's name for lake_query(). */
function lake_request(array $req, int $timeoutS = 30): array
{
    return lake_query($req, $timeoutS);
}

/** The sentence a person reads for an answer that is not `ok` (the service's own words when it gave any). */
function lake_refusal_message(array $answer): string
{
    $rule = (string) ($answer['refusal_rule'] ?? '');
    $words = [
        'engine_unavailable' => LAKE_UNAVAILABLE,
        'busy'               => 'The lake is busy answering other questions — try again in a moment.',
        'not_built'          => 'This is not built yet.',
        'too_long'           => 'The request is too long.',
        'peer'               => 'Only the web application may ask the query service.',
        'k38_pending'        => 'This is readable once the kernel says who asked (K38).',
        'rate'               => 'Too many queries this minute — try again in a moment.',
        'concurrency'        => 'Too many queries at once — try again in a moment.',
        'timeout'            => 'Stopped at the time limit — narrow it.',
        'cancelled'          => 'Cancelled.',
    ];
    $message = trim((string) ($answer['message'] ?? ''));
    if ($rule === 'not_built' && $message !== '') {
        return 'This is ' . $message . '.';
    }
    return $message !== '' ? $message : ($words[$rule] ?? 'The lake could not answer that.');
}

/** A job for the writer (dl_job_enqueue: one queued job of a kind per dataset and reference) — its id. */
function lake_enqueue(PDO $pdo, string $kind, ?int $datasetId, ?int $refId, array $params = [], int $priority = 5): int
{
    $st = $pdo->prepare('SELECT dl_job_enqueue(:k, :d, :r, CAST(:p AS jsonb), CAST(:pr AS smallint))');
    $st->execute(['k' => $kind, 'd' => $datasetId, 'r' => $refId, 'p' => json_encode((object) $params, JSON_UNESCAPED_SLASHES), 'pr' => max(0, min(9, $priority))]);
    return (int) $st->fetchColumn();
}

/** Is the engine there? Never fails: each service answers `ok` or `down`. */
function lake_ping(?PDO $pdo = null): array
{
    $up = 0;
    $versions = [];
    foreach (lake_sockets() as $path) {
        $a = lake_socket_call($path, json_encode(['v' => LAKE_PROTOCOL, 'kind' => 'ping']) . "\n", 3);
        if ($a !== null && ($a['status'] ?? '') === 'ok') {
            $up++;
            $versions[] = (string) ($a['version'] ?? '');
        }
    }
    $writer = 'down';
    try {
        $pdo ??= db();
        $writer = db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE datname = current_database() AND application_name = 'datalake-writer')") ? 'ok' : 'down';
    } catch (Throwable $e) {
        error_log('lake_ping: ' . $e->getMessage());
    }
    return ['writer' => $writer, 'query' => $up > 0 ? 'ok' : 'down', 'instances' => $up, 'version' => $versions[0] ?? null];
}

/**
 * 100 rows of a dataset as the caller may read them (query-service §7 `preview`; uploads-loads.md): the service compiles the caller's policy,
 * masks and filters, runs it and audits it — the answer carries `query_run_id`, `masked_columns` ("key.column"), `as_of_snapshot`.
 * $asOf: a snapshot id, an ISO time, or null for the latest.
 */
function lake_preview(string $key, int|string|null $asOf = null): array
{
    $caller = lake_caller();
    if ($caller['source'] === 'screen') {
        $caller['source'] = 'preview';
    }
    return lake_query(['kind' => 'preview', 'dataset' => $key, 'as_of' => $asOf] + $caller, 30);
}

/** The rules a refusal may answer with Request access (query-service §4): the policy's — never no_such_dataset (a hidden dataset never confirms itself). */
const LAKE_REQUESTABLE = ['discover_only', 'not_readable', 'not_shared'];

/** The Request access link a refusal offers: /access/requests/new?dataset=<key>&level=read for the policy's rules, else null. */
function lake_request_access(array $answer): ?string
{
    $key = (string) ($answer['dataset'] ?? '');
    if ($key === '' || !in_array((string) ($answer['refusal_rule'] ?? ''), LAKE_REQUESTABLE, true)) {
        return null;
    }
    return '/access/requests/new?' . http_build_query(['dataset' => $key, 'level' => 'read']);
}

/** The request's own limit in seconds for a source (dl_settings: the screen's 30 s, an agent's or the API's 60 s, an export's 300 s). */
function lake_timeout(string $source): int
{
    static $cache = [];
    $name = match (true) {
        in_array($source, ['screen', 'preview'], true) => 'query_timeout_screen_s',
        in_array($source, ['export', 'transform'], true) => 'query_timeout_export_s',
        default => 'query_timeout_agent_s',
    };
    if (!isset($cache[$name])) {
        try {
            $cache[$name] = (int) (one_value(db(), 'SELECT dl_setting_int(:n)', ['n' => $name]) ?? 30);
        } catch (Throwable $e) {
            $cache[$name] = 30;
        }
    }
    return max(1, $cache[$name]);
}

/**
 * The caller's SQL through the one door (query-service §7 `sql`; query-service-editor.md, THE SECOND EXEMPLAR). $opts: source (screen by
 * default; an agent's relayed call is `agent`), as_of, params (name → value, bound as literals by the service), request_id (the editor's
 * own, so Cancel can name it), dry_run (the plan and the datasets, no rows), saved_query_id. The answer is the service's (query_run_id always).
 */
function lake_sql(string $sql, array $opts = []): array
{
    $caller = lake_caller();
    $source = (string) ($opts['source'] ?? $caller['source']);
    $req = ['kind' => isset($opts['saved_query_id']) ? 'saved_query' : 'sql', 'sql' => $sql, 'source' => $source,
            'as_of' => $opts['as_of'] ?? null, 'params' => (object) ($opts['params'] ?? []), 'dry_run' => !empty($opts['dry_run'])];
    if (isset($opts['saved_query_id'])) {
        $req['saved_query_id'] = (int) $opts['saved_query_id'];
    }
    if (($opts['request_id'] ?? '') !== '') {
        $req['request_id'] = (string) $opts['request_id'];
    }
    return lake_query($req + $caller, lake_timeout($source));
}

/** A saved query the caller read under its own wall (mcp_saved_queries), run as `sql` with its id in the audit. */
function lake_saved_query(array $saved, array $params = [], array $opts = []): array
{
    return lake_sql((string) $saved['sql'], ['params' => $params, 'saved_query_id' => (int) $saved['saved_query_id']] + $opts);
}

/**
 * Cancel the caller's own running request (query-service §7 `cancel`): asked of EVERY instance (the client cannot know which one runs it);
 * the one running it answers ok. Returns ['cancelled' => bool, 'rule' => ok | not_yours | not_found | engine_unavailable].
 */
function lake_cancel(string $requestId): array
{
    $line = json_encode(['v' => LAKE_PROTOCOL, 'kind' => 'cancel', 'request_id' => $requestId] + array_intersect_key(lake_caller(), ['caller_member_id' => 1]),
        JSON_UNESCAPED_SLASHES) . "\n";
    $best = 'engine_unavailable';
    foreach (lake_sockets() as $path) {
        $a = lake_socket_call($path, $line, 3);
        if ($a === null) {
            continue;
        }
        if (($a['status'] ?? '') === 'ok') {
            return ['cancelled' => true, 'rule' => 'ok'];
        }
        $rule = (string) ($a['refusal_rule'] ?? 'not_found');
        $best = $rule === 'not_yours' || $best === 'engine_unavailable' ? $rule : $best;
    }
    return ['cancelled' => false, 'rule' => $best];
}

