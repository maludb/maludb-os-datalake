<?php
declare(strict_types=1);
/**
 * /transforms/new?output_dataset= (screen `transform-add`) and /transforms/{id}/edit (screen `transform-edit`): name, the dataset it makes (the record picker over the curated, product
 * and my-sandbox datasets I may write that no transform makes yet), the SQL (a textarea with the keys I may read listed beside it), mode, schedule (on request | after its inputs change |
 * a cron), description. Saving runs the SQL through the lake as me: an input I may not read is refused by name; new SQL is a new version, refrozen as me. transforms.manage or
 * sandbox.transform; an edit is the owner's or a data engineer's. JSON: the values and the choices.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/transforms/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/transforms/present.php';
require_once dirname(__DIR__, 2) . '/app/features/transforms/write.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_any_right('transforms.manage|sandbox.transform');
$pdo = db();
$id = request_integer('id') ?? 0;
$t = null;
if ($id > 0) {
    $t = find_transform($pdo, $id) ?? respond_not_found('No such transform.');
    require_manage_transform($t);
}
$v = transform_form_values($t, ['output_dataset' => request_string('output_dataset')]);
$keys = readable_dataset_keys($pdo);
log_screen_view($pdo, $t === null ? 'transform-add' : 'transform-edit', $t === null ? [] : ['entity_type' => 'transform', 'entity_id' => $id, 'dataset_id' => (int) $t['output_dataset_id']]);
if (wants_json()) {
    respond_screen(['transform_id' => $t === null ? null : $id, 'values' => $v, 'modes' => array_keys(TRANSFORM_MODES), 'schedule_kinds' => ['manual', 'after_inputs', 'cron'],
        'readable' => array_map(static fn (array $d): array => ['dataset_id' => (int) $d['dataset_id'], 'key' => $d['key']], $keys), 'may_read_sql' => $t === null ? true : $t['may_read_sql']]);
}
render_screen($t === null ? 'New transform' : 'Edit ' . $t['name'], view('transforms/form.php', ['t' => $t, 'v' => $v, 'keys' => $keys]),
    ['activeNav' => 'transforms', 'screen' => $t === null ? 'transform-add' : 'transform-edit', 'entity' => 'transform', 'recordId' => $t === null ? null : (string) $id]);
