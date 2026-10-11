<?php
declare(strict_types=1);
/**
 * /sandbox (screen `sandbox`; uploads-loads.md): my sandbox datasets as cards — title, key, rows, size, "data updated", made from an upload
 * or saved from a query — and my quota bar (dl_sandbox_usage). Retire is slice 1's action on each (own). Own only: nobody else's sandbox is
 * listed here, ever. sandbox.upload. JSON: the my_sandbox tool's shape.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/sandbox/queries.php';
require_once dirname(__DIR__) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__) . '/app/features/catalog/present.php';
require_login();
require_right('sandbox.upload');
$pdo = db();
$me = (int) current_member_id();
$s = my_sandbox($pdo, $me);
log_screen_view($pdo, 'sandbox');
if (wants_json()) {
    respond_screen(['datasets' => array_map(static fn (array $d): array => ['dataset_id' => (int) $d['dataset_id'], 'key' => $d['key'], 'title' => $d['title'],
        'rows' => $d['row_count'] === null ? null : (int) $d['row_count'], 'bytes' => $d['byte_size'] === null ? null : (int) $d['byte_size'],
        'data_updated_at' => json_ts($d['data_updated_at']), 'made_from' => $d['from_upload'] ? 'upload' : 'query', 'classification' => $d['classification'],
        'has_personal_data' => (bool) $d['has_personal_data'], 'last_load_id' => $d['last_load_id'] === null ? null : (int) $d['last_load_id']], $s['datasets']),
        'used_bytes' => $s['used_bytes'], 'quota_bytes' => $s['quota_bytes']]);
}
render_screen('My sandbox', view('sandbox/index.php', ['s' => $s, 'me' => $me, 'notice' => dl_notice($_GET['notice'] ?? null, ['retired' => ['success', 'Retired.']])]),
    ['activeNav' => 'sandbox', 'screen' => 'sandbox']);
