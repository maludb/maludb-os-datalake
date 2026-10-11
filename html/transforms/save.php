<?php
declare(strict_types=1);
/**
 * Action `transform_save` (no `transform`: log transform.save — creates; with `transform`: changes it, the new SQL a new version refrozen as the saver) —
 * transforms.manage (an output in curated or product) or sandbox.transform (an output in the caller's own sandbox): **name** (lower case, digits, underscores),
 * **output_dataset** (an id or a key: curated, product or my own sandbox — never raw — that no other transform makes), **sql** (one SELECT over dataset keys; left
 * out or empty on a change = kept), mode (replace | incremental_merge — row-wise SQL only, the writer says so when it is not), schedule (a cron expression such as
 * `0 6 * * *`, or `after_inputs`, or empty: on request — the form's schedule_kind + cron say the same), description. THE CRUD EXEMPLAR'S ORDER (slice 1) with slice 3's
 * dry run: dl_handler_begin() → the right → the fields → the SQL through the one door as the saver (the inputs come from the parse; an input the saver may not
 * read is refused with its key named) → the row and dl_transform_freeze() in ONE transaction → log_activity() — the names, inputs and counts, NEVER the SQL — → dl_done().
 * An agent saves a transform in a duty run (it freezes the agent's own level); in a person's chat turn it is refused — the frozen level is the OWNER's alone, and the
 * intersection (K38) cannot be frozen into it.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/transforms/write.php';
require_once dirname(__DIR__, 2) . '/app/features/transforms/present.php';
dl_handler_begin();
require_any_right('transforms.manage|sandbox.transform');
if (lake_on_behalf_of() !== null || lake_ceiling() !== null) {
    refuse(403, 'A transform is frozen at its owner\'s own level — an agent saves one in its own duty, not in a person\'s chat turn. Ask a person to save it.');
}
$pdo = db();
$me = (int) current_member_id();
$id = req_has('transform') && (string) req_val('transform') !== '' ? (int) req_val('transform') : null;
$cur = null;
if ($id !== null) {
    $cur = find_transform($pdo, $id) ?? respond_not_found('No such transform.');
    require_manage_transform($cur);
}
$errors = [];
$name = req_has('name') ? strtolower((string) req_val('name')) : (string) ($cur['name'] ?? '');
if ($name === '') {
    $errors['name'] = 'The name is required.';
} elseif (!preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name)) {
    $errors['name'] = 'The name is lower-case letters, digits and underscores, starting with a letter (at most 63).';
}
$output = req_has('output_dataset') ? (string) req_val('output_dataset') : ($cur === null ? '' : (string) $cur['output_dataset_id']);
if ($output === '') {
    $errors['output_dataset'] = 'Choose the dataset the transform makes.';
}
$sql = req_has('sql') && !is_array($_POST['sql']) ? trim((string) $_POST['sql']) : null;
if ($sql === '' && $cur !== null) {
    $sql = null;                                                       // an emptied SQL on a change keeps the SQL (the form hides it from someone who may not read every input)
}
if (($sql === null || $sql === '') && $cur === null) {
    $errors['sql'] = 'The SQL is required — one SELECT over dataset keys.';
} elseif ($sql !== null && mb_strlen($sql) > 50000) {
    $errors['sql'] = 'A transform\'s SQL is at most 50,000 characters.';
}
$f = ['name' => $name, 'output_dataset' => $output, 'sql' => $sql,
      'description' => dl_text('description', $cur['description'] ?? null, 4000, 'The description', $errors),
      'mode' => dl_choice('mode', $cur['mode'] ?? 'replace', ['replace', 'incremental_merge'], 'The mode', $errors, false) ?? 'replace',
      'schedule' => transform_schedule_from_request($cur['schedule'] ?? null, $errors)];
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$out = dl_guard($pdo, static function () use ($pdo, $id, $f, $me, $cur): array {
    $pdo->beginTransaction();
    try {
        $out = transform_save($pdo, $id, $f, $me);
    } catch (FieldRefused $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        dl_refuse_fields($e->fields);
    }
    $outId = (int) one_value($pdo, 'SELECT id FROM datasets WHERE key = :k', ['k' => $out['output']]);
    log_activity($pdo, 'transform.save', 'transform', $out['id'], ['dataset_id' => $outId, 'after' => ['transform_id' => $out['id'], 'version' => $out['version'], 'output' => $out['output'],
        'inputs' => $out['inputs'], 'mode' => $f['mode'], 'schedule' => $f['schedule'], 'refrozen' => $out['refrozen']],
        'before' => $cur === null ? null : ['version' => (int) $cur['version'], 'mode' => $cur['mode'], 'schedule' => $cur['schedule']]]);
    $pdo->commit();
    return $out;
});
dl_done('Saved ' . $f['name'], $out['id'], dl_land('/transforms/' . $out['id'], $out['created'] ? 'created' : ($out['refrozen'] ? 'refrozen' : 'saved')), 'transformChanged',
    ['version' => $out['version'], 'inputs' => $out['inputs'], 'refrozen' => $out['refrozen']]);
