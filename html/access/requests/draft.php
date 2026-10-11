<?php
declare(strict_types=1);
/**
 * Action `access_request_draft` (log access.draft — access.request; an AGENT for the person it acts for): **dataset**, **level**, **reason**,
 * for (the person — the run's requester by default, and only them: K38's requested_by_member_id). The request is the person's (requester_id),
 * marked drafted_by_agent; they see it under Mine and may withdraw it. A duty (no person asked) drafts nothing; a person asks with the form.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/access/write.php';
dl_handler_begin();
require_right('access.request');
if ((current_member()['member_kind'] ?? '') !== 'agent') {
    refuse(403, 'Drafting is an agent\'s, for the person it acts for — ask with Request access.');
}
$person = lake_on_behalf_of();
if ($person === null) {
    refuse(403, 'Only a run a person asked for drafts a request — a duty drafts none.');
}
$pdo = db();
$agent = (int) current_member_id();
$errors = [];
$for = req_val('for');
if ($for !== null && $for !== '' && (int) $for !== $person) {
    $errors['for'] = 'An agent drafts only for the person it acts for in this run.';
}
$d = require_level($pdo, (string) (req_val('dataset') ?? ''), 'discover');   // the intersection: the agent and the person both may know it exists
$level = dl_choice('level', null, REQUEST_LEVELS, 'The level', $errors, false);
if ($level === null && !isset($errors['level'])) {
    $errors['level'] = 'Choose the level the person needs.';
}
$reason = dl_text('reason', null, 2000, 'The reason', $errors, true);
if (($reason === null && !isset($errors['reason'])) || ($reason !== null && mb_strlen($reason) < 3)) {
    $errors['reason'] = 'Say in a few words why they need it.';
}
$personLevel = (string) (one_value($pdo, 'SELECT level FROM dl_member_access(:d, :m)', ['d' => (int) $d['id'], 'm' => $person]) ?? 'none');
if ($level !== null && dl_level_rank($personLevel) >= dl_level_rank($level)) {
    $errors['level'] = 'They already ' . (DL_LEVEL_VERBS[$level] ?? $level) . ' ' . $d['key'] . '.';
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$id = dl_guard($pdo, static function () use ($pdo, $person, $agent, $d, $level, $reason): int {
    $pdo->beginTransaction();
    $id = request_create($pdo, $person, (int) $d['id'], (string) $level, (string) $reason, null, $agent);
    log_activity($pdo, 'access.draft', 'access_request', $id, ['dataset_id' => (int) $d['id'],
        'after' => ['dataset_id' => (int) $d['id'], 'level' => $level, 'for' => $person, 'reason_length' => mb_strlen((string) $reason)]]);
    $pdo->commit();
    return $id;
});
dl_done('Drafted a request for ' . $d['key'] . ' — the person sees it under Mine', $id, dl_land('/access/requests?box=mine', 'requested'), 'accessChanged');
