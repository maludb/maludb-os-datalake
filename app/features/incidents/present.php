<?php
declare(strict_types=1);

/** Incidents — the JSON shapes (whitelisted) and the status words (contracts-quality.md "Status vocabulary"). */
require_once dirname(__DIR__) . '/checks/present.php';                     // severity_badge(), present_check()

/** An incident status badge: new danger, acknowledged warning, investigating info, resolved success, dismissed secondary. */
function incident_badge(string $status): array
{
    return match ($status) {
        'new' => ['bg-danger', 'New'], 'acknowledged' => ['bg-warning text-dark', 'Acknowledged'], 'investigating' => ['bg-info', 'Investigating'],
        'resolved' => ['bg-success', 'Resolved'], default => ['bg-secondary', 'Dismissed'],
    };
}


function present_incident_row(array $i): array
{
    return ['incident_id' => (int) $i['incident_id'], 'title' => $i['title'], 'dataset_id' => (int) $i['dataset_id'], 'dataset' => $i['key'] ?? null,
        'kind' => $i['kind'], 'severity' => $i['severity'], 'status' => $i['status'], 'assignee' => $i['assignee_name'] ?? null,
        'assignee_member_id' => $i['assignee_member_id'] === null ? null : (int) $i['assignee_member_id'], 'opened_at' => json_ts($i['opened_at']),
        'resolved_at' => json_ts($i['resolved_at'] ?? null), 'downstream_count' => (int) ($i['downstream_count'] ?? 0), 'url' => '/incidents/' . (int) $i['incident_id']];
}

function present_result(?array $r): ?array
{
    if ($r === null) {
        return null;
    }
    return ['result_id' => (int) $r['result_id'], 'value' => $r['value'] === null ? null : (float) $r['value'], 'threshold' => $r['threshold'],
        'passed' => (bool) $r['passed'], 'rows_failed' => (int) $r['rows_failed'], 'failing_keys' => json_decode((string) ($r['failing_keys'] ?? '[]'), true) ?: [],
        'error' => $r['error'], 'ran_at' => json_ts($r['ran_at']), 'load_id' => $r['load_id'] === null ? null : (int) $r['load_id']];
}

function present_incident(array $i, array $may): array
{
    require_once dirname(__DIR__) . '/checks/present.php';
    return present_incident_row($i + ['downstream_count' => count($i['downstream_known']) + (int) $i['downstream_hidden']]) + [
        'check' => $i['check'] === null ? null : present_check($i['check']), 'opened_by' => present_result($i['opened_by']), 'resolved_by' => present_result($i['resolved_by']),
        'acknowledged_at' => json_ts($i['acknowledged_at']), 'root_cause' => $i['root_cause'], 'resolution' => $i['resolution'],
        'downstream' => $i['downstream_known'], 'downstream_hidden' => (int) $i['downstream_hidden'],
        'notes' => array_map(static fn (array $n): array => ['note_id' => (int) $n['note_id'], 'kind' => $n['kind'], 'body' => $n['body'], 'author' => $n['author'],
            'by_agent' => (bool) $n['by_agent'], 'created_at' => json_ts($n['created_at'])], $i['notes']),
        'may' => $may,
    ];
}
