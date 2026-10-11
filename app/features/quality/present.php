<?php
declare(strict_types=1);

/** Quality — the JSON shapes (whitelisted) and the health words (contracts-quality.md "Status vocabulary"). */
require_once dirname(__DIR__) . '/checks/present.php';
require_once dirname(__DIR__) . '/incidents/present.php';

/** Health: green "Healthy", amber "Needs a look", red "Failing" (badge class, words). */
function health_words(string $h): array
{
    return match ($h) { 'red' => ['danger', 'Failing'], 'amber' => ['warning', 'Needs a look'], default => ['success', 'Healthy'] };
}

/** Seconds overdue in words ("2 days 3 hours", "5 hours"). */
function overdue_words(?int $s): string
{
    if ($s === null) {
        return 'never loaded';
    }
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    return trim(($d > 0 ? $d . ' day' . ($d === 1 ? '' : 's') . ' ' : '') . ($h > 0 || $d > 0 ? $h . ' hour' . ($h === 1 ? '' : 's') : $m . ' minutes'));
}

function present_health(?array $h): ?array
{
    if ($h === null) {
        return null;
    }
    return ['health' => $h['health'] ?? 'green', 'words' => health_words((string) ($h['health'] ?? 'green'))[1], 'freshness' => $h['freshness'] ?? null,
        'data_updated_at' => json_ts($h['data_updated_at'] ?? null), 'certification' => $h['certification'] ?? 'none',
        'certification_missing' => array_values((array) ($h['certification_missing'] ?? [])), 'certification_lost_reason' => $h['certification_lost_reason'] ?? null,
        'checks' => array_map(static fn (array $c): array => ['check_id' => (int) $c['check_id'], 'kind' => $c['kind'], 'column' => $c['column'] ?? null,
            'blocking' => (bool) $c['blocking'], 'passed' => $c['passed'] === null ? null : (bool) $c['passed'], 'value' => $c['value'], 'ran_at' => json_ts($c['ran_at'] ?? null)],
            (array) ($h['checks'] ?? [])),
        'open_incidents' => array_map(static fn (array $i): array => ['incident_id' => (int) $i['incident_id'], 'title' => $i['title'], 'severity' => $i['severity'],
            'status' => $i['status'], 'opened_at' => json_ts($i['opened_at'] ?? null)], (array) ($h['open_incidents'] ?? [])),
        'upstream_incidents' => array_values((array) ($h['upstream_incidents'] ?? []))];
}

function present_failing(array $c): array
{
    $last = is_array($c['last_result'] ?? null) ? $c['last_result'] : [];
    return ['check_id' => (int) $c['check_id'], 'dataset_id' => (int) $c['dataset_id'], 'dataset' => $c['key'], 'kind' => $c['kind'], 'column' => $c['column_name'],
        'words' => check_words($c), 'severity' => $c['severity'], 'blocking' => (bool) $c['blocking'], 'value' => isset($last['value']) ? (float) $last['value'] : null,
        'threshold' => $c['threshold'] ?? null, 'since' => json_ts($c['since'] ?? null)];
}

function present_late(array $l): array
{
    return ['dataset_id' => (int) $l['dataset_id'], 'dataset' => $l['key'], 'title' => $l['title'], 'state' => $l['state'],
        'data_updated_at' => json_ts($l['data_updated_at']), 'overdue' => $l['overdue_s'] === null ? 'never loaded' : overdue_words((int) $l['overdue_s'])];
}
