<?php
declare(strict_types=1);

/**
 * The Data Lake worker — one pass a minute from datalake-worker.timer (design §14). It never touches the lake itself: the WRITER
 * (services/lake/, one process, the lake_jobs queue) is the only thing that writes Parquet or the ducklake schema, and the QUERY SERVICE
 * (services/query/) the only thing that reads them. The worker is the clock: it turns what is due into writer jobs (source pulls,
 * scheduled transforms and checks, the nightly maintenance and retention), and keeps the application's own tables — abandoned upload
 * sessions, the catalog's search index catch-up, freshness, usage roll-ups, grant and request expiry, the outbox, dispatches to agents,
 * export files past their time, key-usage pruning. Idempotent; advisory-locked so two runs never overlap; each pass its own try (one
 * failing never stops the next); the report is printed as one JSON line, logged once as `worker.pass` (counts and sentences, never a
 * row value, SQL, a token or a key) and kept as a worker_passes row when the table exists (db/006+).
 *   php bin/worker.php [--passes=a,b] [--limit=200]
 * With --passes the named passes run whether or not they are due; without it every pass runs. DL_WORKER_NOW (ISO 8601) replaces the clock
 * a pass selects by; honoured only when APP_ENV is not prod. Exits 1 when a pass failed.
 *
 * PHASE 0: every pass but `key_usage_prune` is a STUB that changes nothing and answers 0 — the slice whose data it touches fills it in
 * (design §10): `search_catch_up` slice 1's (BUILT); `upload_sessions` slice 2's (BUILT); `usage_rollup`, `exports` slice 3's; `expiry` slice 4's;
 * `freshness` slice 5's; `schedules` slices 5–7's (checks, source pulls and transforms BUILT — slices 5, 6 and 7); `retention`, `maintenance` slice 8's;
 * `outbox`, `dispatches`, `engine_health` slice 10's. REAL from the kit: `key_usage_prune` (dl_prune_key_usage, db/005).
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';

/** The passes, in the order they run. A new pass is a name here and a function worker_pass_<name>(PDO, int $limit, DateTimeImmutable $now): array. */
const WORKER_PASSES = ['upload_sessions', 'search_catch_up', 'schedules', 'freshness', 'expiry', 'usage_rollup', 'retention', 'maintenance', 'exports',
    'outbox', 'dispatches', 'engine_health', 'key_usage_prune'];

$only = [];
$limit = 200;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--passes=')) {
        $only = array_values(array_filter(explode(',', substr($a, 9))));
        if (array_diff($only, WORKER_PASSES) !== []) {
            fwrite(STDERR, 'Unknown pass: ' . implode(', ', array_diff($only, WORKER_PASSES)) . '. Passes: ' . implode(', ', WORKER_PASSES) . ".\n");
            exit(2);
        }
    } elseif (str_starts_with($a, '--limit=')) {
        $limit = max(1, min(1000, (int) substr($a, 8)));
    } else {
        fwrite(STDERR, "Usage: php bin/worker.php [--passes=a,b] [--limit=N]\n");
        exit(2);
    }
}
$now = new DateTimeImmutable();
if (($fake = getenv('DL_WORKER_NOW')) !== false && $fake !== '' && app_is_prod() === false) {
    $now = new DateTimeImmutable($fake);
}
$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('datalake_worker'))")->fetchColumn()) {
    echo json_encode(['skipped' => 'another pass holds the lock']) . "\n";
    exit(0);
}
$GLOBALS['__public_door'] = 'cron';                 // every row a pass writes is source cron, actor none
$GLOBALS['__worker_only'] = $only;                   // a pass named by --passes runs whether or not it is due
$started = microtime(true);
$report = ['at' => $now->format(DATE_ATOM), 'ran' => [], 'errors' => []];
foreach ($only === [] ? WORKER_PASSES : $only as $pass) {
    try {
        $report['ran'][$pass] = ('worker_pass_' . $pass)($pdo, $limit, $now);
    } catch (Throwable $e) {
        error_log('worker ' . $pass . ': ' . $e->getMessage());
        $report['errors'][] = $pass . ': ' . mb_substr($e->getMessage(), 0, 200);
    }
}
$report['seconds'] = round(microtime(true) - $started, 2);
log_activity($pdo, 'worker.pass', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => $report]);
try {
    if ($pdo->query("SELECT to_regclass('public.worker_passes') IS NOT NULL")->fetchColumn()) {
        $pdo->prepare('INSERT INTO worker_passes (started_at, finished_at, steps, error) VALUES (:s, now(), :steps, :err)')
            ->execute(['s' => date(DATE_ATOM, (int) $started), 'steps' => json_encode($report['ran'], JSON_UNESCAPED_SLASHES),
                       'err' => $report['errors'] === [] ? null : implode('; ', $report['errors'])]);
    }
} catch (Throwable $e) {
    error_log('worker_passes: ' . $e->getMessage());
}
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
$pdo->query("SELECT pg_advisory_unlock(hashtext('datalake_worker'))");
exit($report['errors'] === [] ? 0 : 1);

