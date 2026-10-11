<?php
declare(strict_types=1);

/** Checks — the JSON shapes (whitelisted) and the strip's words (contracts-quality.md "Status vocabulary"). */
require_once __DIR__ . '/templates.php';

/** A check result's badge: passed success; failed danger (error) / warning (warn); could not run secondary. */
function result_badge(?array $last, string $severity = 'error'): array
{
    if ($last === null) {
        return ['bg-light text-muted', 'Not run yet'];
    }
    if (($last['error'] ?? null) !== null || ($last['state'] ?? '') === 'error') {
        return ['bg-secondary', 'Could not run'];
    }
    if ((bool) ($last['passed'] ?? false)) {
        return ['bg-success', 'Passed'];
    }
    return $severity === 'warn' ? ['bg-warning text-dark', 'Failed'] : ['bg-danger', 'Failed'];
}

/** A severity badge: error danger "Error", warn warning "Warn" (a check's, an incident's). */
function severity_badge(string $severity): array
{
    return $severity === 'error' ? ['bg-danger', 'Error'] : ['bg-warning text-dark', 'Warn'];
}

function present_check(array $c, ?array $strip = null): array
{
    $last = is_array($c['last_result'] ?? null) ? $c['last_result'] : null;
    return [
        'check_id' => (int) $c['check_id'], 'dataset_id' => (int) $c['dataset_id'], 'dataset' => $c['key'] ?? null, 'kind' => $c['kind'],
        'column' => $c['column_name'], 'params' => (object) ($c['params'] ?? []), 'words' => check_words($c), 'dimension' => $c['dimension'],
        'severity' => $c['severity'], 'blocking' => (bool) $c['blocking'], 'run_when' => $c['run_when'], 'description' => $c['description'],
        'source' => $c['source'], 'enabled' => (bool) $c['enabled'],
        'last_result' => $last === null ? null : ['passed' => (bool) $last['passed'], 'value' => $last['value'] === null ? null : (float) $last['value'],
            'rows_failed' => (int) ($last['rows_failed'] ?? 0), 'ran_at' => json_ts($last['ran_at'] ?? null)],
        'strip' => $strip === null ? null : array_map(static fn (array $s): array => ['result_id' => (int) $s['result_id'], 'state' => $s['state'],
            'ran_at' => json_ts($s['ran_at'])], $strip),
    ];
}
