<?php
declare(strict_types=1);
/**
 * Action `grant_set` (log grant.set — the owner on their own dataset (dataset grants only), or grants.manage on anything; confirm; pauses for
 * an agent: external_send): **target** (dataset, zone or tag), dataset, zone (raw, curated or product), tag, **principal_kind** (member, group,
 * department, agent or everyone), principal, **level**, row_filter (a predicate over the dataset's columns with {member_id} {departments}
 * {groups} — CHECKED by the query service before it is saved, ★13; a dataset grant's only), until (a date), reason. The log names the
 * target, the principal, the level and whether rows are filtered — never the predicate. Undo: grant_revoke.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/access/write.php';
dl_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$target = dl_choice('target', null, ['dataset', 'zone', 'tag'], 'The target', $errors, false) ?? 'dataset';
$f = ['target_kind' => $target, 'dataset_id' => null, 'zone' => null, 'tag_id' => null];
$d = null;
if ($target === 'dataset') {
    $ref = (string) (req_val('dataset') ?? '');
    if ($ref === '') {
        dl_refuse_fields(['dataset' => 'Choose the dataset.']);
    }
    $d = require_dataset($pdo, $ref, 'discover');
    $f['dataset_id'] = (int) $d['id'];
    if (!may_grant_on($pdo, 'dataset', (int) $d['id'])) {
        refuse(403, 'Only the owner of ' . $d['key'] . ' or a Lake admin grants on it.');
    }
} else {
    if (!has_right('grants.manage')) {
        refuse(403, 'A grant on a zone or a tag is a Lake admin\'s (grants.manage) — an owner grants on their own datasets.');
    }
    if ($target === 'zone') {
        $f['zone'] = dl_choice('zone', null, GRANT_ZONES, 'The zone', $errors, false);
        if ($f['zone'] === null && !isset($errors['zone'])) {
            $errors['zone'] = 'Choose the zone (raw, curated or product — a sandbox is shared dataset by dataset).';
        }
    } else {
        $f['tag_id'] = dl_ref($pdo, 'tag', null, 'SELECT 1 FROM mcp_tags WHERE tag_id = :id AND archived_at IS NULL', 'tag', $errors, false);
    }
}
$kind = dl_choice('principal_kind', null, array_keys(PRINCIPAL_KINDS), 'Who', $errors, false);
$f['principal_kind'] = $kind ?? 'member';
if ($kind !== null && $kind !== 'everyone') {
    $pid = (int) (req_val('principal') ?? 0);
    if ($pid <= 0 || principal_label($pdo, $kind, $pid) === null) {
        $errors['principal'] = 'Choose ' . strtolower(PRINCIPAL_KINDS[$kind]) . ' from the list.';
    } else {
        $f['principal_id'] = $pid;
    }
}
$level = dl_choice('level', null, PRINCIPAL_LEVELS[$f['principal_kind']] ?? ACCESS_LEVELS, 'The level', $errors, false);
if ($level === null && !isset($errors['level'])) {
    $errors['level'] = 'Choose the level.';
}
$f['level'] = $level ?? 'read';
$rf = dl_text('row_filter', null, 2000, 'The row filter', $errors);
if ($rf !== null) {
    if ($target !== 'dataset') {
        $errors['row_filter'] = 'A row filter belongs to a grant on one dataset (its columns) — not to a zone or a tag.';
    } elseif (!in_array($f['level'], ['read', 'read_unmasked'], true)) {
        $errors['row_filter'] = 'A row filter narrows a read: choose read or read unmasked.';
    }
}
$until = req_val('until');
$until = $until === '' ? null : $until;
if ($until !== null && (($t = DateTimeImmutable::createFromFormat('!Y-m-d', $until)) === false || $t->format('Y-m-d') !== $until || $until <= date('Y-m-d'))) {
    $errors['until'] = 'Until is a date after today.';
}
$f['expires_at'] = $until === null ? null : $until . 'T23:59:59' . (new DateTimeImmutable($until, new DateTimeZone(member_timezone())))->format('P');
$f['reason'] = dl_text('reason', null, 2000, 'The reason', $errors);
if ($errors === [] && $rf !== null) {
    $check = row_filter_check($me, (string) $d['key'], $rf);                 // ★13: checked before it is saved — never saved unchecked
    if (!$check['ok']) {
        $errors['row_filter'] = (string) $check['error'];
    }
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$f['row_filter'] = $rf;
if (grant_duplicate($pdo, $f) !== null) {
    dl_refuse_fields(['principal' => 'That grant is already there.']);
}
$id = dl_guard($pdo, static function () use ($pdo, $f, $me, $d): int {
    $pdo->beginTransaction();
    $id = grant_set($pdo, $f, $me);
    log_activity($pdo, 'grant.set', 'grant', $id, ($d === null ? [] : ['dataset_id' => (int) $d['id']]) + ['after' => [
        'target_kind' => $f['target_kind'], 'dataset_id' => $f['dataset_id'], 'zone' => $f['zone'], 'tag_id' => $f['tag_id'], 'principal_kind' => $f['principal_kind'],
        'principal_id' => $f['principal_id'] ?? null, 'level' => $f['level'], 'row_filtered' => $f['row_filter'] !== null, 'expires_at' => $f['expires_at'],
        'reason_length' => $f['reason'] === null ? 0 : mb_strlen((string) $f['reason'])]]);
    $pdo->commit();
    return $id;
});
$land = $d !== null ? '/datasets/' . (int) $d['id'] . '?tab=access' : '/access/grants' . ($f['zone'] !== null ? '?zone=' . $f['zone'] : '?tag=' . (int) $f['tag_id']);
dl_done('Granted ' . level_words($f['level']) . ($d !== null ? ' on ' . $d['key'] : ''), $id, dl_land(return_path($land), 'granted'), 'accessChanged');
