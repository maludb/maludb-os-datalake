<?php
declare(strict_types=1);
/**
 * Action `incident_resolve` (log incident.resolve — incidents.manage or the dataset's owner; PEOPLE: a resolution is a person's decision; an
 * agent's call is refused; confirm): **incident**, **resolution**, root_cause. (A passing run resolves an incident by itself — the database's.)
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/incidents/write.php';
[$pdo, $i, $may] = incident_action_begin();
require_human();
if (!in_array($i['status'], INCIDENT_OPEN, true)) {
    refuse(422, 'Only an open incident is resolved — this one is ' . $i['status'] . '.');
}
if (!$may['resolve']) {
    refuse(403, 'Resolving is the dataset\'s owner\'s or an engineer\'s.');
}
$errors = [];
$resolution = dl_text('resolution', null, 4000, 'What was done', $errors, true);
if ($resolution === null && !isset($errors['resolution'])) {
    $errors['resolution'] = 'Say what was done.';
}
$cause = dl_text('root_cause', null, 2000, 'The root cause', $errors);
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$id = (int) $i['incident_id'];
dl_guard($pdo, static function () use ($pdo, $id, $i, $resolution, $cause): void {
    $pdo->beginTransaction();
    incident_resolve($pdo, $id, (string) $resolution, $cause, (int) current_member_id());
    log_activity($pdo, 'incident.resolve', 'incident', $id, ['dataset_id' => (int) $i['dataset_id'], 'before' => ['status' => $i['status']],
        'after' => ['status' => 'resolved', 'resolution_length' => mb_strlen((string) $resolution), 'root_cause' => $cause !== null]]);
    $pdo->commit();
});
dl_done('Resolved', $id, dl_land(return_path('/incidents/' . $id), 'resolved'), 'incidentChanged');
