<?php
declare(strict_types=1);
/**
 * Actions `check_create` (log check.create — checks.manage; an agent with the action granted — the Steward — makes non-blocking checks only:
 * dl_checks_guard refuses its blocking one) and `check_update` (log check.update — checks.manage): **dataset** (create) or **check** (update),
 * **kind** (create), column, params (an object, or the form's fields), **dimension**, severity, blocking, run_when, description. A custom SQL is
 * dry-run through the query service as the person first and may read only its own dataset; a referential check's dataset must be one the
 * person may discover, its column live. Undo: check_disable (a create), restore prior (an update).
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/checks/write.php';
dl_handler_begin();
$pdo = db();
$agent = is_action_authed() && (current_member()['member_kind'] ?? '') === 'agent';
$checkId = req_has('check') ? (int) req_val('check') : null;
$check = null;
if ($checkId !== null) {
    require_right('checks.manage');
    $probe = one_value($pdo, 'SELECT dataset_id FROM checks WHERE id = :id', ['id' => $checkId]);
    if ($probe === null) {
        respond_not_found('No such check.');
    }
    $d = require_dataset($pdo, (string) $probe, 'discover');
    $check = find_check($pdo, $checkId) ?? respond_not_found('No such check.');
} else {
    if (!has_right('checks.manage') && !$agent) {
        require_right('checks.manage');                  // an agent reaches here only through the kernel's grant of check_create
    }
    $d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
}
$id = (int) $d['id'];
$errors = [];
$kind = $check['kind'] ?? dl_choice('kind', null, array_keys(CHECK_KINDS), 'The kind', $errors, false);
if ($kind === null) {
    dl_refuse_fields($errors + ['kind' => 'Choose what the check asks.']);
}
$t = CHECK_KINDS[$kind];
$columns = live_columns($pdo, $id);
$column = $check['column_name'] ?? null;
if ($t['column']) {
    $column = req_has('column') ? (string) req_val('column') : $column;
    if ($column === null || $column === '') {
        $errors['column'] = 'Choose the column it checks.';
    } elseif (!isset($columns[$column])) {
        $errors['column'] = $d['key'] . ' has no column ' . $column . '.';
    }
} else {
    $column = null;
}
$params = req_has('params') || array_intersect(array_keys($_POST), ['max_failures', 'values', 'min', 'max', 'pattern', 'change_pct', 'max_age_hours', 'dataset_key', 'ref_column', 'sql']) !== []
    ? check_params_from_request($kind, $errors, $check['params'] ?? null) : ($check['params'] ?? check_params_from_request($kind, $errors));
$dimension = dl_choice('dimension', $check['dimension'] ?? $t['dimension'], CHECK_DIMENSIONS, 'The dimension', $errors, false) ?? $t['dimension'];
$severity = dl_choice('severity', $check['severity'] ?? 'error', ['warn', 'error'], 'The severity', $errors, false) ?? 'error';
$blocking = dl_yes('blocking', $check['blocking'] ?? false);
$when = dl_choice('run_when', $check['run_when'] ?? 'on_load', array_keys(CHECK_WHEN), 'When', $errors, false) ?? 'on_load';
$description = dl_text('description', $check['description'] ?? null, 2000, 'The reason', $errors);
if ($blocking && $severity !== 'error') {
    $errors['blocking'] = 'A blocking check is an error — set the severity to Error.';
}
if ($kind === 'referential' && !isset($errors['dataset_key'])) {
    $other = one_value($pdo, 'SELECT dataset_id FROM mcp_datasets WHERE key = :k AND NOT is_stub AND status <> \'retired\'', ['k' => (string) $params['dataset_key']]);
    if ($other === null) {
        $errors['dataset_key'] = 'There is no dataset ' . $params['dataset_key'] . ' you may see.';
    } elseif (!isset(live_columns($pdo, (int) $other)[(string) $params['column']])) {
        $errors['ref_column'] = $params['dataset_key'] . ' has no column ' . $params['column'] . '.';
    }
}
if ($kind === 'custom_sql' && !isset($errors['sql'])) {
    $a = lake_sql((string) $params['sql'], ['dry_run' => true]);
    if (($a['status'] ?? '') !== 'ok') {
        $errors['sql'] = lake_refusal_message($a);
    } elseif (array_values(array_unique((array) ($a['datasets'] ?? []))) !== [(string) $d['key']]) {
        $errors['sql'] = 'A custom check reads only its own dataset (' . $d['key'] . ').';
    }
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$f = ['dataset_id' => $id, 'kind' => $kind, 'column_name' => $column, 'params' => $params, 'dimension' => $dimension, 'severity' => $severity, 'blocking' => $blocking,
      'run_when' => $when, 'description' => $description, 'source' => $agent ? 'agent' : 'person'];
$me = (int) current_member_id();
$newId = dl_guard($pdo, static function () use ($pdo, $checkId, $f, $me, $check, $id): int {
    $pdo->beginTransaction();
    $cid = check_save($pdo, $checkId, $f, $me);
    $after = ['kind' => $f['kind'], 'column' => $f['column_name'], 'params' => $f['kind'] === 'custom_sql' ? ['sql_length' => mb_strlen((string) $f['params']['sql'])] : $f['params'],
              'dimension' => $f['dimension'], 'severity' => $f['severity'], 'blocking' => $f['blocking'], 'run_when' => $f['run_when'], 'source' => $f['source']];
    if ($check === null) {
        log_activity($pdo, 'check.create', 'check', $cid, ['dataset_id' => $id, 'after' => $after]);
    } else {
        $before = ['kind' => $check['kind'], 'column' => $check['column_name'], 'params' => $check['kind'] === 'custom_sql' ? ['sql_length' => mb_strlen((string) ($check['params']['sql'] ?? ''))] : $check['params'],
                   'dimension' => $check['dimension'], 'severity' => $check['severity'], 'blocking' => $check['blocking'], 'run_when' => $check['run_when'], 'source' => $check['source']];
        log_activity($pdo, 'check.update', 'check', $cid, ['dataset_id' => $id] + dl_diff($before, $after));
    }
    $pdo->commit();
    return $cid;
});
dl_done($check === null ? 'Check added' : 'Check saved', $newId, dl_land(return_path('/datasets/' . $d['key'] . '/checks'), $check === null ? 'created' : 'updated', 'check-row-' . $newId), 'checksChanged');
