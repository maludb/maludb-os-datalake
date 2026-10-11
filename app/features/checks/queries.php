<?php
declare(strict_types=1);

/**
 * Checks — the reads (contracts-quality.md "Query functions"; the records tools `find_checks` (the check resolver), `failing_checks`). Every
 * read goes through the walled views (mcp_checks, mcp_check_results: what the caller may discover; failing keys only at `read`); nothing here
 * decides a result — dl_check_result_record() did (db/010).
 */
require_once __DIR__ . '/templates.php';

/** Checks by filters: dataset (id), kind, enabled (yes/no), failing (yes: the latest result failed), q (words in the kind, column, dataset). */
function find_checks(PDO $pdo, array $f): array
{
    $where = ['NOT d.is_stub'];
    $args = [];
    if (!empty($f['dataset'])) {
        $where[] = 'k.dataset_id = :ds';
        $args['ds'] = (int) $f['dataset'];
    }
    if (!empty($f['kind']) && isset(CHECK_KINDS[$f['kind']])) {
        $where[] = 'k.kind = :kind';
        $args['kind'] = $f['kind'];
    }
    if (isset($f['enabled']) && $f['enabled'] !== null && $f['enabled'] !== '') {
        $where[] = 'k.enabled = :en';
        $args['en'] = $f['enabled'] ? 't' : 'f';
    }
    if (!empty($f['failing'])) {
        $where[] = "(k.last_result ->> 'passed')::boolean IS FALSE";
    }
    if (($f['q'] ?? '') !== '') {
        $where[] = '(k.kind ILIKE :like OR k.column_name ILIKE :like OR d.key ILIKE :like OR k.description ILIKE :like)';
        $args['like'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
    }
    $st = $pdo->prepare('SELECT k.check_id, k.dataset_id, d.key, d.title, k.column_name, k.kind, k.params, k.dimension, k.severity, k.blocking, k.run_when,
                                k.description, k.source, k.enabled, k.last_result
                           FROM mcp_checks k JOIN mcp_datasets d ON d.dataset_id = k.dataset_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY d.key, k.check_id LIMIT ' . (int) ($f['limit'] ?? 500));
    $st->execute($args);
    return array_map('check_decode', $st->fetchAll());
}

/** One check (with its dataset's key) the caller may discover — null otherwise. */
function find_check(PDO $pdo, int $id): ?array
{
    $r = one_row($pdo, 'SELECT k.check_id, k.dataset_id, d.key, d.title, d.zone, k.column_name, k.kind, k.params, k.dimension, k.severity, k.blocking, k.run_when,
                               k.description, k.source, k.enabled, k.last_result
                          FROM mcp_checks k JOIN mcp_datasets d ON d.dataset_id = k.dataset_id WHERE k.check_id = :id AND NOT d.is_stub', ['id' => $id]);
    return $r === null ? null : check_decode($r);
}

function check_decode(array $r): array
{
    foreach (['params', 'last_result'] as $c) {
        if (array_key_exists($c, $r) && !is_array($r[$c])) {
            $r[$c] = $r[$c] === null ? null : (json_decode((string) $r[$c], true) ?: ($c === 'params' ? [] : null));
        }
    }
    foreach (['blocking', 'enabled'] as $c) {
        if (array_key_exists($c, $r)) {
            $r[$c] = $r[$c] === true || $r[$c] === 't' || $r[$c] === 1 || $r[$c] === '1';
        }
    }
    return $r;
}

/** The last $n results of a check, oldest first (the strip): [{result_id, passed, error, value, rows_failed, ran_at}]. */
function check_strip(PDO $pdo, int $check, int $n = 7): array
{
    $st = $pdo->prepare('SELECT * FROM (SELECT result_id, passed, error, value, rows_failed, ran_at FROM mcp_check_results WHERE check_id = :c
                          ORDER BY ran_at DESC, result_id DESC LIMIT ' . max(1, min(60, $n)) . ') s ORDER BY ran_at, result_id');
    $st->execute(['c' => $check]);
    return array_map(static fn (array $r): array => $r + ['state' => $r['error'] !== null ? 'error' : ((bool) $r['passed'] ? 'passed' : 'failed')], $st->fetchAll());
}

/** Each check's strip in one read (the checks table): [check_id => strip]. */
function check_strips(PDO $pdo, array $checkIds, int $n = 7): array
{
    if ($checkIds === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT check_id, result_id, passed, error, value, rows_failed, ran_at FROM (
                             SELECT r.*, row_number() OVER (PARTITION BY r.check_id ORDER BY r.ran_at DESC, r.result_id DESC) AS rn
                               FROM mcp_check_results r WHERE r.check_id = ANY (CAST(:ids AS bigint[]))) s
                          WHERE rn <= ' . max(1, min(60, $n)) . ' ORDER BY check_id, ran_at, result_id');
    $st->execute(['ids' => pg_array_literal(array_map('intval', $checkIds))]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['check_id']][] = $r + ['state' => $r['error'] !== null ? 'error' : ((bool) $r['passed'] ? 'passed' : 'failed')];
    }
    return $out;
}

/** The live columns of a dataset (the check form's column select; the contract form's sources): [name => physical type]. */
function live_columns(PDO $pdo, int $dataset, bool $system = false): array
{
    $st = $pdo->prepare('SELECT name, physical_type FROM mcp_dataset_columns WHERE dataset_id = :d AND removed_snapshot_id IS NULL' . ($system ? '' : ' AND NOT is_system') . ' ORDER BY ordinal');
    $st->execute(['d' => $dataset]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(string) $r['name']] = (string) ($r['physical_type'] ?? '');
    }
    return $out;
}

/** A column's declared reference (slice 1's references_dataset_id / references_column) as {dataset_key, column} — the referential check's prefill. */
function column_reference(PDO $pdo, int $dataset, string $column): ?array
{
    $r = one_row($pdo, 'SELECT r.key, c.references_column FROM mcp_dataset_columns c JOIN mcp_datasets r ON r.dataset_id = c.references_dataset_id AND NOT r.is_stub
                         WHERE c.dataset_id = :d AND c.name = :n AND c.removed_snapshot_id IS NULL', ['d' => $dataset, 'n' => $column]);
    return $r === null || $r['references_column'] === null ? null : ['dataset_key' => (string) $r['key'], 'column' => (string) $r['references_column']];
}

/** The checks picker (the check resolver): checks the caller may discover. */
function checks_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $where = 'NOT d.is_stub AND (:q = \'\' OR k.kind ILIKE :like OR k.column_name ILIKE :like OR d.key ILIKE :like)';
    $args = ['q' => $q, 'like' => picker_like($q)];
    if (($params['dataset'] ?? null) !== null) {
        $where .= ' AND k.dataset_id = :ds';
        $args['ds'] = (int) $params['dataset'];
    }
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_checks k JOIN mcp_datasets d ON d.dataset_id = k.dataset_id WHERE $where",
        "SELECT k.check_id, k.kind, k.column_name, k.params, k.enabled, d.key FROM mcp_checks k JOIN mcp_datasets d ON d.dataset_id = k.dataset_id WHERE $where ORDER BY d.key, k.check_id", $args, $page);
    $r['rows'] = array_map(static fn (array $c): array => ['id' => (int) $c['check_id'], 'label' => (string) $c['key'] . ': ' . check_words(check_decode($c)),
        'detail' => CHECK_KINDS[$c['kind']]['label'] ?? (string) $c['kind'], 'badge' => $c['enabled'] === true || $c['enabled'] === 't' ? null : 'Off', 'color' => 'secondary'], $r['rows']);
    return $r;
}

/** May the caller manage a dataset's checks: checks.manage — or an agent the kernel relayed check_create for (its grant of the action). */
function may_manage_checks(): bool
{
    return has_right('checks.manage');
}

/** May the caller run a dataset's checks now: checks.manage or the dataset's steward. */
function may_run_checks(array $d): bool
{
    return has_right('checks.manage') || (int) ($d['steward_member_id'] ?? 0) === (int) current_member_id();
}
