<?php
declare(strict_types=1);
/**
 * Action `incident_assign` (log incident.assign — incidents.manage or the dataset's owner): **incident**, **assignee** (a member — the record
 * picker). The assignee is told (incident_assigned); a new incident is acknowledged by being assigned. Undo: incident_assign (to someone else).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/incidents/write.php';
[$pdo, $i, $may] = incident_action_begin();
if (!in_array($i['status'], INCIDENT_OPEN, true)) {
    refuse(422, 'A ' . $i['status'] . ' incident is not assigned.');
}
if (!$may['assign']) {
    refuse(403, 'Assigning is the dataset\'s owner\'s or an engineer\'s.');
}
$errors = [];
$assignee = dl_ref($pdo, 'assignee', null, "SELECT 1 FROM mcp_members WHERE member_id = :id AND status = 'active'", 'the assignee', $errors, false);
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$id = (int) $i['incident_id'];
dl_guard($pdo, static function () use ($pdo, $id, $i, $assignee): void {
    $pdo->beginTransaction();
    incident_assign($pdo, $id, (int) $assignee, (int) current_member_id());
    log_activity($pdo, 'incident.assign', 'incident', $id, ['dataset_id' => (int) $i['dataset_id'],
        'before' => ['assignee_member_id' => $i['assignee_member_id'] === null ? null : (int) $i['assignee_member_id']], 'after' => ['assignee_member_id' => (int) $assignee]]);
    $pdo->commit();
});
dl_done('Assigned', $id, dl_land(return_path('/incidents/' . $id), 'assigned'), 'incidentChanged', ['assignee' => (int) $assignee]);