// ---- the passes (stubs until their slice) ------------------------------------------------------------------------------------------

/** SLICE 2 — upload sessions past upload_session_hours: their parts deleted, the session expired (a finished upload's blob is the writer's). */
/**
 * upload_sessions (slice 2 — uploads-loads.md divergence 2): sessions still open past expires_at are expired and their parts removed; the
 * sniff's sample rows — the uploader's own file shown back to them — are dropped from every session whose mapping is decided (a load made from
 * it), discarded or expired: `sniff.sample` and each column's five-value `sample` go; the proposal's names, types and kinds stay.
 */
function worker_pass_upload_sessions(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/uploads/store.php';
    $expired = 0;
    $st = $pdo->prepare("SELECT id FROM upload_sessions WHERE status IN ('open', 'assembling') AND expires_at < CAST(:now AS timestamptz) ORDER BY id LIMIT " . $limit);
    $st->execute(['now' => $now->format(DATE_ATOM)]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare("UPDATE upload_sessions SET status = 'expired' WHERE id = :id AND status IN ('open', 'assembling')")->execute(['id' => (int) $id]);
        try {
            store_drop_parts((int) $id);
        } catch (Throwable $e) {
            error_log('worker upload_sessions: ' . $e->getMessage());
        }
        $expired++;
    }
    $nulled = 0;
    $st = $pdo->query("SELECT u.id, u.sniff FROM upload_sessions u
                        WHERE u.sniff IS NOT NULL AND (jsonb_exists(u.sniff, 'sample') OR jsonb_path_exists(u.sniff, '$.columns[*].sample'))
                          AND (u.status IN ('expired', 'failed') OR EXISTS (SELECT 1 FROM loads l WHERE l.upload_session_id = u.id))
                        ORDER BY u.id LIMIT " . $limit);
    foreach ($st->fetchAll() as $r) {
        $s = json_decode((string) $r['sniff'], true);
        if (!is_array($s)) {
            continue;
        }
        unset($s['sample']);
        $strip = static fn (array $cols): array => array_map(static function (array $c): array { unset($c['sample']); return $c; }, $cols);
        $s['columns'] = $strip((array) ($s['columns'] ?? []));
        foreach ((array) ($s['sheets'] ?? []) as $i => $sh) {
            $s['sheets'][$i]['columns'] = $strip((array) ($sh['columns'] ?? []));
        }
        $s['sample_cleared_at'] = $now->format(DATE_ATOM);
        $pdo->prepare('UPDATE upload_sessions SET sniff = CAST(:s AS jsonb) WHERE id = :id')->execute(['s' => json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'id' => (int) $r['id']]);
        $nulled++;
    }
    return ['expired' => $expired, 'samples_cleared' => $nulled];
}
/**
 * SLICE 1 — the search index caught up (catalog.md): the triggers index a person's change at once; this pass catches what they cannot see —
 * a writer's commit (data_updated_at moves as datalake_lake), usage moving the ranking, a row missed in a failed transaction. Every dataset
 * whose metadata_updated_at or data_updated_at passed its index row's updated_at (or that has none; or retired with one left) is re-indexed
 * by dl_index_dataset(), and every glossary term whose updated_at did by dl_index_glossary_term() — at most $limit of each a pass, oldest
 * change first. (Saved queries and metrics are indexed by their triggers; their slices add them here if they need a catch-up.)
 */
function worker_pass_search_catch_up(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $st = $pdo->prepare("SELECT d.id FROM datasets d LEFT JOIN search_index s ON s.entity_kind = 'dataset' AND s.entity_id = d.id
                          WHERE (d.status <> 'retired' AND (s.entity_id IS NULL OR GREATEST(d.metadata_updated_at, COALESCE(d.data_updated_at, d.metadata_updated_at)) > s.updated_at))
                             OR (d.status = 'retired' AND s.entity_id IS NOT NULL)
                          ORDER BY GREATEST(d.metadata_updated_at, COALESCE(d.data_updated_at, d.metadata_updated_at)) LIMIT :n");
    $st->execute(['n' => $limit]);
    $datasets = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    foreach ($datasets as $id) {
        $pdo->prepare('SELECT dl_index_dataset(:id)')->execute(['id' => $id]);
    }
    $st = $pdo->prepare("SELECT g.id FROM glossary_terms g LEFT JOIN search_index s ON s.entity_kind = 'glossary_term' AND s.entity_id = g.id
                          WHERE (g.status <> 'deprecated' AND (s.entity_id IS NULL OR g.updated_at > s.updated_at)) OR (g.status = 'deprecated' AND s.entity_id IS NOT NULL)
                          ORDER BY g.updated_at LIMIT :n");
    $st->execute(['n' => $limit]);
    $terms = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    foreach ($terms as $id) {
        $pdo->prepare('SELECT dl_index_glossary_term(:id)')->execute(['id' => $id]);
    }
    return ['indexed' => count($datasets), 'terms' => count($terms)];
}
/**
 * schedules — SLICE 5's and SLICE 6's parts (contracts-quality.md, sources-pulls.md; lake-engine §7). Slice 5: a dataset's checks that run every hour or
 * every day are queued as ONE `checks` job (params.when) once per window — the hour, the day — never twice in a window (a job of that `when` for the
 * dataset made since the window began). Slice 6: the SOURCES — the postgres sources' weekly reconciliation (Sundays from 03:00 business time), the
 * pulls queued by a person's Pull now, then the sources due, within DL_WORKER_PULLS_BUDGET_SECONDS (default 300): the pulls are PHP's (a pull lands
 * each file once and queues the writer's `load`); the writer is never asked to fetch. Slice 7: the TRANSFORMS due — a cron schedule with a matching minute since the last run (business time
 * zone, once per due minute) or `after_inputs` with an input newer than the snapshot the last run read — each a transform_runs row (queued) and the writer's `transform` job; a run
 * still queued or running after six hours is abandoned (failed) first. `DL_WORKER_NOW` is the clock outside production.
 */
function worker_pass_schedules(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $st = $pdo->prepare("SELECT DISTINCT k.dataset_id, k.run_when FROM checks k JOIN datasets d ON d.id = k.dataset_id
                          WHERE k.enabled AND k.run_when IN ('hourly', 'daily') AND d.status IN ('active', 'deprecated')
                            AND NOT EXISTS (SELECT 1 FROM lake_jobs j WHERE j.kind = 'checks' AND j.dataset_id = k.dataset_id AND j.params ->> 'when' = k.run_when
                                               AND j.created_at >= date_trunc(CASE k.run_when WHEN 'hourly' THEN 'hour' ELSE 'day' END, now()))
                          ORDER BY k.dataset_id LIMIT :n");
    $st->bindValue('n', $limit, PDO::PARAM_INT);
    $st->execute();
    $queued = 0;
    foreach ($st->fetchAll() as $r) {
        lake_enqueue($pdo, 'checks', (int) $r['dataset_id'], null, ['when' => (string) $r['run_when']], 6);
        $queued++;
    }
    require_once dirname(__DIR__) . '/app/lake/fetch/pulls.php';
    require_once dirname(__DIR__) . '/app/features/sources/queries.php';
    return ['checks_queued' => $queued, 'sources' => sources_run_due_pulls($pdo, $limit, $now), 'transforms' => worker_schedule_transforms($pdo, $limit, $now)];
}
/** The transforms part of the schedules pass (transforms-products-lineage.md divergence 3): abandon the stale, queue the due. Returns {queued, abandoned, which[]}. */
function worker_schedule_transforms(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/transforms/queries.php';
    $abandoned = $pdo->exec("UPDATE transform_runs SET status = 'failed', error = 'abandoned — the writer did not finish it', finished_at = now()
                              WHERE status IN ('queued', 'running') AND created_at < now() - interval '6 hours'");
    $which = [];
    foreach (transforms_due($pdo, $now) as $t) {
        if (count($which) >= $limit) {
            break;
        }
        try {
            $pdo->beginTransaction();
            $q = transform_enqueue_run($pdo, (int) $t['id'], null);
            $d = (int) one_value($pdo, 'SELECT output_dataset_id FROM transforms WHERE id = :id', ['id' => (int) $t['id']]);
            log_activity($pdo, 'transform.run', 'transform', (int) $t['id'], ['dataset_id' => $d, 'after' => ['transform_run_id' => $q['run'], 'job_id' => $q['job'], 'new' => $q['new'], 'why' => $t['why']]]);
            $pdo->commit();
            $which[] = $t['name'] . ':' . $t['why'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('worker transforms ' . $t['name'] . ': ' . $e->getMessage());
        }
    }
    return ['queued' => count($which), 'abandoned' => (int) $abandoned, 'which' => $which];
}
/**
 * freshness — SLICE 5 (contracts-quality.md divergence 3; lake-engine §4, §7): hourly. Every dataset with a freshness expectation
 * (freshness_warn_after) gets ONE `freshness` check the first time — source `contract`, never blocking, severity `error` when an error interval is
 * set, else `warn`, run on every load too (fresh data passes it at once) — and a result every hour through dl_check_result_record(): the hours
 * since "data updated" against the warning (passed when no longer due — dl_freshness_due()). The database opens the incident, resolves it on
 * a pass, rechecks certification.
 */
function worker_pass_freshness(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    if (!in_array('freshness', $GLOBALS['__worker_only'] ?? [], true)
        && db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM activity_log WHERE action = 'worker.freshness' AND created_at >= date_trunc('hour', now()))")) {
        return ['due' => false];
    }
    $made = $failed = $passed = 0;
    $st = $pdo->prepare("SELECT d.id, d.key, extract(epoch FROM d.freshness_warn_after) / 3600.0 AS warn_h, d.freshness_error_after IS NOT NULL AS has_error,
                                CASE WHEN d.data_updated_at IS NULL THEN NULL ELSE extract(epoch FROM now() - d.data_updated_at) / 3600.0 END AS age_h,
                                EXISTS (SELECT 1 FROM dl_freshness_due() f WHERE f.dataset_id = d.id) AS due
                           FROM datasets d WHERE d.freshness_warn_after IS NOT NULL AND d.status IN ('active', 'deprecated') ORDER BY d.id LIMIT :n");
    $st->bindValue('n', $limit, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll() as $d) {
        $severity = $d['has_error'] ? 'error' : 'warn';
        $check = one_row($pdo, "SELECT id, severity FROM checks WHERE dataset_id = :d AND kind = 'freshness' AND source = 'contract' ORDER BY id LIMIT 1", ['d' => (int) $d['id']]);
        if ($check === null) {
            $id = (int) one_value($pdo, "INSERT INTO checks (dataset_id, kind, params, dimension, severity, blocking, run_when, description, source)
                                          VALUES (:d, 'freshness', '{}'::jsonb, 'timeliness', :s, false, 'on_load', 'Kept by the lake: its freshness expectation.', 'contract') RETURNING id",
                ['d' => (int) $d['id'], 's' => $severity]);
            log_activity($pdo, 'check.create', 'check', $id, ['source' => 'cron', 'actor_member_id' => null, 'dataset_id' => (int) $d['id'],
                'after' => ['kind' => 'freshness', 'severity' => $severity, 'source' => 'contract', 'by' => 'the freshness pass']]);
            $made++;
            $check = ['id' => $id, 'severity' => $severity];
        } elseif ($check['severity'] !== $severity) {
            $pdo->prepare('UPDATE checks SET severity = :s WHERE id = :id')->execute(['s' => $severity, 'id' => (int) $check['id']]);
        }
        $ok = !$d['due'];
        $pdo->prepare("SELECT dl_check_result_record(:c, NULL, NULL, :v, :t, :p, 0, '[]'::jsonb, 0, NULL)")->execute(['c' => (int) $check['id'],
            'v' => $d['age_h'] === null ? null : round((float) $d['age_h'], 3), 't' => (string) round((float) $d['warn_h'], 3), 'p' => $ok ? 't' : 'f']);
        $ok ? $passed++ : $failed++;
    }
    log_activity($pdo, 'worker.freshness', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['checks_made' => $made, 'late' => $failed, 'fresh' => $passed]]);
    return ['checks_made' => $made, 'late' => $failed, 'fresh' => $passed];
}
/** SLICE 4 — grants and access requests past their expiry closed, logged grant.expire / access.expire, the holder notified. */
/**
 * SLICE 4 — expiry (lake-engine §7; access-policies.md): grants past `expires_at` → revoked at their expiry (the trail by trigger; a person's or an
 * agent's grant tells its grantee); pending requests past `wanted_until` or older than 30 days → expired (access.expire, the requester told);
 * grants ending within seven days → ONE notice to the grantee (a person or an agent) and the dataset's owner, never twice (the pass dedupes per
 * grant and person: notifications of kind grant_expiring for the grant).
 */
function worker_pass_expiry(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $at = $now->format(DATE_ATOM);
    $out = ['revoked' => 0, 'expired_requests' => 0, 'announced' => 0];
    $st = $pdo->prepare("UPDATE grants g SET revoked_at = g.expires_at
                          WHERE g.id IN (SELECT id FROM grants WHERE revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at <= CAST(:now AS timestamptz)
                                          ORDER BY expires_at LIMIT $limit FOR UPDATE SKIP LOCKED)
                      RETURNING g.id, g.principal_kind, g.principal_id, g.dataset_id, g.zone, g.tag_id, g.level,
                                (SELECT d.key FROM datasets d WHERE d.id = g.dataset_id) AS dataset_key");
    $st->execute(['now' => $at]);
    foreach ($st->fetchAll() as $g) {
        $out['revoked']++;
        $target = $g['dataset_key'] ?? ($g['zone'] !== null ? 'the ' . $g['zone'] . ' zone' : 'a tag');
        if (in_array($g['principal_kind'], ['member', 'agent'], true)) {
            $pdo->prepare("SELECT dl_notify(:m, 'access_decided', :t, :b, 'grant', :g, NULL, NULL, :dd)")
                ->execute(['m' => (int) $g['principal_id'], 't' => 'Your access to ' . $target . ' ended', 'b' => 'The grant (' . $g['level'] . ') reached its end date.',
                           'g' => (int) $g['id'], 'dd' => 'grant_ended:' . (int) $g['id']]);
        }
        log_activity($pdo, 'grant.expire', 'grant', (int) $g['id'], ['source' => 'cron', 'actor_member_id' => null] + ($g['dataset_id'] === null ? [] : ['dataset_id' => (int) $g['dataset_id']])
            + ['after' => ['principal_kind' => $g['principal_kind'], 'principal_id' => $g['principal_id'] === null ? null : (int) $g['principal_id'], 'level' => $g['level'], 'revoked' => true]]);
    }
    $st = $pdo->prepare('SELECT r.*, d.key FROM dl_access_request_expire(CAST(:now AS timestamptz), :lim) r JOIN datasets d ON d.id = r.dataset_id');
    $st->execute(['now' => $at, 'lim' => $limit]);
    foreach ($st->fetchAll() as $r) {
        $out['expired_requests']++;
        $pdo->prepare("SELECT dl_notify(:m, 'access_decided', :t, :b, 'access_request', :id, NULL, NULL, :dd)")
            ->execute(['m' => (int) $r['requester_id'], 't' => 'Your request for ' . $r['key'] . ' expired',
                       'b' => $r['why'] === 'wanted_until' ? 'Its wanted-until date passed before anyone decided.' : 'Nobody decided it in 30 days — ask again if you still need it.',
                       'id' => (int) $r['request_id'], 'dd' => 'access_expired:' . (int) $r['request_id']]);
        log_activity($pdo, 'access.expire', 'access_request', (int) $r['request_id'], ['source' => 'cron', 'actor_member_id' => null, 'dataset_id' => (int) $r['dataset_id'],
            'before' => ['status' => 'pending'], 'after' => ['status' => 'expired', 'why' => $r['why'], 'level' => $r['level']]]);
    }
    $st = $pdo->prepare("SELECT g.id, g.principal_kind, g.principal_id, g.level, g.expires_at, g.dataset_id, d.key AS dataset_key, d.owner_member_id, g.zone
                           FROM grants g LEFT JOIN datasets d ON d.id = g.dataset_id
                          WHERE g.revoked_at IS NULL AND g.expires_at > CAST(:now AS timestamptz) AND g.expires_at <= CAST(:now2 AS timestamptz) + interval '7 days'
                          ORDER BY g.expires_at LIMIT $limit");
    $st->execute(['now' => $at, 'now2' => $at]);
    foreach ($st->fetchAll() as $g) {
        $who = [];
        if (in_array($g['principal_kind'], ['member', 'agent'], true)) {
            $who[] = (int) $g['principal_id'];
        }
        if ($g['owner_member_id'] !== null) {
            $who[] = (int) $g['owner_member_id'];
        }
        $target = $g['dataset_key'] ?? ($g['zone'] !== null ? 'the ' . $g['zone'] . ' zone' : 'a tag');
        foreach (array_unique($who) as $m) {
            $told = db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM notifications WHERE member_id = :m AND kind = 'grant_expiring' AND record_type = 'grant' AND record_id = :g)",
                ['m' => $m, 'g' => (int) $g['id']]);
            if ($told) {
                continue;
            }
            $pdo->prepare("SELECT dl_notify(:m, 'grant_expiring', :t, :b, 'grant', :g, NULL, NULL, :dd)")
                ->execute(['m' => $m, 't' => 'A grant on ' . $target . ' ends ' . substr((string) $g['expires_at'], 0, 10),
                           'b' => 'A ' . $g['level'] . ' grant ends then. Its owner may renew it; the grantee may ask again.', 'g' => (int) $g['id'], 'dd' => 'grant_expiring:' . (int) $g['id'] . ':' . $m]);
            $out['announced']++;
        }
    }
    return $out;
}
/**
 * SLICE 3 — once a day after 01:00: yesterday's query_runs rolled into dataset_usage_daily and dataset_joins (dl_usage_rollup — a dataset unused
 * for unused_after_days marked Tier "unused"), then dl_query_runs_prune() (the SQL text after query_text_days, the row after audit_keep_days).
 * Done once per day: a `worker.usage_rollup` row names the day it rolled. The Usage tab, search's ranking (dl_usage_30d) and "popular" read it.
 */
function worker_pass_usage_rollup(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $day = $now->modify('-1 day')->format('Y-m-d');
    if (!in_array('usage_rollup', $GLOBALS['__worker_only'] ?? [], true)) {
        if ((int) $now->format('G') < 1) {
            return ['days' => 0, 'due' => false];
        }
        if (db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM activity_log WHERE action = 'worker.usage_rollup' AND after ->> 'day' = :d)", ['d' => $day])) {
            return ['days' => 0, 'done' => $day];
        }
    }
    $n = (int) one_value($pdo, 'SELECT dl_usage_rollup(CAST(:d AS date))', ['d' => $day]);
    $pruned = (int) one_value($pdo, 'SELECT dl_query_runs_prune()');
    // search ranks by the last 30 days' queries (search_index.usage_30d): a dataset whose count moved is indexed again
    $st = $pdo->prepare("SELECT s.entity_id FROM search_index s WHERE s.entity_kind = 'dataset' AND s.usage_30d IS DISTINCT FROM dl_usage_30d(s.entity_id) LIMIT :n");
    $st->bindValue('n', $limit, PDO::PARAM_INT);
    $st->execute();
    $reindexed = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare('SELECT dl_index_dataset(:id)')->execute(['id' => (int) $id]);
        $reindexed++;
    }
    log_activity($pdo, 'worker.usage_rollup', null, null, ['source' => 'cron', 'actor_member_id' => null,
        'after' => ['day' => $day, 'datasets' => $n, 'pruned' => $pruned, 'reindexed' => $reindexed]]);
    return ['days' => 1, 'day' => $day, 'datasets' => $n, 'pruned' => $pruned, 'reindexed' => $reindexed];
}
/** SLICE 8 — nightly: the writer's retention job queued per policy; query_runs.sql nulled after query_text_days; audit, check results and profiles past their windows deleted. */
function worker_pass_retention(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['queued' => 0, 'nulled' => 0, 'deleted' => 0, 'stub' => true]; }
/** SLICE 8 — at maintenance_hour:maintenance_minute the writer's maintenance job queued once a day (flush, merge, rewrite, expire, clean up). */
function worker_pass_maintenance(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['queued' => 0, 'stub' => true]; }
/**
 * SLICE 3 — export files past their expiry: the writer's sweep queued (an `export` job, op sweep — one queued at a time; it unlinks the files
 * of expired and deleted exports and nulls storage_path; it owns exports/, PHP never deletes there). The download door already answers 404.
 */
function worker_pass_exports(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $n = (int) one_value($pdo, 'SELECT count(*) FROM exports WHERE storage_path IS NOT NULL AND expires_at < CAST(:now AS timestamptz)', ['now' => $now->format(DATE_ATOM)]);
    if ($n === 0) {
        return ['expired' => 0];
    }
    $job = lake_enqueue($pdo, 'export', null, null, ['op' => 'sweep'], 7);
    return ['expired' => $n, 'sweep_job' => $job];
}
/** SLICE 10 — the outbox: email through MaluMail (malumail_send), texts to members through the kernel (kernel_send_text), retries with backoff. */
function worker_pass_outbox(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['sent' => 0, 'skipped' => 0, 'retried' => 0, 'failed' => 0, 'stub' => true]; }
/** SLICE 10 — agent dispatches: a blocking check failed on a dataset → the Steward's chat turn to triage; at most three attempts. */
function worker_pass_dispatches(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['dispatched' => 0, 'retried' => 0, 'stub' => true]; }
/** SLICE 10 — the engine's state for the admin's Engine page: the writer's oldest queued job, failed jobs, the query workers answering. */
function worker_pass_engine_health(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['checked' => 0, 'stub' => true]; }
/** dl_prune_key_usage() (db/005): minute buckets older than a day, day buckets older than 35 days. Real from the kit. */
function worker_pass_key_usage_prune(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return ['pruned' => (int) one_value($pdo, 'SELECT dl_prune_key_usage()')];
}
