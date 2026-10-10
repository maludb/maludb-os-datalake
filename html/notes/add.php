<?php
declare(strict_types=1);
/**
 * Action `note_add` (log note.add — discover on the record's dataset): **kind** (dataset, incident or saved_query), **record** (its id),
 * **body** (up to 8,000 characters). A dataset's note needs discover on it (a stub is nothing); an incident's, discover on its dataset; a
 * saved query's, the query visible to the caller (mcp_saved_queries). The body is never logged — its length is.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/write.php';
dl_handler_begin();
$pdo = db();
$kind = (string) (req_val('kind') ?? '');
if (!in_array($kind, NOTE_KINDS, true)) {
    dl_refuse_fields(['kind' => 'A note is on a dataset, an incident or a saved query.']);
}
$record = (string) (req_val('record') ?? '');
$errors = [];
$body = dl_text('body', null, NOTE_MAX, 'The note', $errors, true);
if ($body === null && !isset($errors['body'])) {
    $errors['body'] = 'Write the note.';
}
$key = null;
if ($kind === 'dataset') {
    $d = require_dataset($pdo, $record, 'discover');
    [$rid, $datasetId, $key] = [(int) $d['id'], (int) $d['id'], (string) $d['key']];
} else {
    if (!ctype_digit($record) || strlen($record) > 18) {
        respond_not_found('Not found.');
    }
    $rid = (int) $record;
    $datasetId = dl_guard($pdo, static fn (): ?int => note_record_dataset($pdo, $kind, $rid));
    if ($datasetId !== null) {
        require_dataset($pdo, (string) $datasetId, 'discover');
    }
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$me = (int) current_member_id();
$noteId = dl_guard($pdo, static function () use ($pdo, $kind, $rid, $body, $me, $datasetId): int {
    $pdo->beginTransaction();
    $noteId = note_add($pdo, $kind, $rid, (string) $body, $me);
    log_activity($pdo, 'note.add', 'note', $noteId, ['dataset_id' => $datasetId, 'after' => ['record_type' => $kind, 'record_id' => $rid, 'length' => mb_strlen((string) $body)]]);
    $pdo->commit();
    return $noteId;
});
dl_done('Added a note', $noteId, dl_land(note_record_url($kind, $rid, $key), 'note_added', 'note-' . $noteId), 'noteChanged', ['record_type' => $kind, 'record_id' => $rid]);
