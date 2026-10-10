<?php
declare(strict_types=1);
/**
 * Actions `tag_create` (no `tag`; log tag.create) and `tag_update` (`tag` + label, kind, colour; log tag.update) — catalog.edit: **label**
 * (up to 80), slug (made from the label when omitted; never changes), kind (managed or free — managed by default here), colour (#rrggbb).
 * The slug's form and its uniqueness are the database's.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/write.php';
dl_handler_begin();
require_right('catalog.edit');
$pdo = db();
$me = (int) current_member_id();
$given = (string) (req_val('tag') ?? '');
$t = $given === '' ? null : (find_tag($pdo, $given) ?? respond_not_found('No such tag.'));
if ($t !== null && $t['archived_at'] !== null) {
    refuse(422, 'The tag ' . $t['label'] . ' is archived.');
}
$errors = [];
$cur = $t === null ? ['label' => null, 'kind' => 'managed', 'colour' => null] : ['label' => $t['label'], 'kind' => $t['kind'], 'colour' => $t['colour']];
$f = [];
$f['label'] = dl_text('label', $cur['label'], 80, 'The label', $errors, true);
if ($f['label'] === null && !isset($errors['label'])) {
    $errors['label'] = 'The label is required.';
}
$f['kind'] = dl_choice('kind', $cur['kind'], array_keys(TAG_KINDS), 'The kind', $errors, false) ?? $cur['kind'];
$colour = req_has('colour') ? strtolower((string) req_val('colour')) : $cur['colour'];
if ($colour !== null && $colour !== '' && !preg_match('/^#[0-9a-f]{6}$/', $colour)) {
    $errors['colour'] = 'A colour is #rrggbb.';
}
$f['colour'] = $colour === '' ? null : $colour;
if ($t === null) {
    $slug = req_has('slug') && (string) req_val('slug') !== '' ? (string) req_val('slug') : tag_slug((string) $f['label']);
    if (!preg_match(TAG_SLUG_RE, $slug)) {
        $errors[req_has('slug') ? 'slug' : 'label'] = 'A slug is lowercase letters, digits, - and _ (at most 50).';
    } elseif (find_tag($pdo, $slug) !== null) {
        $errors[req_has('slug') ? 'slug' : 'label'] = 'There is already a tag #' . $slug . '.';
    }
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
if ($t === null) {
    $newId = dl_guard($pdo, static function () use ($pdo, $f, $slug, $me): int {
        $pdo->beginTransaction();
        $newId = tag_save($pdo, null, $f + ['slug' => $slug], $me);
        log_activity($pdo, 'tag.create', 'tag', $newId, ['after' => ['slug' => $slug, 'label' => $f['label'], 'kind' => $f['kind'], 'colour' => $f['colour']]]);
        $pdo->commit();
        return $newId;
    });
    dl_done('Made the tag ' . $f['label'], $newId, dl_land('/tags', 'created'), 'tagChanged', ['slug' => $slug]);
}
$id = (int) $t['tag_id'];
$diff = dl_diff($cur, $f);
if ($diff['after'] !== []) {
    dl_guard($pdo, static function () use ($pdo, $id, $diff, $me, $t): void {
        $pdo->beginTransaction();
        tag_save($pdo, $id, $diff['after'], $me);
        log_activity($pdo, 'tag.update', 'tag', $id, ['before' => $diff['before'], 'after' => $diff['after'] + ['slug' => $t['slug']]]);
        $pdo->commit();
    });
}
dl_done($diff['after'] === [] ? 'Nothing to change' : 'Saved the tag ' . $f['label'], $id, dl_land('/tags', 'updated'), 'tagChanged');
