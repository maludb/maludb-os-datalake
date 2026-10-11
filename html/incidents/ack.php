<?php
declare(strict_types=1);
/**
 * Action `incident_ack` (log incident.ack — incidents.manage, the dataset's steward or owner; the Steward agent through its grant of the
 * action): **incident** — a new incident acknowledged (someone is on it).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/incidents/write.php';
[$pdo, $i, $may] = incident_action_begin();
if ($i['status'] !== 'new') {
    refuse(422, 'Only a new incident is acknowledged — this one is ' . $i['status'] . '.');
}
if (!$may['ack']) {
    refuse(403, 'Acknowledging is the dataset\'s owner\'s, its steward\'s or an engineer\'s.');
}
$id = (int) $i['incident_id'];
dl_guard($pdo, static function () use ($pdo, $id, $i): void {
    $pdo->beginTransaction();
    incident_ack($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'incident.ack', 'incident', $id, ['dataset_id' => (int) $i['dataset_id'], 'before' => ['status' => 'new'], 'after' => ['status' => 'acknowledged']]);
    $pdo->commit();
});
dl_done('Acknowledged', $id, dl_land(return_path('/incidents/' . $id), 'acknowledged'), 'incidentChanged');
