<?php
declare(strict_types=1);
/**
 * /datasets/{id|key}/checks (screen `checks`; contracts-quality.md) — a dataset's checks as a table: kind, column, params in words, dimension,
 * severity, blocking, when, enabled, the last result and a 7-cell strip (the last seven results); Add check; Run all. discover on the dataset.
 * JSON: the checks with their strips.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/checks/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/checks/present.php';
require_once dirname(__DIR__, 3) . '/app/features/catalog/queries.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$d = require_dataset($pdo, request_string('ref') !== '' ? request_string('ref') : request_string('dataset'), 'discover');
$id = (int) $d['id'];
$checks = find_checks($pdo, ['dataset' => $id]);
$strips = check_strips($pdo, array_map(static fn (array $c): int => (int) $c['check_id'], $checks));
$may = ['manage' => may_manage_checks(), 'run' => may_run_checks($d) && $checks !== []];
log_screen_view($pdo, 'checks', ['entity_type' => 'dataset', 'entity_id' => $id, 'dataset_id' => $id]);
if (wants_json()) {
    respond_screen(['dataset' => ['dataset_id' => $id, 'key' => $d['key']], 'may' => $may,
        'checks' => array_map(static fn (array $c): array => present_check($c, $strips[(int) $c['check_id']] ?? []), $checks)]);
}
$notices = ['created' => ['success', 'Check saved — it runs ' . 'as you chose; Run all to see it now.'], 'updated' => ['success', 'Check saved.'],
    'disabled' => ['secondary', 'Turned off.'], 'enabled' => ['success', 'Turned back on.'], 'deleted' => ['secondary', 'Deleted, with its results.'],
    'queued' => ['info', 'Running — the results land here in a moment.']];
render_screen('Checks — ' . $d['key'], view('checks/index.php', ['d' => $d, 'checks' => $checks, 'strips' => $strips, 'may' => $may,
    'notice' => dl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'quality', 'screen' => 'checks', 'entity' => 'dataset', 'recordId' => (string) $id, 'datasetKey' => (string) $d['key']]);
