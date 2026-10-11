<?php
declare(strict_types=1);
/**
 * Action `column_classify` (log column.classify — catalog.edit; confirm; pauses for an agent: external_send — a downgrade widens who sees values):
 * **dataset**, **column**, classification (public, internal, confidential or restricted), personal_kind (a kind — or none). Set by a person,
 * confirmed; the database keeps a personal column at least confidential and re-derives the dataset. Undo: column_classify with the old values.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/personal/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/personal/write.php';
dl_handler_begin();
require_right('catalog.edit');
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
$c = find_personal_column($pdo, (int) $d['id'], (string) (req_val('column') ?? '')) ?? respond_not_found('No such column.');
$errors = [];
$class = dl_choice('classification', null, DATASET_CLASSIFICATIONS, 'The classification', $errors);
$kind = null;
if (req_has('personal_kind')) {
    $k = (string) req_val('personal_kind');
    if ($k === '' || $k === 'none') {
        $kind = '';
    } elseif (in_array($k, personal_kinds($pdo), true)) {
        $kind = $k;
    } else {
        $errors['personal_kind'] = 'The personal kind is one of: ' . implode(', ', personal_kinds($pdo)) . ', or none.';
    }
}
if ($class === null && $kind === null && $errors === []) {
    $errors['classification'] = 'Set the classification, the personal kind, or both.';
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$cid = (int) $c['column_id'];
$me = (int) current_member_id();
$after = dl_guard($pdo, static function () use ($pdo, $cid, $class, $kind, $me, $d): array {
    $pdo->beginTransaction();
    $before = column_class_state($pdo, $cid);
    column_classify($pdo, $cid, $class, $kind, $me);
    $after = column_class_state($pdo, $cid);
    log_activity($pdo, 'column.classify', 'dataset_column', $cid, ['dataset_id' => (int) $d['id'], 'before' => ['classification' => $before['classification'], 'personal_kind' => $before['personal_kind']],
        'after' => ['classification' => $after['classification'], 'personal_kind' => $after['personal_kind']]]);
    $pdo->commit();
    return $after;
});
dl_done($c['name'] . ' is now ' . $after['classification'] . ($after['personal_kind'] !== null ? ' (' . str_replace('_', ' ', (string) $after['personal_kind']) . ')' : ''), $cid,
    dl_land(return_path('/datasets/' . (int) $d['id'] . '/columns/' . rawurlencode((string) $c['name'])), 'classified'), 'datasetChanged',
    ['classification' => $after['classification'], 'personal_kind' => $after['personal_kind']]);
