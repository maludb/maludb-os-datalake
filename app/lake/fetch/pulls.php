<?php
declare(strict_types=1);

/**
 * THE PULL, end to end (sources-pulls.md "The pull"; Inventory's app/sources/pulls.php ported, inv_ → dl_): the due sources and the queued
 * manual pulls (the worker's pass), the `$source` array a connector receives (the ONE place a credential is opened), one pull — start, build,
 * pull with an emitter that LANDS each file once by its hash and makes its load exactly as slice 2's upload does, finish with the counts, the
 * failure ladder and the pause — and the weekly reconciliation of the `postgres` sources. PHP's worker pulls; the WRITER (the only process
 * that writes the lake) loads whatever lands, and is not touched. The worker has no member: it reads the base tables (mcp_* views answer a
 * member only).
 *
 * A pull's file → dl_landing_store() (the same function the upload's finish calls: hashed, de-duplicated, moved to landing/aa/bb/sha) → a
 * `loads` row (source_id, pull_id, blob_id, landing_sha256, page_cursor, mode, mapping, idempotency_key '<dataset>:<sha256>') → the writer's
 * `load` job. A page or file already loaded into that dataset is refused by the key and counted as nothing.
 */
require_once __DIR__ . '/Connector.php';
require_once __DIR__ . '/landing_ndjson.php';
require_once dirname(__DIR__, 2) . '/features/uploads/store.php';

/** The failures in a row that pause a source. */
const DL_PULL_PAUSE_AFTER = 5;
/** A pull still `running` after this long is abandoned (the worker died with it). */
const DL_PULL_STUCK_HOURS = 6;

/** The business's time zone (dl_settings.timezone). */
function dl_business_tz(PDO $pdo): string
{
    static $tz = null;
    return $tz ??= (string) (one_value($pdo, 'SELECT timezone FROM dl_settings WHERE id = 1') ?: 'UTC');
}

/** The test hooks (never in production): transport, sftp_runner, kernel_read — a proof's fixtures. The clock a pull uses is $GLOBALS['__dl_now'] (the worker sets it to its own). */
function dl_fetch_test(): array
{
    return !app_is_prod() && is_array($GLOBALS['__dl_fetch_test'] ?? null) ? $GLOBALS['__dl_fetch_test'] : [];
}

/** The scratch directory of one pull: under the data directory's landing area, so landing a file is a rename on one filesystem. */
function dl_pull_work_dir(int $pullId): string
{
    $dir = dl_storage_dir() . '/landing/.parts/pull-' . $pullId;
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('The pull\'s scratch directory could not be made.');
    }
    return $dir;
}

/** Remove a pull's scratch directory and what is left in it. */
function dl_pull_work_clean(string $dir): void
{
    foreach ((array) glob($dir . '/*') as $f) {
        if (is_file((string) $f)) {
            @unlink((string) $f);
        }
    }
    @rmdir($dir);
}

/**
 * The `$source` array of Connector.php from the row — THE ONE PLACE a credential is opened (dl_open()); DlCredentialError when the seal does not
 * open or the key is missing. $ctx: now (DateTimeImmutable), pull_id, work_dir, reload.
 */
