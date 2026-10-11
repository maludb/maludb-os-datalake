<?php
declare(strict_types=1);
/**
 * Action `incident_note` (log incident.note — discover on its dataset): **incident**, **body** (up to 8,000 characters — names and counts,
 * never values), kind (note or triage — the Steward's triage is shown first). Logged with the body's length only.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/incidents/write.php';
[$pdo, $i, $may] = incident_action_begin();
$errors = [];
$body = dl_text('body', null, 8000, 'The note', $errors, true);
if ($body === null && !isset($errors['body'])) {
    $errors['body'] = 'The note is required.';
}
$kind = dl_choice('kind', 'note', ['note', 'triage'], 'The kind', $errors, false) ?? 'note';
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$id = (int) $i['incident_id'];
$nid = dl_guard($pdo, static function () use ($pdo, $id, $i, $body, $kind): int {
    $pdo->beginTransaction();
    $nid = incident_note($pdo, $id, (string) $body, $kind, (int) current_member_id());
    log_activity($pdo, 'incident.note', 'incident', $id, ['dataset_id' => (int) $i['dataset_id'], 'after' => ['note_id' => $nid, 'kind' => $kind, 'length' => mb_strlen((string) $body)]]);
    $pdo->commit();
    return $nid;
});
dl_done('Note added', $id, dl_land(return_path('/incidents/' . $id), 'noted', 'incident-note-' . $nid), 'incidentChanged', ['note_id' => $nid]);
