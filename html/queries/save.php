<?php
declare(strict_types=1);
/**
 * Action `saved_query_save` (log saved_query.save — queries.save): **title**, **sql**, question, description, parameters (a list of name,
 * type, default, required — a form's rows or an agent's JSON), shared (yes or no), saved_query (to update MY OWN — its badge drops by itself
 * when the SQL changes). The SQL is dry-run first through the one door: one the allow-list refuses is not saved (the service's sentence on
 * `sql`); one the policy refuses today is saved with the refusal returned. Logged with the title and the dataset count — never the SQL.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/editor/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/saved_queries/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/saved_queries/write.php';
dl_handler_begin();
require_right('queries.save');
$pdo = db();
$me = (int) current_member_id();
$id = req_has('saved_query') && (string) req_val('saved_query') !== '' ? (int) req_val('saved_query') : null;
$cur = null;
if ($id !== null) {
    $cur = find_saved_query($pdo, $id);
    if ($cur === null || (int) $cur['owner_member_id'] !== $me) {
        respond_not_found('No such saved query of yours — copy another\'s to change it.');
    }
}
$errors = [];
$f = [];
$f['title'] = dl_text('title', $cur['title'] ?? null, 200, 'The title', $errors, true);
if ($f['title'] === null && !isset($errors['title'])) {
    $errors['title'] = 'The title is required.';
}
$f['question'] = dl_text('question', $cur['question'] ?? null, 500, 'The question', $errors);
$f['description'] = dl_text('description', $cur['description'] ?? null, 4000, 'The description', $errors);
$sql = array_key_exists('sql', $_POST) && !is_array($_POST['sql']) ? trim((string) $_POST['sql']) : ($cur['sql'] ?? '');
if ($sql === '') {
    $errors['sql'] = 'There is no SQL to save.';
} elseif (mb_strlen($sql) > 20000) {
    $errors['sql'] = 'A saved query is at most 20,000 characters.';
}
$f['sql'] = $sql;
$f['parameters'] = saved_query_params_from_request($errors) ?? ($cur['parameters'] ?? []);
$f['shared'] = dl_yes('shared', $cur === null ? false : (bool) $cur['shared']);
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$out = dl_guard($pdo, static function () use ($pdo, $id, $f, $me, $cur): array {
    try {
        $pdo->beginTransaction();
        $out = saved_query_save($pdo, $id, $f, $me);
    } catch (FieldRefused $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        dl_refuse_fields($e->fields);
    }
    $after = ['title' => $f['title'], 'datasets' => count($out['datasets']), 'shared' => $f['shared'], 'parameters' => count($f['parameters']),
              'refused_today' => $out['refusal']['rule'] ?? null];
    if ($cur !== null) {
        $after['sql_changed'] = hash('sha256', $f['sql']) !== (string) $cur['sql_sha256'];
    }
    log_activity($pdo, 'saved_query.save', 'saved_query', $out['id'], ['after' => $after, 'before' => $cur === null ? null : ['title' => $cur['title'], 'shared' => (bool) $cur['shared']]]);
    $pdo->commit();
    return $out;
});
dl_done('Saved ' . $f['title'], $out['id'], dl_land('/queries/' . $out['id'], $out['refusal'] === null ? ($id === null ? 'created' : 'saved') : 'refused'), 'savedQueryChanged',
    ['datasets' => $out['datasets'], 'refusal' => $out['refusal']]);
