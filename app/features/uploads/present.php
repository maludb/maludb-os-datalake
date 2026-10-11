<?php
declare(strict_types=1);

/** Whitelisting presenters for uploads. The sniff's sample rows go only to the uploader's own JSON (find_upload() answers own only). */

function upload_badge(string $status, string $sniffStatus = 'none'): array
{
    if ($status === 'done' && isset(SNIFF_STATUS[$sniffStatus]) && SNIFF_STATUS[$sniffStatus] !== null) {
        [$c, $w] = SNIFF_STATUS[$sniffStatus];
    } else {
        [$c, $w] = UPLOAD_STATUS[$status] ?? ['secondary', $status];
    }
    return ['bg-soft-' . $c . ' text-' . $c, $w];
}

function present_upload_row(array $u): array
{
    return ['upload_id' => (int) $u['id'], 'filename' => $u['filename'], 'byte_size' => (int) $u['byte_size'], 'status' => $u['status'],
            'sniff_status' => $u['sniff_status'], 'parts_done' => (int) ($u['parts_done'] ?? 0), 'parts_total' => (int) ($u['parts_total'] ?? 0),
            'load_id' => isset($u['load_id']) && $u['load_id'] !== null ? (int) $u['load_id'] : null, 'load_status' => $u['load_status'] ?? null,
            'dataset' => $u['dataset_key'] ?? null, 'created_at' => json_ts($u['created_at'])];
}

/** One upload and its sniff for the uploader: the format, the encoding, the delimiter, the sheets, the columns with their proposals, the sample. */
function present_upload(array $u, ?string $sheet, bool $withSample = true): array
{
    $s = $u['sniff'];
    $out = ['upload_id' => (int) $u['id'], 'filename' => $u['filename'], 'byte_size' => (int) $u['byte_size'], 'status' => $u['status'],
            'sniff_status' => $u['sniff_status'], 'sha256_prefix' => $u['sha256'] === null ? null : substr((string) $u['sha256'], 0, 12),
            'load_id' => $u['load_id'] === null ? null : (int) $u['load_id'], 'error' => $u['sniff_status'] === 'failed' ? ($s['error'] ?? $u['sniff_error']) : $u['error']];
    if (is_array($s) && !isset($s['error'])) {
        $sh = sniff_sheet($s, $sheet);
        $out['sniff'] = ['format' => $s['format'], 'encoding' => $s['encoding'], 'delimiter' => $s['delimiter'], 'header_row' => (int) ($s['header_row'] ?? 1),
            'sheets' => array_map(static fn (array $x): array => ['name' => $x['name'], 'header_rows' => (int) $x['header_rows'], 'rows_estimate' => $x['rows_estimate'],
                'warning' => $x['warning'] ?? null], $s['sheets'] ?? []),
            'sheet' => $sh['name'], 'rows_estimate' => $sh['rows_estimate'], 'warnings' => array_values(array_filter(array_merge((array) ($s['warnings'] ?? []), [$sh['warning']]))),
            'columns' => array_map(static fn (array $c): array => ['source_name' => $c['source_name'], 'name' => $c['name'], 'type' => $c['type'] ?? 'VARCHAR',
                'proposed_personal_kind' => $c['proposed_personal_kind'] ?? null, 'confidence' => $c['confidence'] ?? null, 'note' => $c['note'] ?? null], $sh['columns'])];
        if ($withSample) {
            $out['sniff']['sample'] = $sh['rows'];
            $out['sniff']['sample_cleared'] = !isset($s['sample']);
        }
    }
    return $out;
}
