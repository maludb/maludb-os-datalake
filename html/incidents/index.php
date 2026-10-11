<?php
declare(strict_types=1);
/**
 * /incidents?status=&severity=&assignee=&dataset= (screen `incident-list`; contracts-quality.md) — incidents as a table: title, dataset, kind,
 * severity, status, assignee, opened, resolved; open ones first. mcp_incidents' wall (what the caller may discover). JSON: the rows.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/incidents/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/incidents/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$status = request_string('status', 'open');
$f = ['status' => $status === 'all' || in_array($status, INCIDENT_STATUSES, true) ? $status : 'open',
      'severity' => in_array(request_string('severity'), ['warn', 'error'], true) ? request_string('severity') : null,
      'assignee' => request_string('assignee') === 'me' ? 'me' : (request_integer('assignee') ?: null), 'dataset' => null];
$scope = null;
if (request_string('dataset') !== '') {
    $d = require_dataset($pdo, request_string('dataset'), 'discover');
    $f['dataset'] = (int) $d['id'];
    $scope = (string) $d['key'];
}
$page = max(1, min(1000, request_integer('page') ?? 1));
$result = find_incidents($pdo, $f, $page);
log_screen_view($pdo, 'incident-list', $f['dataset'] === null ? [] : ['entity_type' => 'dataset', 'entity_id' => $f['dataset'], 'dataset_id' => $f['dataset']]);
if (wants_json()) {
    respond_screen(['filters' => $f, 'page' => $result['page'], 'total' => $result['total'], 'incidents' => array_map('present_incident_row', $result['rows'])]);
}
render_screen('Incidents', view('incidents/index.php', ['f' => $f, 'result' => $result, 'scope' => $scope]), ['activeNav' => 'quality', 'screen' => 'incident-list']);
