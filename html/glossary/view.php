<?php
declare(strict_types=1);
/**
 * /glossary/{id} (screen `term-view`; catalog.md): a term's definition, synonyms, category, steward, status; the columns that carry it —
 * of datasets the caller may discover only; Approve (a draft, catalog.edit, a person), Deprecate, Edit. JSON: present_term().
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/glossary/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/glossary/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$id = request_integer('id');
$t = $id === null ? null : find_term($pdo, $id);
if ($t === null) {
    respond_not_found('No such term.');
}
$columns = term_columns($pdo, (int) $t['term_id']);
$edit = has_right('catalog.edit');
$human = (current_member()['member_kind'] ?? '') === 'human';
$may = ['edit' => $edit, 'approve' => $edit && $human && $t['status'] === 'draft', 'deprecate' => $edit && $t['status'] !== 'deprecated'];
log_screen_view($pdo, 'term-view', ['entity_type' => 'glossary_term', 'entity_id' => (int) $t['term_id']]);
if (wants_json()) {
    respond_screen(present_term($t, $columns) + ['may' => $may]);
}
$notices = ['created' => ['success', 'Added as a draft — an engineer approves it.'], 'updated' => ['success', 'Saved.'], 'approved' => ['success', 'Approved.']];
render_screen($t['name'], view('glossary/view.php', ['t' => $t, 'columns' => $columns, 'children' => term_children($pdo, (int) $t['term_id']), 'may' => $may,
    'here' => here_url(), 'notice' => dl_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'glossary', 'screen' => 'term-view', 'entity' => 'glossary_term', 'recordId' => (string) $t['term_id']]);
