<?php
declare(strict_types=1);

/**
 * Quality — the lake's health and the calls a person makes on it (contracts-quality.md, THE THIRD EXEMPLAR: the database decides, PHP renders
 * and calls). The records tools `dataset_health`, `failing_checks`, `late_datasets` read here; promotion and a check run are writer jobs;
 * certification is dl_certify() / its loss dl_certification_recheck(); drift accepted DRAFTS the contract's next version (divergence 2).
 */
require_once dirname(__DIR__) . '/checks/queries.php';
require_once dirname(__DIR__) . '/incidents/queries.php';
require_once dirname(__DIR__) . '/contracts/queries.php';

/** Failing checks: the latest result failed (the caller's discover wall), with the dataset, the value, the threshold and since when. */
function failing_checks(PDO $pdo, array $f = []): array
{
    $where = ["NOT d.is_stub", "(k.last_result ->> 'passed')::boolean IS FALSE", 'k.enabled'];
    $args = [];
    if (in_array($f['severity'] ?? null, ['warn', 'error'], true)) {
        $where[] = 'k.severity = :sev';
        $args['sev'] = $f['severity'];
    }
    if (!empty($f['department'])) {
        $where[] = 'd.department_id = :dep';
        $args['dep'] = (int) $f['department'];
    }
    if (!empty($f['dataset'])) {
        $where[] = 'k.dataset_id = :ds';
        $args['ds'] = (int) $f['dataset'];
    }
    $st = $pdo->prepare('SELECT k.check_id, k.dataset_id, d.key, d.title, k.column_name, k.kind, k.params, k.severity, k.blocking, k.last_result,
                                (SELECT r.threshold FROM mcp_check_results r WHERE r.check_id = k.check_id ORDER BY r.ran_at DESC, r.result_id DESC LIMIT 1) AS threshold,
                                COALESCE((SELECT min(r.ran_at) FROM mcp_check_results r WHERE r.check_id = k.check_id
                                            AND r.ran_at > COALESCE((SELECT max(p.ran_at) FROM mcp_check_results p WHERE p.check_id = k.check_id AND p.passed), \'-infinity\')),
                                         (k.last_result ->> \'ran_at\')::timestamptz) AS since
                           FROM mcp_checks k JOIN mcp_datasets d ON d.dataset_id = k.dataset_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY (k.severity = \'error\') DESC, since, d.key LIMIT 200');
    $st->execute($args);
    return array_map('check_decode', $st->fetchAll());
}

/** Late datasets (dl_freshness_due — the caller's discover wall applied here): key, warn/error, data updated, overdue. */
function late_datasets(PDO $pdo, array $f = []): array
{
    $where = ['NOT d.is_stub'];
    $args = [];
    if (in_array($f['severity'] ?? null, ['warn', 'error'], true)) {
        $where[] = 'l.state = :sev';
        $args['sev'] = $f['severity'];
    }
    if (!empty($f['department'])) {
        $where[] = 'd.department_id = :dep';
        $args['dep'] = (int) $f['department'];
    }
    $st = $pdo->prepare('SELECT l.dataset_id, l.key, d.title, l.state, l.data_updated_at, extract(epoch FROM l.overdue)::bigint AS overdue_s
                           FROM dl_freshness_due() l JOIN mcp_datasets d ON d.dataset_id = l.dataset_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY (l.state = \'error\') DESC, l.overdue DESC NULLS FIRST LIMIT 200');
    $st->execute($args);
    return $st->fetchAll();
}

/** The quality home: three lists (screen `quality`). $filters: severity, department. */
function quality_home(PDO $pdo, int $member, array $filters): array
{
    $open = find_incidents($pdo, ['status' => 'open'] + array_intersect_key($filters, ['severity' => 1, 'department' => 1]), 1);
    return ['failing' => failing_checks($pdo, $filters), 'late' => late_datasets($pdo, $filters), 'incidents' => $open['rows'], 'incidents_total' => $open['total']];
}

/** dl_dataset_health — the Quality tab's and the dot's answer (null below discover). */
function dataset_health(PDO $pdo, int $dataset): ?array
{
    $v = one_value($pdo, 'SELECT dl_dataset_health(:d)', ['d' => $dataset]);
    return $v === null ? null : (json_decode((string) $v, true) ?: null);
}

/** Certify (dl_certify decides — eligible, the right; promoted is the owner's lighter badge). */
function certify(PDO $pdo, int $dataset, string $level): void
{
    $pdo->prepare('SELECT dl_certify(:d, :l)')->execute(['d' => $dataset, 'l' => $level]);
}

/** Remove a certification by hand, with a reason (the reason kept as certification_lost_reason, its time as certification_lost_at). */
function certify_remove(PDO $pdo, int $dataset, string $reason, int $by): void
{
    $st = $pdo->prepare("UPDATE datasets SET certification = 'none', certified_at = NULL, certified_by = NULL, certification_lost_reason = :r,
                                certification_lost_at = now() WHERE id = :d AND certification <> 'none'");
    $st->execute(['r' => 'removed: ' . $reason, 'd' => $dataset]);
    if ($st->rowCount() === 0) {
        throw new DomainException('It is not certified.');
    }
}

/** Promote now (incremental) or rebuild whole: a `promote` job for the writer — its id. Refused without an active contract. */
function promote(PDO $pdo, int $dataset, bool $rebuild, int $by): int
{
    if (one_value($pdo, "SELECT 1 FROM contracts WHERE dataset_id = :d AND status = 'active'", ['d' => $dataset]) === null) {
        throw new DomainException('Publish a contract first — promotion types by it');
    }
    $queued = one_row($pdo, "SELECT id, params FROM lake_jobs WHERE kind = 'promote' AND dataset_id = :d AND status = 'queued' LIMIT 1", ['d' => $dataset]);
    if ($queued !== null && $rebuild && empty((json_decode((string) $queued['params'], true) ?: [])['full'])) {
        throw new DomainException('A promotion is already queued — rebuild once it has run.');   // the queue keeps one job a kind per dataset
    }
    return lake_enqueue($pdo, 'promote', $dataset, null, $rebuild ? ['full' => true] : [], $rebuild ? 4 : 3);
}

/**
 * Decide a pending schema change of the raw input (divergence 2): accept → the contract's next version DRAFTED (an added column a minor —
 * typed VARCHAR, reading the new raw column; a removed one a major — the curated column that read it dropped), never published; reject → the
 * decision recorded (the rescued values stay in _dl_rescued). Returns the draft's id (accept) or null.
 */
function drift_decide(PDO $pdo, int $dataset, int $schemaVersion, bool $accept, int $by): ?int
{
    require_once dirname(__DIR__) . '/contracts/write.php';
    $v = one_row($pdo, "SELECT v.id, v.dataset_id, v.changes, v.decision, d.key FROM schema_versions v JOIN datasets d ON d.id = v.dataset_id WHERE v.id = :v", ['v' => $schemaVersion]);
    if ($v === null) {
        throw new DomainException('Not found.');
    }
    if ($v['decision'] !== 'pending') {
        throw new DomainException('That change was already decided.');
    }
    $active = one_row($pdo, "SELECT id, version, schema, key, ordering_column, sla FROM contracts WHERE dataset_id = :d AND status = 'active'", ['d' => $dataset]);
    $schema = $active === null ? [] : (json_decode((string) $active['schema'], true) ?: []);
    if ($active === null || contract_raw_key($schema) !== (string) $v['key']) {
        throw new DomainException('That change is not of the raw dataset this one promotes from.');
    }
    $pdo->prepare("UPDATE schema_versions SET decision = :dec, decided_by = :by, decided_at = now() WHERE id = :id")
        ->execute(['dec' => $accept ? 'accepted' : 'rejected', 'by' => $by, 'id' => $schemaVersion]);
    if (!$accept) {
        return null;
    }
    $changes = json_decode((string) $v['changes'], true) ?: [];
    foreach ($changes as $c) {
        if (($c['kind'] ?? '') === 'added' && ($c['to'] ?? '') === '_dl_rescued') {
            throw new DomainException('The column ' . ($c['column'] ?? '') . ' was rescued — ' . $v['key'] . ' keeps new columns aside (_dl_rescued). Set it to add new columns, '
                . 'replay the load, then accept the change it reports.');
        }
    }
    $removedSources = [];
    $added = [];
    foreach ($changes as $c) {
        $col = (string) ($c['column'] ?? '');
        if (($c['kind'] ?? '') === 'removed') {
            $removedSources[] = $v['key'] . '.' . $col;
        } elseif (($c['kind'] ?? '') === 'added' && $col !== '') {
            $added[] = $col;
        }
    }
    $draft = contract_draft($pdo, $dataset);
    $base = $draft !== null ? $draft['schema'] : $schema;
    $names = array_column($base, 'name');
    $next = array_values(array_filter($base, static fn (array $e): bool => !in_array((string) ($e['source'] ?? ''), $removedSources, true)));
    foreach ($added as $col) {
        if (!in_array($col, $names, true) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $col) && !str_starts_with(strtolower($col), '_dl_')) {
            $next[] = ['name' => $col, 'logical_type' => 'string', 'physical_type' => 'VARCHAR', 'required' => false, 'unique' => false, 'primary_key' => false,
                       'source' => $v['key'] . '.' . $col, 'description' => null, 'classification' => null, 'personal_kind' => null];
        }
    }
    [$M, $m] = array_map('intval', explode('.', (string) $active['version']));
    $key = $draft !== null ? $draft['key'] : pg_text_array((string) $active['key']);
    $key = array_values(array_intersect($key, array_column($next, 'name')));
    $version = $removedSources !== [] || $key === [] ? ($M + 1) . '.0.0' : "$M." . ($m + 1) . '.0';
    if ($draft !== null && version_compare((string) $draft['version'], $version, '>')) {
        $version = (string) $draft['version'];
    }
    return contract_draft_save($pdo, $dataset, ['version' => $version, 'schema' => $next, 'key' => $key,
        'ordering_column' => $draft !== null ? $draft['ordering_column'] : $active['ordering_column'],
        'sla' => $draft !== null ? $draft['sla'] : (json_decode((string) $active['sla'], true) ?: []), 'contract' => $draft === null ? null : (int) $draft['contract_id']], $by);
}

/** The quarantine's rows as the caller may read them (one lake_query — `preview` with `quarantine: true`): the service's answer + reasons. */
function quarantine_rows(PDO $pdo, int $member, int $dataset, ?string $reason, int $page): array
{
    $key = (string) one_value($pdo, 'SELECT key FROM datasets WHERE id = :d', ['d' => $dataset]);
    $caller = lake_caller();
    if ($caller['source'] === 'screen') {
        $caller['source'] = 'preview';
    }
    $req = ['kind' => 'preview', 'dataset' => $key, 'quarantine' => true, 'offset' => max(0, ($page - 1) * 100)] + $caller;
    if ($reason !== null && $reason !== '') {
        $req['reason'] = $reason;
    }
    return lake_query($req, 30);
}

/** The open incidents on the datasets I use (the home's tile): I own, steward or follow them, I am assigned, or I read them this month. */
function my_open_incidents(PDO $pdo, int $member, int $limit = 6): array
{
    $st = $pdo->prepare("SELECT i.incident_id, i.title, i.severity, i.status, d.key FROM mcp_incidents i JOIN mcp_datasets d ON d.dataset_id = i.dataset_id AND NOT d.is_stub
                          WHERE i.status IN ('new', 'acknowledged', 'investigating')
                            AND (d.owner_member_id = :me OR d.steward_member_id = :me OR i.assignee_member_id = :me
                                 OR EXISTS (SELECT 1 FROM mcp_dataset_follows f WHERE f.dataset_id = i.dataset_id)
                                 OR EXISTS (SELECT 1 FROM query_runs q WHERE q.caller_member_id = :me AND q.dataset_ids @> ARRAY[i.dataset_id]
                                               AND q.created_at > now() - interval '30 days' AND q.status = 'ok'))
                          ORDER BY (i.severity = 'error') DESC, i.opened_at DESC LIMIT " . max(1, $limit));
    $st->execute(['me' => $member]);
    return $st->fetchAll();
}

/** The dataset's latest promotion (the writer's job): status, the counts it answered, the error in words — for the Quality tab and its poll. */
function last_promotion(PDO $pdo, int $dataset): ?array
{
    $r = one_row($pdo, "SELECT id AS job_id, status, result, error, attempts, created_at, started_at, finished_at, params FROM lake_jobs
                         WHERE kind = 'promote' AND dataset_id = :d ORDER BY id DESC LIMIT 1", ['d' => $dataset]);
    if ($r === null) {
        return null;
    }
    $r['result'] = json_decode((string) ($r['result'] ?? 'null'), true) ?: [];
    $r['params'] = json_decode((string) ($r['params'] ?? 'null'), true) ?: [];
    return $r;
}

/** A promotion's words for the tab: "Promoted 120 rows (3 held back, 2 duplicates collapsed) — contract 1.0.0". */
function promotion_words(?array $p): ?string
{
    if ($p === null) {
        return null;
    }
    $r = $p['result'];
    return match ((string) $p['status']) {
        'queued' => 'A promotion is queued.',
        'running' => 'A promotion is running.',
        'done' => ($r['status'] ?? '') === 'nothing_new' ? 'Nothing new in raw since the last promotion.'
            : (!empty($r['full']) ? 'Rebuilt whole: ' : 'Promoted ') . number_format((int) ($r['rows_promoted'] ?? 0)) . ' rows'
              . ' (' . number_format((int) ($r['rows_quarantined'] ?? 0)) . ' held back, ' . number_format((int) ($r['rows_deduped'] ?? 0)) . ' duplicates collapsed)'
              . (isset($r['contract']) ? ' — contract ' . $r['contract'] : '') . (!empty($r['rebuilt_for_shape']) ? '; the contract changed its shape, so it was rebuilt' : '') . '.',
        'failed' => 'The last promotion failed: ' . (string) ($p['error'] ?? 'see the job'),
        default => (string) $p['status'],
    };
}
