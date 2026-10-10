<?php
declare(strict_types=1);
/** Action `note_delete` (log note.delete — the note's author or catalog.edit; confirm): **note**. A note the caller may not see is nothing (404). No undo. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/write.php';
dl_handler_begin();
$pdo = db();
$given = (string) (req_val('note') ?? '');
$n = ctype_digit($given) && strlen($given) <= 18 ? find_note($pdo, (int) $given) : null;
if ($n === null) {
    respond_not_found('No such note.');
}
if ((int) $n['member_id'] !== (int) current_member_id() && !has_right('catalog.edit')) {
    refuse(403, 'Only the note\'s author or a data engineer may delete it.');
}
$kind = (string) $n['record_type'];
$rid = (int) $n['record_id'];
$datasetId = $kind === 'dataset' ? $rid : ($kind === 'incident' ? (int) one_value($pdo, 'SELECT dataset_id FROM mcp_incidents WHERE incident_id = :id', ['id' => $rid]) : null);
$key = $kind === 'dataset' ? one_value($pdo, 'SELECT key FROM mcp_datasets WHERE dataset_id = :id AND NOT is_stub', ['id' => $rid]) : null;
dl_guard($pdo, static function () use ($pdo, $n, $kind, $rid, $datasetId): void {
    $pdo->beginTransaction();
    note_delete($pdo, (int) $n['note_id']);
    log_activity($pdo, 'note.delete', 'note', (int) $n['note_id'], ['dataset_id' => $datasetId ?: null, 'before' => ['record_type' => $kind, 'record_id' => $rid,
        'author_member_id' => $n['member_id'] === null ? null : (int) $n['member_id'], 'length' => mb_strlen((string) $n['body'])]]);
    $pdo->commit();
});
dl_done('Deleted the note', (int) $n['note_id'], dl_land(note_record_url($kind, $rid, $key === null ? null : (string) $key), 'note_deleted'), 'noteChanged');