function dl_source_for_connector(PDO $pdo, int $sourceId, array $ctx = []): array
{
    $row = one_row($pdo, 'SELECT * FROM sources WHERE id = :id', ['id' => $sourceId]) ?? throw new DlMisconfigured('No such source');
    $settings = json_decode((string) $row['settings'], true) ?: [];
    $credential = null;
    if ($row['credential_id'] !== null) {
        $sealed = one_value($pdo, "SELECT convert_from(ciphertext, 'UTF8') FROM source_credentials WHERE id = :c AND source_id = :s", ['c' => $row['credential_id'], 's' => $sourceId]);
        if ($sealed === null) {
            throw new DlCredentialError('credential_unreadable: missing');
        }
        $credential = dl_open((string) $sealed);
    }
    $dataset = null;
    if (isset($settings['target_dataset_id'])) {
        $dataset = one_row($pdo, 'SELECT id, key, row_count FROM datasets WHERE id = :d', ['d' => (int) $settings['target_dataset_id']]);
    }
    $state = json_decode((string) (one_value($pdo, "SELECT policy -> 'state' FROM source_pulls WHERE source_id = :s AND status IN ('ok', 'partial') AND kind <> 'probe' ORDER BY id DESC LIMIT 1", ['s' => $sourceId]) ?? 'null'), true);
    $test = dl_fetch_test();
    $fp = dl_settings_fingerprint($settings);
    if (!is_array($state) || ($state['_fp'] ?? null) !== $fp) {
        $state = [];                                   // a source whose settings changed starts again: an old ETag, window or watermark belongs to the old address
    }
    return [
        '_fp' => $fp, 'id' => (int) $row['id'], 'name' => (string) $row['name'], 'connector' => (string) $row['connector'], 'base_url' => $row['base_url'], 'settings' => $settings,
        'credential' => $credential, 'rate_per_second' => (float) $row['rate_per_second'], 'user_agent' => $row['user_agent'] !== null && trim((string) $row['user_agent']) !== '' ? trim((string) $row['user_agent']) : null,
        'timeout' => 20, 'cache_dir' => dl_http_default_cache_dir(), 'now' => $ctx['now'] ?? ($GLOBALS['__dl_now'] ?? new DateTimeImmutable('now')), 'tz' => dl_business_tz($pdo),
        'state' => is_array($state) ? $state : [], 'work_dir' => $ctx['work_dir'] ?? null, 'pull_id' => $ctx['pull_id'] ?? null, 'reload' => !empty($ctx['reload']), 'pdo' => $pdo,
        'dataset' => $dataset === null ? null : ['id' => (int) $dataset['id'], 'key' => (string) $dataset['key'], 'row_count' => $dataset['row_count'] === null ? null : (int) $dataset['row_count']],
        'transport' => $test['transport'] ?? null, 'sftp_runner' => $test['sftp_runner'] ?? null, 'kernel_read' => $test['kernel_read'] ?? null,
    ];
}

/** A short hash of a source's settings (without its target): the state a pull keeps is good only for the settings it was kept under. */
function dl_settings_fingerprint(array $settings): string
{
    unset($settings['target_dataset_id']);
    ksort($settings);
    return substr(sha1((string) json_encode($settings)), 0, 16);
}

