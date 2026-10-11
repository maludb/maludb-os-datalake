<?php
declare(strict_types=1);
/**
 * Action `incident_dismiss` (log incident.dismiss — incidents.manage; PEOPLE; confirm): **incident**, **reason** — it was no incident (a check
 * set too tight, a known gap). Logged with the reason's length.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/incidents/write.php';
[$pdo, $i, $may] = incident_action_begin();
require_human();
if (!in_array($i['status'], INCIDENT_OPEN, true)) {
    refuse(422, 'Only an open incident is dismissed — this one is ' . $i['status'] . '.');
}
if (!$may['dismiss']) {
    refuse(403, 'Dismissing an incident is an engineer\'s.');
}
$errors = [];
$reason = dl_text('reason', null, 2000, 'The reason', $errors, true);
if ($reason === null && !isset($errors['reason'])) {
    $errors['reason'] = 'Say why it is no incident.';           // left out is no reason either
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$id = (int) $i['incident_id'];
dl_guard($pdo, static function () use ($pdo, $id, $i, $reason): void {
    $pdo->beginTransaction();
    incident_dismiss($pdo, $id, (string) $reason, (int) current_member_id());
    log_activity($pdo, 'incident.dismiss', 'incident', $id, ['dataset_id' => (int) $i['dataset_id'], 'before' => ['status' => $i['status']],
        'after' => ['status' => 'dismissed', 'reason_length' => mb_strlen((string) $reason)]]);
    $pdo->commit();
});
dl_done('Dismissed', $id, dl_land(return_path('/incidents/' . $id), 'dismissed'), 'incidentChanged');
