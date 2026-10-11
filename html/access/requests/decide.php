<?php
declare(strict_types=1);
/**
 * Action `access_decide` (log access.decide — the dataset's owner or grants.manage, never the requester; confirm; pauses for an agent:
 * external_send): **access_request**, **approve** (yes or no), level (another level than asked), until (a date), note (a denial says why).
 * dl_access_decide makes the dataset grant on approval; the requester is told. Undo: grant_revoke.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/access/write.php';
dl_handler_begin();
$pdo = db();
$id = (int) (req_val('access_request') ?? 0);
$r = $id > 0 ? find_access_request($pdo, $id) : null;
if ($r === null) {
    respond_not_found('No such request.');
}
$me = (int) current_member_id();
if ((int) $r['requester_id'] === $me) {
    refuse(403, 'A request is decided by someone other than its requester.');
}
if ((int) ($r['owner_member_id'] ?? 0) !== $me && !has_right('grants.manage')) {
    refuse(403, 'Deciding access is the dataset\'s owner\'s or a Lake admin\'s.');
}
if ($r['status'] !== 'pending') {
    refuse(422, 'That request was already ' . $r['status'] . '.');
}
$errors = [];
if (!req_has('approve')) {
    $errors['approve'] = 'Approve or deny.';
}
$approve = dl_yes('approve');
$level = dl_choice('level', null, REQUEST_LEVELS, 'The level', $errors);
$note = dl_text('note', null, 2000, 'The note', $errors);
$until = req_val('until');
$until = $until === '' ? null : $until;
if ($until !== null && (($t = DateTimeImmutable::createFromFormat('!Y-m-d', $until)) === false || $t->format('Y-m-d') !== $until || $until <= date('Y-m-d'))) {
    $errors['until'] = 'Until is a date after today.';
}
if (!$approve && ($note === null || mb_strlen($note) < 3)) {
    $errors['note'] = 'A denial says why.';
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$untilTs = $until === null ? null : $until . 'T23:59:59' . (new DateTimeImmutable($until, new DateTimeZone(member_timezone())))->format('P');
$gid = dl_guard($pdo, static function () use ($pdo, $id, $approve, $level, $untilTs, $note, $r): ?int {
    $pdo->beginTransaction();
    $gid = request_decide($pdo, $id, $approve, $level, $untilTs, $note);
    log_activity($pdo, 'access.decide', 'access_request', $id, ['dataset_id' => (int) $r['dataset_id'],
        'before' => ['status' => 'pending'], 'after' => ['status' => $approve ? 'approved' : 'denied', 'level' => $approve ? ($level ?? $r['level']) : null,
        'grant_id' => $gid, 'until' => $untilTs, 'note_length' => $note === null ? 0 : mb_strlen($note)]]);
    $pdo->commit();
    return $gid;
});
dl_done(($approve ? 'Approved' : 'Denied') . ' — ' . ($r['requester_name'] ?? 'the requester') . ' told', $id, dl_land(return_path('/access/requests?box=to_decide'), $approve ? 'approved' : 'denied'),
    'accessChanged', ['grant_id' => $gid]);
