<?php
declare(strict_types=1);
/**
 * /queries/new (?sql= from the editor) and /queries/{id}/edit (screen `query-edit`): title, the question it answers, description, the SQL,
 * the parameters (rows of name, type, default, required), shared — "saving re-checks the SQL" (the dry run's datasets shown on the query;
 * a refusal of the shape under the SQL). queries.save; an edit is the owner's alone (another's: copy it — /queries/new?copy={id}).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/saved_queries/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/saved_queries/present.php';
require_login();
require_right('queries.save');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('id');
$q = null;
if ($id !== null) {
    $q = find_saved_query($pdo, $id);
    if ($q === null || (int) $q['owner_member_id'] !== $me) {
        respond_not_found('No such saved query of yours.');
    }
} elseif (ctype_digit(request_string('copy'))) {
    $src = find_saved_query($pdo, (int) request_string('copy'));
    if ($src !== null) {
        $q = ['saved_query_id' => null, 'title' => 'Copy of ' . $src['title'], 'question' => $src['question'], 'description' => $src['description'],
              'sql' => $src['sql'], 'parameters' => $src['parameters'], 'shared' => false];
    }
}
$q ??= ['saved_query_id' => null, 'title' => '', 'question' => '', 'description' => '', 'sql' => request_string('sql'), 'parameters' => [], 'shared' => false];
log_screen_view($pdo, 'query-edit', $id === null ? [] : ['entity_type' => 'saved_query', 'entity_id' => $id]);
if (wants_json()) {
    respond_screen(['saved_query' => ['saved_query_id' => $q['saved_query_id'] === null ? null : (int) $q['saved_query_id'], 'title' => $q['title'],
        'question' => $q['question'], 'description' => $q['description'], 'sql' => $q['sql'], 'parameters' => $q['parameters'], 'shared' => (bool) $q['shared']],
        'types' => array_keys(PARAM_TYPES)]);
}
render_screen($id === null ? 'New saved query' : 'Edit ' . $q['title'], view('queries/form.php', ['q' => $q]),
    ['activeNav' => 'queries', 'screen' => 'query-edit', 'entity' => 'saved_query', 'recordId' => $id]);
