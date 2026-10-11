<?php
declare(strict_types=1);
/**
 * /datasets/{id|key}/checks/new?kind=&column= (screen `check-add`) and /checks/{id}/edit (screen `check-edit`; contracts-quality.md) — the
 * templates as cards (not null, unique, accepted values, range, pattern, row count, freshness, referential, schema, custom SQL); the chosen
 * kind's fields: the column (a select of the dataset's live columns), its params (per kind — the referenced dataset and column prefilled from
 * the column's declared reference), the dimension (defaulted by kind), the severity, blocking (people; an agent's form hides it), when; on
 * edit, enable/disable and delete. checks.manage. JSON: the kinds, the columns, the check.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/checks/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/checks/present.php';
require_login();
require_right('checks.manage');
$pdo = db();
$check = null;
if (request_integer('id') !== null) {
    $probe = one_value($pdo, 'SELECT dataset_id FROM checks WHERE id = :id', ['id' => request_integer('id')]);
    if ($probe === null) {
        respond_not_found('No such check.');
    }
    $d = require_dataset($pdo, (string) $probe, 'discover');
    $check = find_check($pdo, (int) request_integer('id')) ?? respond_not_found('No such check.');
} else {
    $d = require_dataset($pdo, request_string('ref') !== '' ? request_string('ref') : request_string('dataset'), 'discover');
}
$id = (int) $d['id'];
$kind = $check['kind'] ?? (isset(CHECK_KINDS[request_string('kind')]) ? request_string('kind') : null);
$columns = live_columns($pdo, $id);
$column = $check['column_name'] ?? (request_string('column') !== '' && isset($columns[request_string('column')]) ? request_string('column') : null);
$ref = $kind === 'referential' && $column !== null && $check === null ? column_reference($pdo, $id, $column) : null;
$v = [
    'column' => $column, 'params' => $check['params'] ?? ($ref ?? []), 'dimension' => $check['dimension'] ?? ($kind === null ? null : CHECK_KINDS[$kind]['dimension']),
    'severity' => $check['severity'] ?? 'error', 'blocking' => (bool) ($check['blocking'] ?? false), 'run_when' => $check['run_when'] ?? 'on_load',
    'description' => $check['description'] ?? '',
];
log_screen_view($pdo, $check === null ? 'check-add' : 'check-edit', ['entity_type' => $check === null ? 'dataset' : 'check', 'entity_id' => $check === null ? $id : (int) $check['check_id'], 'dataset_id' => $id]);
if (wants_json()) {
    respond_screen(['dataset' => ['dataset_id' => $id, 'key' => $d['key']], 'kind' => $kind, 'columns' => array_keys($columns), 'values' => $v,
        'check' => $check === null ? null : present_check($check),
        'kinds' => array_map(static fn (string $k, array $t): array => ['kind' => $k, 'label' => $t['label'], 'dimension' => $t['dimension'], 'column' => $t['column'], 'what' => $t['what']],
            array_keys(CHECK_KINDS), CHECK_KINDS)]);
}
render_screen($check === null ? 'Add a check — ' . $d['key'] : 'Edit a check — ' . $d['key'], view('checks/form.php', ['d' => $d, 'check' => $check, 'kind' => $kind, 'columns' => $columns, 'v' => $v,
    'errors' => [], 'agent' => is_action_authed()]), ['activeNav' => 'quality', 'screen' => $check === null ? 'check-add' : 'check-edit', 'entity' => 'dataset', 'recordId' => (string) $id,
    'datasetKey' => (string) $d['key']]);
