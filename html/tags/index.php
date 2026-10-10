<?php
declare(strict_types=1);
/**
 * /tags (screen `tags`; catalog.md): the tag vocabulary — managed and free tags as chips with counts and colours; for catalog.edit, make one
 * and (?edit={id}) change a label, kind or colour, or archive (a tag carrying a grant warns: its grants stay until revoked). JSON: present_tag().
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$all = find_tags($pdo, null, null, true, 500);
$live = array_values(array_filter($all, static fn (array $t): bool => $t['archived_at'] === null));
$archived = array_values(array_filter($all, static fn (array $t): bool => $t['archived_at'] !== null));
$mayEdit = has_right('catalog.edit');
$grants = tags_with_grants($pdo);
$edit = null;
if ($mayEdit && ($e = request_integer('edit')) !== null) {
    $edit = find_tag($pdo, (string) $e);
    if ($edit !== null && $edit['archived_at'] !== null) {
        $edit = null;
    }
}
log_screen_view($pdo, 'tags');
if (wants_json()) {
    respond_screen(['rows' => array_map(static fn (array $t): array => present_tag($t) + ['grants' => $grants[(int) $t['tag_id']] ?? 0], $live),
        'archived' => array_map('present_tag', $archived), 'kinds' => TAG_KINDS, 'may' => ['edit' => $mayEdit]]);
}
$notices = ['created' => ['success', 'Tag made.'], 'updated' => ['success', 'Tag saved.'], 'archived' => ['success', 'Archived.'],
    'archived_grants' => ['warning', 'Archived. Grants on this tag stay until revoked.']];
render_screen('Tags', view('tags/index.php', ['tags' => $live, 'archived' => $archived, 'grants' => $grants, 'may_edit' => $mayEdit, 'edit' => $edit,
    'notice' => dl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'glossary', 'screen' => 'tags', 'entity' => 'tag', 'recordId' => $edit === null ? '' : (string) $edit['tag_id']]);
