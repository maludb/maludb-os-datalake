<?php
declare(strict_types=1);
/**
 * Action `dataset_deprecate` (log dataset.deprecate — the owner or an engineer, and discover; confirm): **dataset**, **note** (why, and what
 * to use instead — up to 2,000 characters), successor (a dataset the caller may discover, not itself). Status deprecated; the followers who
 * asked to hear of it are told (dl_notify — the wall per follower); its card greys with "use {successor}". Undo: dataset_update status active.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/write.php';
dl_handler_begin();
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
if ($d['my_level'] !== 'own' && !has_right('catalog.edit')) {
    refuse(403, 'Only the owner of ' . $d['key'] . ' or a data engineer may deprecate it.');
}
$id = (int) $d['id'];
$errors = [];
$note = dl_text('note', null, 2000, 'The note', $errors, true);
if ($note === null && !isset($errors['note'])) {
    $errors['note'] = 'Say why, and what to use instead.';
}
$successor = null;
$successorKey = null;
$given = trim((string) (req_val('successor') ?? ''));
if ($given !== '' && $given !== '0') {
    $s = dataset_by_ref($pdo, $given);
    $sid = $s === null ? null : (int) $s['id'];
    if ($sid === null || !db_bool($pdo, 'SELECT dl_can_discover(:id)', ['id' => $sid])) {
        $errors['successor'] = 'Choose the successor from the datasets you may see.';
    } elseif ($sid === $id) {
        $errors['successor'] = 'A dataset is not its own successor.';
    } else {
        [$successor, $successorKey] = [$sid, (string) $s['key']];
    }
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$me = (int) current_member_id();
$told = dl_guard($pdo, static function () use ($pdo, $id, $d, $note, $successor, $successorKey, $me): int {
    $pdo->beginTransaction();
    dataset_deprecate($pdo, $id, (string) $note, $successor);
    $told = notify_followers($pdo, $id, 'deprecated', $d['key'] . ' is deprecated', $successorKey === null ? mb_substr((string) $note, 0, 300) : 'Use ' . $successorKey . ' instead. ' . mb_substr((string) $note, 0, 300), $me);
    log_activity($pdo, 'dataset.deprecate', 'dataset', $id, ['before' => ['status' => $d['status']], 'after' => ['status' => 'deprecated', 'successor_id' => $successor,
        'successor_key' => $successorKey, 'note_length' => mb_strlen((string) $note), 'followers_told' => $told]]);
    $pdo->commit();
    return $told;
});
dl_done('Deprecated ' . $d['key'] . ($told > 0 ? ' — ' . $told . ' follower' . ($told === 1 ? '' : 's') . ' told' : ''), $id, dl_land('/datasets/' . $id, 'deprecated'), 'datasetChanged',
    ['followers_told' => $told]);
