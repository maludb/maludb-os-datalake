<?php
declare(strict_types=1);
/**
 * /access/grants/new?dataset=&zone=&tag=&principal_kind=&principal= (screen `grant-add`) — to grant. The owner on their own dataset; grants.manage
 * on anything (a zone or a tag only theirs — ★14). ?fragment=who answers the who-and-level part for a principal kind (the kind's select);
 * a dataset changed answers the page, whose row-filter field (the dataset's columns) the form takes. JSON: the values and the columns.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/access/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$admin = has_right('grants.manage');
$kind = request_string('principal_kind');
$kind = isset(PRINCIPAL_KINDS[$kind]) ? $kind : 'member';
$level = request_string('level');
$v = ['target' => 'dataset', 'dataset' => null, 'zone' => null, 'tag' => null, 'principal_kind' => $kind, 'principal' => request_integer('principal'),
      'level' => in_array($level, PRINCIPAL_LEVELS[$kind], true) ? $level : 'read', 'row_filter' => '', 'until' => '', 'reason' => ''];
if (request_string('fragment') === 'who') {
    echo view('access/partials/grant-who.php', ['v' => $v]);
    exit;
}
$d = null;
if (request_string('dataset') !== '') {
    $d = require_dataset($pdo, request_string('dataset'), 'discover');
    if (!may_grant_on($pdo, 'dataset', (int) $d['id'])) {
        refuse(403, 'Only the owner of ' . $d['key'] . ' or a Lake admin grants on it.');
    }
    $v['dataset'] = (int) $d['id'];
} elseif (!$admin && !db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM mcp_datasets WHERE owner_member_id = :me AND NOT is_stub AND status <> 'retired')", ['me' => (int) current_member_id()])) {
    refuse(403, 'Granting is a dataset owner\'s on their own datasets, or a Lake admin\'s.');
}
if ($admin && in_array(request_string('zone'), GRANT_ZONES, true)) {
    $v['target'] = 'zone';
    $v['zone'] = request_string('zone');
} elseif ($admin && request_integer('tag') !== null) {
    $v['target'] = 'tag';
    $v['tag'] = request_integer('tag');
}
$columns = $d === null ? [] : dataset_column_names($pdo, (int) $d['id']);
log_screen_view($pdo, 'grant-add', $d === null ? [] : ['entity_type' => 'dataset', 'entity_id' => (int) $d['id'], 'dataset_id' => (int) $d['id']]);
if (wants_json()) {
    respond_screen(['values' => $v, 'admin' => $admin, 'columns' => array_column($columns, 'name'), 'principal_kinds' => array_keys(PRINCIPAL_KINDS),
                    'levels' => PRINCIPAL_LEVELS, 'tokens' => ROW_FILTER_TOKENS]);
}
render_screen('Add a grant', view('access/grant-form.php', ['v' => $v, 'admin' => $admin, 'columns' => $columns, 'dataset_key' => $d['key'] ?? null, 'errors' => []]),
    ['activeNav' => 'access', 'screen' => 'grant-add', 'entity' => $d === null ? '' : 'dataset', 'recordId' => $d === null ? '' : (string) $d['id'], 'datasetKey' => $d['key'] ?? '']);
