<?php
declare(strict_types=1);

/** Contracts — the JSON shapes (whitelisted) and the words of a status, an SLA and a breaking change (contracts-quality.md "Status vocabulary"). */
require_once __DIR__ . '/queries.php';

/** A contract status badge: draft secondary, active success, deprecated dark. */
function contract_status_badge(string $status): array
{
    return match ($status) { 'active' => ['bg-success', 'Active'], 'deprecated' => ['bg-dark', 'Deprecated'], default => ['bg-secondary', 'Draft'] };
}

/** An ISO 8601 duration in words (P1D → "1 day", PT6H → "6 hours", P2Y → "2 years"). */
function duration_words(?string $iso): ?string
{
    if ($iso === null || $iso === '') {
        return null;
    }
    if (!preg_match('/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $iso, $m)) {
        return $iso;
    }
    $parts = [];
    foreach ([1 => 'year', 2 => 'month', 3 => 'week', 4 => 'day', 5 => 'hour', 6 => 'minute', 7 => 'second'] as $i => $unit) {
        if (($m[$i] ?? '') !== '' && (int) $m[$i] > 0) {
            $parts[] = (int) $m[$i] . ' ' . $unit . ((int) $m[$i] === 1 ? '' : 's');
        }
    }
    return $parts === [] ? $iso : implode(' ', $parts);
}

/** The SLA in words: "fresh within 1 day; kept 2 years; …". */
function sla_words(array $sla): string
{
    $words = ['freshness' => 'fresh within', 'retention' => 'kept', 'time_to_detect' => 'a problem detected within', 'time_to_notify' => 'people told within',
              'time_to_repair' => 'repaired within'];
    $out = [];
    foreach ($words as $k => $w) {
        if (!empty($sla[$k])) {
            $out[] = $w . ' ' . duration_words((string) $sla[$k]);
        }
    }
    return $out === [] ? 'No SLA set.' : ucfirst(implode('; ', $out)) . '.';
}

/** A breaking change in words. */
function breaking_words(array $b): string
{
    return match ((string) ($b['kind'] ?? '')) {
        'removed' => $b['column'] . ' removed',
        'retyped' => $b['column'] . ' retyped ' . ($b['from'] ?? '?') . ' → ' . ($b['to'] ?? '?'),
        'key_changed' => 'the key changed (' . implode(', ', (array) ($b['from'] ?? [])) . ' → ' . implode(', ', (array) ($b['to'] ?? [])) . ')',
        default => (string) json_encode($b),
    };
}

function present_contract(?array $k): ?array
{
    if ($k === null) {
        return null;
    }
    return ['contract_id' => (int) $k['contract_id'], 'dataset_id' => (int) $k['dataset_id'], 'version' => $k['version'], 'status' => $k['status'],
        'schema' => array_map(static fn (array $e): array => ['name' => $e['name'], 'logical_type' => $e['logical_type'] ?? null, 'physical_type' => $e['physical_type'] ?? null,
            'required' => (bool) ($e['required'] ?? false), 'unique' => (bool) ($e['unique'] ?? false), 'primary_key' => (bool) ($e['primary_key'] ?? false),
            'source' => $e['source'] ?? null, 'description' => $e['description'] ?? null, 'classification' => $e['classification'] ?? null,
            'personal_kind' => $e['personal_kind'] ?? null], (array) $k['schema']),
        'key' => array_values((array) $k['key']), 'ordering_column' => $k['ordering_column'], 'sla' => (object) ($k['sla'] ?? []), 'sla_words' => sla_words((array) ($k['sla'] ?? [])),
        'raw' => contract_raw_key((array) $k['schema']), 'breaking' => (bool) $k['breaking'], 'breaking_changes' => array_values((array) ($k['breaking_changes'] ?? [])),
        'published_at' => json_ts($k['published_at'] ?? null), 'published_by' => $k['published_by_name'] ?? null];
}

function present_contract_version(array $v): array
{
    return ['contract_id' => (int) $v['contract_id'], 'version' => $v['version'], 'status' => $v['status'], 'breaking' => (bool) $v['breaking'],
        'breaking_changes' => array_map('breaking_words', array_values((array) $v['breaking_changes'])), 'published_at' => json_ts($v['published_at'] ?? null)];
}

/** The impact as the page and the JSON show it: datasets by key (only those the caller may know), saved queries and the rest counted. */
function present_impact(array $i): array
{
    return ['datasets' => array_map(static fn (array $d): string => (string) $d['key'], (array) ($i['datasets'] ?? [])), 'hidden_datasets' => (int) ($i['hidden'] ?? 0),
        'saved_queries' => count((array) ($i['saved_queries'] ?? [])), 'metrics' => count((array) ($i['metrics'] ?? [])),
        'transforms' => count((array) ($i['transforms'] ?? [])), 'consumers' => array_values(array_map('strval', (array) ($i['consumers'] ?? [])))];
}
