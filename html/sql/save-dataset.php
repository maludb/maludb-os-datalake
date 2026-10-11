<?php
declare(strict_types=1);
/**
 * Action `sandbox_save` (log sandbox.save — sandbox.save): the editor's "Save as dataset" — **name** (my sandbox's sandbox.m{me}_{name}; my own
 * of that name is replaced), **sql** (one SELECT), title, as_of. The SQL is dry-run first (a refusal is the service's sentence on `sql`); my
 * policy is compiled now and frozen into the writer's `sandbox_save` job, which makes the dataset from MY views (masked as I saw it), its
 * classification floor the highest of its inputs, its profile written (query-service-editor.md divergence 3). Lands on the dataset.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/editor/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/editor/write.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
dl_handler_begin();
require_right('sandbox.save');
$pdo = db();
$me = (int) current_member_id();
$name = strtolower(trim((string) (req_val('name') ?? '')));
$sql = (string) ($_POST['sql'] ?? '');
$asOf = trim((string) (req_val('as_of') ?? ''));
$asOf = $asOf === '' ? null : (ctype_digit($asOf) ? (int) $asOf : $asOf);
$errors = [];
if ($name === '') {
    $errors['name'] = 'Name the dataset.';
}
if (trim($sql) === '') {
    $errors['sql'] = 'There is no SQL to save.';
}
$title = dl_text('title', null, 200, 'The title', $errors);
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$out = dl_guard($pdo, static function () use ($pdo, $me, $name, $sql, $title, $asOf): array {
    try {
        return sandbox_save($pdo, $me, $name, $sql, $title, $asOf);
    } catch (FieldRefused $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        dl_refuse_fields($e->fields);
    }
});
dl_done('Saving ' . $out['key'] . ' — the writer is making it', $out['dataset_id'], dl_land('/datasets/' . $out['key'], 'saving'), 'datasetChanged',
    ['job_id' => $out['job_id'], 'key' => $out['key']]);
