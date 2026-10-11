<?php
declare(strict_types=1);
/**
 * /incidents/{id} (screen `incident-view`; contracts-quality.md) — phone-first: the title, the status line, the dataset (link), the check and
 * the result that opened it (value, threshold, rows failed, the failing keys — hashed when personal, shown at `read` by mcp_check_results), the
 * downstream datasets (those the caller may know; the rest counted), the Steward's triage first then the notes in order; Acknowledge · Assign
 * (the member picker) · Note · Resolve (resolution + root cause) · Dismiss (reason). The incident's dataset gates it (discover). JSON: the incident.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/incidents/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/incidents/present.php';
require_once dirname(__DIR__, 2) . '/app/features/checks/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$id = request_integer('id') ?? 0;
$probe = $id > 0 ? one_value($pdo, 'SELECT dataset_id FROM incidents WHERE id = :id', ['id' => $id]) : null;
if ($probe === null) {
    respond_not_found('No such incident.');
}
require_dataset($pdo, (string) $probe, 'discover');
$i = find_incident($pdo, $id) ?? respond_not_found('No such incident.');
$may = incident_may($i);
log_screen_view($pdo, 'incident-view', ['entity_type' => 'incident', 'entity_id' => $id, 'dataset_id' => (int) $i['dataset_id']]);
if (wants_json()) {
    respond_screen(['incident' => present_incident($i, $may)]);
}
$notices = ['acknowledged' => ['success', 'Acknowledged.'], 'assigned' => ['success', 'Assigned — they have been told.'], 'noted' => ['success', 'Note added.'],
            'resolved' => ['success', 'Resolved.'], 'dismissed' => ['secondary', 'Dismissed.']];
render_screen($i['title'], view('incidents/view.php', ['i' => $i, 'may' => $may, 'notice' => dl_notice($_GET['notice'] ?? null, $notices), 'back' => back_link()]),
    ['activeNav' => 'quality', 'screen' => 'incident-view', 'entity' => 'incident', 'recordId' => (string) $id, 'datasetKey' => (string) $i['key']]);
