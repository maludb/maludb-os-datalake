<?php
declare(strict_types=1);
/** /glossary/new (screen `term-add`, prefill `name`, `parent`) and /glossary/{id}/edit (screen `term-edit`) — catalog.edit. JSON: the values. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/glossary/queries.php';
require_login();
require_right('catalog.edit');
$pdo = db();
$id = request_integer('id');
$t = null;
if ($id === null) {
    $parent = request_integer('parent');
    $v = ['name' => mb_substr(trim(request_string('name')), 0, 120), 'definition' => '', 'synonyms' => [],
          'parent_id' => $parent !== null && find_term($pdo, $parent) !== null ? $parent : null, 'steward_member_id' => null];
} else {
    $t = find_term($pdo, $id) ?? respond_not_found('No such term.');
    $v = ['name' => $t['name'], 'definition' => $t['definition'], 'synonyms' => $t['synonyms'], 'parent_id' => $t['parent_id'] === null ? null : (int) $t['parent_id'],
          'steward_member_id' => $t['steward_member_id'] === null ? null : (int) $t['steward_member_id']];
}
$screen = $id === null ? 'term-add' : 'term-edit';
log_screen_view($pdo, $screen, $id === null ? [] : ['entity_type' => 'glossary_term', 'entity_id' => $id]);
if (wants_json()) {
    respond_screen(['term_id' => $id, 'values' => $v]);
}
render_screen($id === null ? 'New term' : 'Edit ' . $t['name'], view('glossary/form.php', ['t' => $t, 'v' => $v]),
    ['activeNav' => 'glossary', 'screen' => $screen, 'entity' => 'glossary_term', 'recordId' => $id === null ? '' : (string) $id]);
