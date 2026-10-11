<?php
declare(strict_types=1);

/** Whitelisting presenters for loads (JSON mode and the MCP tools' shape): ids, counts, statuses, names — never a raw line below write. */

function present_load_row(array $r): array
{
    return ['load_id' => (int) $r['load_id'], 'dataset_id' => (int) $r['dataset_id'], 'dataset' => $r['dataset_key'] ?? null, 'source_id' => $r['source_id'] === null ? null : (int) $r['source_id'],
            'status' => $r['status'], 'mode' => $r['mode'] ?? null, 'rows_read' => (int) $r['rows_read'], 'rows_loaded' => (int) $r['rows_loaded'],
            'rows_rejected' => (int) $r['rows_rejected'], 'rows_rescued' => (int) $r['rows_rescued'], 'cast_rejects' => (int) ($r['cast_rejects'] ?? 0),
            'checks_outcome' => $r['checks_outcome'], 'snapshot_id' => $r['snapshot_id'] === null ? null : (int) $r['snapshot_id'],
            'requested_by' => $r['requested_by'] === null ? null : (int) $r['requested_by'], 'requested_by_name' => $r['requested_by_name'] ?? null,
            'queued_at' => json_ts($r['queued_at']), 'finished_at' => json_ts($r['finished_at'])];
}

function present_load(array $l, bool $mayWrite): array
{
    return present_load_row($l) + [
        'file' => ['name' => $l['file_name'], 'bytes' => $l['file_bytes'] === null ? null : (int) $l['file_bytes'],
                   'sha256_prefix' => $l['landing_sha256'] === null ? null : substr((string) $l['landing_sha256'], 0, 12),
                   'landing_purged' => $l['landing_purged_at'] !== null, 'replayable' => $l['landing_purged_at'] === null && $l['blob_id'] !== null],
        'schema_seen' => $l['schema_seen'], 'error' => $l['error'], 'template' => $l['template_name'] ?? null,
        'started_at' => json_ts($l['started_at']), 'rolled_back_at' => json_ts($l['rolled_back_at']), 'rolled_back_by_name' => $l['rolled_back_by_name'] ?? null,
        'rollback_snapshot_id' => $l['rollback_snapshot_id'] === null ? null : (int) $l['rollback_snapshot_id'],
        'job' => $l['job'] === null ? null : ['job_id' => (int) $l['job']['job_id'], 'kind' => $l['job']['kind'], 'status' => $l['job']['status'], 'error' => $l['job']['error']],
        'may' => ['replay' => $mayWrite && has_right('loads.manage'), 'rollback' => $mayWrite && has_right('loads.manage'), 'raw_lines' => $mayWrite],
    ];
}

function present_reject(array $r): array
{
    return ['reject_id' => (int) $r['reject_id'], 'line' => $r['line'] === null ? null : (int) $r['line'], 'column' => $r['column_name'],
            'error_type' => $r['error_type'], 'message' => $r['message']] + (array_key_exists('raw_line', $r) ? ['raw_line' => $r['raw_line']] : []);
}

/** A load's status badge: [class, words]. */
function load_badge(string $status): array
{
    [$c, $w] = LOAD_STATUS[$status] ?? ['secondary', $status];
    return ['bg-soft-' . $c . ' text-' . ($c === 'light' ? 'dark' : $c), $w];
}

/** The drift a load saw, in words: ["2 columns added", …]. */
function drift_words(?array $drift): array
{
    if ($drift === null) {
        return [];
    }
    $out = [];
    foreach (['added' => 'added', 'rescued' => 'rescued into _dl_rescued', 'missing' => 'missing (NULL in this load)'] as $k => $w) {
        $n = count($drift[$k] ?? []);
        if ($n > 0) {
            $out[] = $n . ' column' . ($n === 1 ? '' : 's') . ' ' . $w . ': ' . implode(', ', array_slice($drift[$k], 0, 8)) . ($n > 8 ? '…' : '');
        }
    }
    return $out;
}