/** What the engine's reader needs to read a landed file: [engine format, read_options] — from the named format, or from the bytes when `auto`. */
function dl_file_options(string $format, string $path): array
{
    $head = (string) @file_get_contents($path, false, null, 0, 8192);
    $fmt = strtolower($format);
    if ($fmt === 'auto') {
        $fmt = match (true) {
            str_starts_with($head, 'PAR1') => 'parquet',
            str_starts_with($head, "PK\x03\x04"), str_starts_with($head, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1") => 'xlsx',
            in_array(ltrim($head, " \t\r\n\xEF\xBB\xBF")[0] ?? '', ['{', '['], true) => 'json',
            default => 'csv',
        };
        if ($fmt === 'csv') {
            $first = (string) strtok(ltrim($head, "\xEF\xBB\xBF"), "\n");
            $best = ',';
            $max = -1;
            foreach ([',' => ',', ';' => ';', "\t" => "\t", '|' => '|'] as $d) {
                $n = substr_count($first, $d);
                if ($n > $max) {
                    [$best, $max] = [$d, $n];
                }
            }
            return ['csv', ['format' => 'csv', 'delim' => $best]];
        }
    }
    return match ($fmt) {
        'csv' => ['csv', ['format' => 'csv', 'delim' => ',']],
        'tsv' => ['csv', ['format' => 'csv', 'delim' => "\t"]],
        'xlsx' => ['workbook', ['format' => 'workbook']],
        'json', 'ndjson' => ['json', ['format' => 'json']],
        'parquet' => ['parquet', ['format' => 'parquet']],
        default => ['csv', ['format' => 'csv', 'delim' => ',']],
    };
}

/** The `loads.mapping` of a pulled file: the format's read options and, when the source names a saved mapping, its sheet, header row, renames and key. */
function dl_pull_mapping(string $format, string $landedPath, ?array $template): array
{
    [$fmt, $options] = dl_file_options($format, $landedPath);
    $t = $template ?? [];
    $headerRow = max(1, (int) ($t['header_row'] ?? 1));
    if ($fmt === 'workbook') {
        if (($t['sheet'] ?? null) !== null) {
            $options['sheet'] = (string) $t['sheet'];
        }
        if ($headerRow > 1) {
            $options['skip_rows'] = $headerRow - 1;
        }
    } elseif ($fmt === 'csv' && $headerRow > 1) {
        $options['skip'] = $headerRow - 1;
    }
    return ['format' => $fmt, 'sheet' => $t['sheet'] ?? null, 'header_row' => $headerRow, 'read_options' => $options, 'columns' => (object) ($t['columns'] ?? []),
            'dedupe_key' => array_values((array) ($t['dedupe_key'] ?? [])), 'target' => 'raw', 'from_source' => true];
}

/**
 * The emitter: land one file once, make its load, queue the writer's job. Returns ['landed' => bool, 'duplicate' => bool, 'load_id' => ?int, 'error' => ?string].
 * A file the key already knows is a duplicate (counted as nothing); a FAILED load of the same bytes is queued again.
 */
function dl_pull_emitter(PDO $pdo, array $source, int $pullId, ?int $by, array &$tally): Closure
{
    return function (array $file) use ($pdo, $source, $pullId, $by, &$tally): array {
        $datasetId = (int) ($source['dataset']['id'] ?? 0);
        if ($datasetId === 0) {
            return ['landed' => false, 'duplicate' => false, 'load_id' => null, 'error' => 'the source has no target dataset'];
        }
        try {
            $landed = dl_landing_store($pdo, (string) $file['path'], (string) ($file['name'] ?? 'file'));
        } catch (UploadRefused $e) {
            return ['landed' => false, 'duplicate' => false, 'load_id' => null, 'error' => mb_substr($e->getMessage(), 0, 200)];
        } catch (Throwable $e) {
            error_log('pull landing: ' . $e->getMessage());
            return ['landed' => false, 'duplicate' => false, 'load_id' => null, 'error' => 'the file could not be landed'];
        }
        $tally['bytes'] += (int) $landed['byte_size'];
        $key = $datasetId . ':' . $landed['sha256'];
        $existing = one_row($pdo, 'SELECT id, status FROM loads WHERE idempotency_key = :k', ['k' => $key]);
        if ($existing !== null) {
            if ($existing['status'] === 'failed') {
                $pdo->prepare("UPDATE loads SET status = 'queued', error = NULL WHERE id = :id AND status = 'failed'")->execute(['id' => (int) $existing['id']]);
                lake_enqueue($pdo, 'load', $datasetId, (int) $existing['id'], [], 4);
            }
            return ['landed' => false, 'duplicate' => true, 'load_id' => (int) $existing['id'], 'error' => null];
        }
        $template = null;
        $tid = $source['settings']['template_id'] ?? null;
        if ($tid !== null) {
            $m = one_value($pdo, 'SELECT mapping FROM import_templates WHERE id = :id', ['id' => (int) $tid]);
            $template = $m === null ? null : (json_decode((string) $m, true) ?: null);
        }
        $format = (string) ($file['format'] ?? 'auto');
        $mapping = dl_pull_mapping($format, dl_storage_dir() . '/' . $landed['path'], $template);
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("INSERT INTO loads (dataset_id, source_id, pull_id, blob_id, landing_sha256, page_cursor, template_id, mode, idempotency_key, mapping, requested_by)
                                 VALUES (:d, :s, :p, :b, :sha, :cur, :t, :m, :k, CAST(:map AS jsonb), :by) RETURNING id");
            $st->execute(['d' => $datasetId, 's' => $source['id'], 'p' => $pullId, 'b' => $landed['blob_id'], 'sha' => $landed['sha256'], 'cur' => $file['cursor'] ?? null,
                't' => $tid, 'm' => ($file['mode'] ?? 'append') === 'replace' ? 'replace' : 'append', 'k' => $key,
                'map' => json_encode($mapping, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'by' => $by]);
            $loadId = (int) $st->fetchColumn();
            lake_enqueue($pdo, 'load', $datasetId, $loadId, ['template_id' => $tid, 'format' => $format], 4);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['landed' => false, 'duplicate' => false, 'load_id' => null, 'error' => mb_substr(db_message($e, 'the load could not be made'), 0, 200)];
        }
        $tally['load_ids'][] = $loadId;
        return ['landed' => true, 'duplicate' => false, 'load_id' => $loadId, 'error' => null];
    };
}

/** "first error (+N more)" ≤ 200. */
function dl_pull_error_text(array $errors): ?string
{
    $errors = array_values(array_filter(array_map(static fn ($e): string => trim((string) $e), $errors), static fn (string $e): bool => $e !== ''));
    if ($errors === []) {
        return null;
    }
    return mb_substr($errors[0] . (count($errors) > 1 ? ' (+' . (count($errors) - 1) . ' more)' : ''), 0, 200);
}

/** The pull's policy (source_pulls.policy): the client's facts boiled down, the connector's state, the document's extra keys. Never a credential or a URL's query. */
function dl_pull_policy(array $stats, bool $queued, bool $reload): array
{
    $facts = (array) ($stats['policy'] ?? []);
    $hosts = (array) ($facts['robots'] ?? []);
    $states = array_map(static fn ($h) => is_array($h) ? (string) ($h['state'] ?? '') : (string) $h, $hosts);
    $policy = [];
    if (in_array('blocked', $states, true) || (int) ($facts['robots_skipped'] ?? 0) > 0) {
        $policy['robots'] = 'blocked';                   // the host turns crawlers away, or robots.txt forbids the path asked for
    } elseif ($states !== [] && array_diff($states, ['ok', 'none']) === []) {
        $policy['robots'] = 'ok';
    }
    foreach (['user_agent', 'rate_per_second', 'proxy', 'cached', 'blocked', 'robots_skipped'] as $k) {
        if (array_key_exists($k, $facts)) {
            $policy[$k] = $facts[$k];
        }
    }
    $policy['hosts'] = $hosts;
    $requests = (array) ($facts['requests'] ?? []);
    $notOk = array_values(array_filter($requests, static function ($r): bool {
        $s = (int) ($r['status'] ?? 0);
        return !empty($r['skipped']) || !empty($r['blocked']) || (!empty($r['reason']) && $r['reason'] !== 'redirect') || $s < 200 || $s >= 300;
    }));
    $policy['requests'] = array_slice($notOk, 0, 50);
    $policy['state'] = (object) (array) ($stats['state'] ?? []);
    if (!empty($stats['document']) && is_array($stats['document'])) {
        $doc = $stats['document'];
        $policy['document'] = strlen((string) json_encode($doc)) <= 8192 ? $doc : ['keys' => array_slice(array_keys($doc), 0, 40), 'truncated' => true];
    }
    foreach (['note', 'rows', 'files_new', 'files_taken', 'files_waiting', 'tool'] as $k) {
        if (isset($stats[$k])) {
            $policy[$k] = $stats[$k];
        }
    }
    if ($reload) {
        $policy['reload'] = true;
    }
    $policy['errors'] = array_slice(array_map(static fn ($e): string => mb_substr((string) $e, 0, 200), (array) ($stats['errors'] ?? [])), 0, 20);
    return $policy;
}

/**
 * One pull end to end. $queuedPullId = a `source_pulls` row source_pull_now (or the reconciliation) made with policy.queued: the start is
 * skipped and its policy replaced at the finish. Refuses (DomainException) a paused source, a source backing off (scheduled pulls only), a
 * source that pulls nothing and a pull already running. Returns the finished pull's counts:
 * ['pull_id', 'status', 'items_seen', 'items_landed', 'bytes', 'http_requests', 'error', 'paused', 'paused_reason', 'consecutive_failures', 'load_ids'].
 */
function dl_run_pull(PDO $pdo, int $sourceId, string $kind, ?int $by, ?int $queuedPullId = null): array
{
    // 1. start
    $pdo->beginTransaction();
    try {
        $s = one_row($pdo, 'SELECT * FROM sources WHERE id = :id FOR UPDATE', ['id' => $sourceId]) ?? throw new DomainException('No such source.');
        $connector = dl_fetch_connector((string) $s['connector']);
        if (!$connector->capabilities()['pulls']) {
            throw new DomainException('manual_only: ' . $s['name'] . ' is a named shape for people\'s uploads — there is nothing to pull.');
        }
        if (!$s['active']) {
            throw new DomainException($s['name'] . ' is switched off.');
        }
        if ($s['paused_at'] !== null) {
            throw new DomainException($s['name'] . ' is paused: ' . ($s['paused_reason'] ?? 'no reason given') . '.');
        }
        if ($kind === 'scheduled' && $s['backoff_until'] !== null && strtotime((string) $s['backoff_until']) > time()) {
            throw new DomainException($s['name'] . ' is backing off until ' . substr((string) $s['backoff_until'], 0, 16) . '.');
        }
        $pdo->prepare("UPDATE source_pulls SET status = 'failed', finished_at = now(), error = 'Abandoned — the worker stopped before it finished.'
                        WHERE source_id = :s AND status = 'running' AND started_at <= now() - interval '" . DL_PULL_STUCK_HOURS . " hours'")->execute(['s' => $sourceId]);
        if ($queuedPullId === null) {
            if (one_value($pdo, "SELECT 1 FROM source_pulls WHERE source_id = :s AND status = 'running'", ['s' => $sourceId]) !== null) {
                throw new DomainException('A pull of ' . $s['name'] . ' is already running.');
            }
            $queuedPullId = (int) one_value($pdo, 'INSERT INTO source_pulls (source_id, kind, started_by) VALUES (:s, :k, :b) RETURNING id', ['s' => $sourceId, 'k' => $kind, 'b' => $by]);
            $queued = false;
            $reload = false;
        } else {
            $row = one_row($pdo, "SELECT id, policy FROM source_pulls WHERE id = :p AND source_id = :s AND status = 'running'", ['p' => $queuedPullId, 's' => $sourceId])
                ?? throw new DomainException('That queued pull is gone.');
            $queued = true;
            $reload = !empty((json_decode((string) $row['policy'], true) ?: [])['reload']);
        }
        $pdo->prepare('UPDATE sources SET last_pull_id = :p WHERE id = :s')->execute(['p' => $queuedPullId, 's' => $sourceId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($queuedPullId !== null && $e instanceof DomainException) {
            $pdo->prepare("UPDATE source_pulls SET status = 'failed', finished_at = now(), error = :e WHERE id = :p AND status = 'running'")
                ->execute(['e' => mb_substr($e->getMessage(), 0, 200), 'p' => $queuedPullId]);       // a queued pull that was refused is finished, not left running
        }
        throw $e;
    }
    $pullId = $queuedPullId;
    $targetId = (int) ((json_decode((string) $s['settings'], true) ?: [])['target_dataset_id'] ?? 0) ?: null;      // for the log and the notice, even when the source cannot be built
    $workDir = dl_pull_work_dir($pullId);
    $tally = ['bytes' => 0, 'load_ids' => []];
    $emitErrors = [];
    $landed = 0;
    $stats = null;
    // 2. build
    try {
        $source = dl_source_for_connector($pdo, $sourceId, ['pull_id' => $pullId, 'work_dir' => $workDir, 'reload' => $reload]);
        if ($source['dataset'] === null) {
            throw new DlMisconfigured('the source has no target dataset');
        }
        if ($connector->capabilities()['needs_credential'] && $source['credential'] === null) {
            throw new DlCredentialError('credential: none is set');
        }
    } catch (DlCredentialError $e) {
        $m = $e->getMessage();
        $stats = ['status' => 'blocked', 'errors' => [str_starts_with($m, 'secrets_key_missing') ? 'the secrets key is missing — the credential cannot be opened' : (str_starts_with($m, 'credential:') ? $m : 'the credential cannot be opened — set it again')],
                  'items_seen' => 0, 'http_requests' => 0, 'bytes' => 0, 'policy' => [], 'pause' => 'credential: ' . (str_starts_with($m, 'secrets_key_missing') ? 'the secrets key (DL_SECRETS_KEY) is missing' : (str_starts_with($m, 'credential: none') ? 'none is set' : 'it cannot be opened — set it again'))];
    } catch (DlMisconfigured $e) {
        $stats = ['status' => 'failed', 'errors' => [mb_substr($e->getMessage(), 0, 200)], 'items_seen' => 0, 'http_requests' => 0, 'bytes' => 0, 'policy' => []];
    }
    // 3. pull
    if ($stats === null) {
        $emitter = dl_pull_emitter($pdo, $source, $pullId, $by, $tally);
        $emit = function (array $file) use ($emitter, &$emitErrors, &$landed): array {
            $r = $emitter($file);
            if ($r['error'] !== null) {
                $emitErrors[] = $r['error'];
            }
            $landed += $r['landed'] ? 1 : 0;
            return $r;
        };
        try {
            $stats = $connector->pull($source, $emit);
        } catch (DlMisconfigured $e) {
            $stats = ['status' => 'failed', 'errors' => [mb_substr($e->getMessage(), 0, 200)], 'items_seen' => 0, 'http_requests' => 0, 'bytes' => 0, 'policy' => []];
        } catch (Throwable $e) {
            error_log('pull ' . $pullId . ': ' . $e->getMessage());
            $stats = ['status' => $landed > 0 ? 'partial' : 'failed', 'errors' => [mb_substr($e->getMessage(), 0, 200)], 'items_seen' => 0, 'http_requests' => 0, 'bytes' => 0, 'policy' => []];
        }
    }
    dl_pull_work_clean($workDir);
    // 4. the status, downgraded by what could not be landed
    $status = in_array($stats['status'] ?? '', ['ok', 'partial', 'failed', 'blocked'], true) ? $stats['status'] : 'failed';
    if ($status === 'ok' && $emitErrors !== []) {
        $status = $landed > 0 ? 'partial' : 'failed';
    }
    $errors = array_merge((array) ($stats['errors'] ?? []), array_diff($emitErrors, (array) ($stats['errors'] ?? [])));
    $error = dl_pull_error_text($errors);
    $stats['errors'] = $errors;
    $policy = dl_pull_policy($stats, $queued, $reload);
    if (isset($source['_fp'])) {
        $policy['state'] = (object) ((array) $policy['state'] + ['_fp' => $source['_fp']]);
    }
    $bytes = (int) ($stats['bytes'] ?? 0) > 0 ? (int) $stats['bytes'] : $tally['bytes'];
    // 5. finish
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE source_pulls SET status = :st, finished_at = now(), error = :e, policy = CAST(:pol AS jsonb), listings_seen = :seen, listings_new = :new,
                          http_requests = :req, bytes = :b WHERE id = :p')
        ->execute(['st' => $status, 'e' => $error, 'pol' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'seen' => (int) ($stats['items_seen'] ?? 0), 'new' => $landed,
                   'req' => (int) ($stats['http_requests'] ?? 0), 'b' => $bytes, 'p' => $pullId]);
    $after = dl_pull_finish_source($pdo, $s, $sourceId, $status, $error, $stats, $policy, $targetId);
    if (in_array($status, ['ok', 'partial'], true) && ($source['dataset']['id'] ?? null) !== null) {
        $pdo->prepare("SELECT dl_lineage_edge(NULL, :s, :d, 'load')")->execute(['s' => $sourceId, 'd' => $source['dataset']['id']]);
    }
    log_activity($pdo, 'source.pull_done', 'source', $sourceId, ['source' => 'cron', 'actor_member_id' => null, 'dataset_id' => $targetId, 'after' => [
        'pull_id' => $pullId, 'status' => $status, 'items_seen' => (int) ($stats['items_seen'] ?? 0), 'items_landed' => $landed, 'bytes' => $bytes,
        'http_requests' => (int) ($stats['http_requests'] ?? 0), 'kind' => $kind, 'loads' => count($tally['load_ids']), 'error' => $error]]);
    $pdo->commit();
    if ($after['auto_paused']) {
        dl_pull_notify_paused($pdo, $after['source'], (string) $after['reason'], $targetId);
    }
    return ['pull_id' => $pullId, 'status' => $status, 'items_seen' => (int) ($stats['items_seen'] ?? 0), 'items_landed' => $landed, 'bytes' => $bytes,
            'http_requests' => (int) ($stats['http_requests'] ?? 0), 'error' => $error, 'paused' => $after['source']['paused_at'] !== null,
            'paused_reason' => $after['source']['paused_reason'], 'consecutive_failures' => (int) $after['source']['consecutive_failures'], 'load_ids' => $tally['load_ids']];
}

/**
 * The source row after a pull: a good pull (ok, partial) clears the ladder and records what robots.txt said; a pull the stats name a PAUSE for
 * (no_connection, a robots.txt or bot wall, a credential that cannot be opened) pauses at once and counts no failure — blocked means blocked;
 * a failed pull climbs the ladder (backoff 2^n minutes, at most a day) and the fifth failure in a row pauses. Returns ['source' => the row, 'auto_paused', 'reason'].
 */
function dl_pull_finish_source(PDO $pdo, array $before, int $sourceId, string $status, ?string $error, array $stats, array $policy, ?int $datasetId = null): array
{
    $auto = false;
    $reason = null;
    if (in_array($status, ['ok', 'partial'], true)) {
        $robots = $policy['robots'] ?? null;
        $pdo->prepare('UPDATE sources SET last_ok_at = now(), consecutive_failures = 0, backoff_until = NULL,
                              robots_state = COALESCE(CAST(:r AS text), robots_state), robots_checked_at = CASE WHEN CAST(:r2 AS text) IS NULL THEN robots_checked_at ELSE now() END WHERE id = :id')
            ->execute(['r' => $robots, 'r2' => $robots, 'id' => $sourceId]);
    } elseif (!empty($stats['pause'])) {
        $reason = mb_substr((string) $stats['pause'], 0, 300);
        $blockedByRobots = ($policy['robots'] ?? '') === 'blocked' || str_starts_with($reason, 'robots.txt');
        $pdo->prepare("UPDATE sources SET paused_at = now(), paused_reason = :r, robots_state = CASE WHEN :b THEN 'blocked' ELSE robots_state END,
                              robots_checked_at = CASE WHEN :b2 THEN now() ELSE robots_checked_at END WHERE id = :id")
            ->execute(['r' => $reason, 'b' => $blockedByRobots ? 't' : 'f', 'b2' => $blockedByRobots ? 't' : 'f', 'id' => $sourceId]);
        $auto = true;
    } else {
        $n = (int) $before['consecutive_failures'] + 1;
        $minutes = min(1440, 2 ** min($n, 11));
        $pdo->prepare('UPDATE sources SET consecutive_failures = :n, backoff_until = now() + make_interval(mins => :m) WHERE id = :id')->execute(['n' => $n, 'm' => $minutes, 'id' => $sourceId]);
        if ($n >= DL_PULL_PAUSE_AFTER) {
            $reason = DL_PULL_PAUSE_AFTER . ' failures in a row' . ($error !== null ? ': ' . $error : '');
            $pdo->prepare('UPDATE sources SET paused_at = now(), paused_reason = :r WHERE id = :id')->execute(['r' => mb_substr($reason, 0, 300), 'id' => $sourceId]);
            $auto = true;
        }
    }
    $row = one_row($pdo, 'SELECT * FROM sources WHERE id = :id', ['id' => $sourceId]);
    if ($auto) {
        log_activity($pdo, 'source.paused_auto', 'source', $sourceId, ['source' => 'cron', 'actor_member_id' => null, 'dataset_id' => $datasetId,
            'after' => ['consecutive_failures' => (int) $row['consecutive_failures'], 'reason' => $reason]]);
    }
    return ['source' => $row, 'auto_paused' => $auto, 'reason' => $reason];
}

/** Tell the source's creator and every holder of sources.manage that a source stopped itself — once a day for a source. */
function dl_pull_notify_paused(PDO $pdo, array $source, string $reason, ?int $datasetId): void
{
    try {
        $who = array_map('intval', $pdo->query("SELECT dl_members_with_right('sources.manage')")->fetchAll(PDO::FETCH_COLUMN));
        if ($source['created_by'] !== null) {
            $who[] = (int) $source['created_by'];
        }
        $day = gmdate('Y-m-d');
        foreach (array_unique($who) as $m) {
            $pdo->prepare("SELECT dl_notify(:m, 'load_failed', :t, :b, 'source', :id, NULL, NULL, :dd)")->execute([
                'm' => $m, 't' => mb_substr($source['name'] . ' was paused', 0, 200), 'b' => mb_substr('It stopped pulling: ' . $reason . '. Resume it from the source page once it is put right.', 0, 1000),
                'id' => (int) $source['id'], 'dd' => 'source_paused:' . (int) $source['id'] . ':' . $day . ':' . $m]);
        }
    } catch (Throwable $e) {
        error_log('pull notify: ' . $e->getMessage());
    }
}

/**
 * The weekly reconciliation of the postgres sources (divergence 3): on Sundays from 03:00 business time, once, each active postgres source's
 * table count is compared with its dataset's row count; when they differ a full reload is queued (a pull with policy.reload — the first page
 * `replace`, the rest `append`). Skipped while loads are queued or running (the lake's count lags). Logged `source.reconcile`.
 * Returns ['checked' => n, 'reloads' => n].
 */
function dl_reconcile_postgres(PDO $pdo, DateTimeImmutable $now, bool $force = false): array
{
    $tz = new DateTimeZone(dl_business_tz($pdo));
    $local = $now->setTimezone($tz);
    $out = ['checked' => 0, 'reloads' => 0, 'skipped' => 0];
    if (!$force && ((int) $local->format('w') !== 0 || (int) $local->format('G') < 3)) {
        return $out + ['due' => false];
    }
    $since = $local->setTime(0, 0)->format(DATE_ATOM);
    $rows = $pdo->query("SELECT id FROM sources WHERE connector = 'postgres' AND active AND paused_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $id) {
        $id = (int) $id;
        if (!$force && db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM activity_log WHERE action = 'source.reconcile' AND entity_id = :id AND occurred_at >= CAST(:s AS timestamptz))", ['id' => $id, 's' => $since])) {
            $out['skipped']++;
            continue;
        }
        try {
            $source = dl_source_for_connector($pdo, $id, ['now' => $now]);
            $ds = $source['dataset'];
            if ($ds === null || $ds['row_count'] === null
                || db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM loads WHERE dataset_id = :d AND status IN ('queued', 'running')) OR EXISTS (SELECT 1 FROM source_pulls WHERE source_id = :s AND status = 'running')", ['d' => $ds['id'], 's' => $id])) {
                $out['skipped']++;
                continue;
            }
            $count = DlConnectorPostgres::count($source);
        } catch (Throwable $e) {
            error_log('reconcile ' . $id . ': ' . $e->getMessage());
            $out['skipped']++;
            continue;
        }
        $out['checked']++;
        $reload = $count !== (int) $ds['row_count'];
        if ($reload) {
            $pdo->prepare("INSERT INTO source_pulls (source_id, kind, policy) VALUES (:s, 'scheduled', '{\"queued\": true, \"reload\": true}'::jsonb)")->execute(['s' => $id]);
            $out['reloads']++;
        }
        log_activity($pdo, 'source.reconcile', 'source', $id, ['source' => 'cron', 'actor_member_id' => null, 'dataset_id' => $ds['id'],
            'after' => ['source_count' => $count, 'lake_count' => (int) $ds['row_count'], 'reload' => $reload]]);
    }
    return $out;
}

/**
 * The worker's source pass (bin/worker.php `schedules`, this slice's part): the weekly reconciliation first (it may queue a reload), then the
 * queued manual pulls, then the sources due — each pull its own try, within DL_WORKER_PULLS_BUDGET_SECONDS (default 300).
 * Returns ['reconciled' => …, 'queued' => n, 'due' => n, 'pulls' => n, 'ok'|'partial'|'failed'|'blocked' => n, 'errors' => n].
 */
function sources_run_due_pulls(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $report = ['reconciled' => null, 'queued' => 0, 'due' => 0, 'pulls' => 0, 'ok' => 0, 'partial' => 0, 'failed' => 0, 'blocked' => 0, 'errors' => 0, 'landed' => 0];
    $budget = max(1, (int) (env('DL_WORKER_PULLS_BUDGET_SECONDS') ?: 300));
    $started = microtime(true);
    $tally = static function (array $r) use (&$report): void {
        $report['pulls']++;
        $st = (string) ($r['status'] ?? 'failed');
        if (isset($report[$st])) {
            $report[$st]++;
        }
        $report['landed'] += (int) ($r['items_landed'] ?? 0);
    };
    try {
        $report['reconciled'] = dl_reconcile_postgres($pdo, $now);
    } catch (Throwable $e) {
        $report['errors']++;
        error_log('reconcile: ' . $e->getMessage());
    }
    $prevNow = $GLOBALS['__dl_now'] ?? null;
    $GLOBALS['__dl_now'] = $now;
    $queued = $pdo->query("SELECT id, source_id FROM source_pulls WHERE status = 'running' AND policy ->> 'queued' = 'true' AND started_at > now() - interval '" . DL_PULL_STUCK_HOURS . " hours' ORDER BY started_at")->fetchAll();
    foreach ($queued as $q) {
        if (microtime(true) - $started > $budget) {
            break;
        }
        try {
            $tally(dl_run_pull($pdo, (int) $q['source_id'], 'manual', null, (int) $q['id']));
            $report['queued']++;
        } catch (Throwable $e) {
            $report['errors']++;
            error_log('queued pull ' . $q['id'] . ': ' . $e->getMessage());
        }
    }
    $due = sources_due($pdo, $now);
    $report['due'] = count($due);
    foreach (array_slice($due, 0, max(1, $limit)) as $d) {
        if (microtime(true) - $started > $budget) {
            break;
        }
        try {
            $tally(dl_run_pull($pdo, (int) $d['id'], 'scheduled', null));
        } catch (Throwable $e) {
            $report['errors']++;                         // a refusal at the start (paused, running) is the pass's error line, never an exception out of it
            error_log('pull of source ' . $d['id'] . ': ' . $e->getMessage());
        }
    }
    $GLOBALS['__dl_now'] = $prevNow;
    return $report;
}
