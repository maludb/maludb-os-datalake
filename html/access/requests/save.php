<?php
declare(strict_types=1);
/**
 * Action `access_request_create` (log access.request — access.request, people): **dataset** (a stub's key or id is enough), **level** (discover,
 * read, read_unmasked or write), **reason** (3 to 2,000 characters), wanted_until (a date). One waiting request per person and dataset (the
 * database's unique index, said in words); the dataset's owner — else every holder of grants.manage — told. Undo: access_request_withdraw.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/access/write.php';
dl_handler_begin();
require_right('access.request');
require_human();
$pdo = db();
$d = require_level($pdo, (string) (req_val('dataset') ?? ''), 'discover');
$me = (int) current_member_id();
$errors = [];
$level = dl_choice('level', null, REQUEST_LEVELS, 'The level', $errors, false);
if ($level === null && !isset($errors['level'])) {
    $errors['level'] = 'Choose the level you need.';
}
$reason = dl_text('reason', null, 2000, 'The reason', $errors, true);
if (($reason === null && !isset($errors['reason'])) || ($reason !== null && mb_strlen($reason) < 3)) {
    $errors['reason'] = 'Say in a few words why you need it.';
}
$until = req_val('wanted_until');
$until = $until === '' ? null : $until;
if ($until !== null && (($t = DateTimeImmutable::createFromFormat('!Y-m-d', $until)) === false || $t->format('Y-m-d') !== $until || $until <= date('Y-m-d'))) {
    $errors['wanted_until'] = 'Wanted until is a date after today.';
}
if ($level !== null && dl_level_rank((string) $d['my_level']) >= dl_level_rank($level)) {
    $errors['level'] = 'You already ' . (DL_LEVEL_VERBS[$level] ?? $level) . ' ' . $d['key'] . '.';
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$id = dl_guard($pdo, static function () use ($pdo, $me, $d, $level, $reason, $until): int {
    $pdo->beginTransaction();
    $id = request_create($pdo, $me, (int) $d['id'], (string) $level, (string) $reason, $until, null);
    log_activity($pdo, 'access.request', 'access_request', $id, ['dataset_id' => (int) $d['id'],
        'after' => ['dataset_id' => (int) $d['id'], 'level' => $level, 'wanted_until' => $until, 'reason_length' => mb_strlen((string) $reason)]]);
    $pdo->commit();
    return $id;
});
dl_done('Asked to ' . (DL_LEVEL_VERBS[$level] ?? $level) . ' ' . $d['key'] . ' — its owner decides', $id, dl_land(return_path('/access/requests?box=mine'), 'requested'), 'accessChanged');
