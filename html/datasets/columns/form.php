<?php
declare(strict_types=1);
/**
 * /datasets/{id|key}/columns/{name|id}/edit (screen `column-edit`; catalog.md — the owner, the steward or an engineer, and discover): business
 * name, description, the glossary term, critical, the reference (dataset + column). ?ref_columns=1&references_dataset=<id> answers the
 * referenced dataset's live columns as the select (Pattern A, when the dataset picker changes — a dataset the caller may not discover
 * answers an empty select). JSON: the values.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/catalog/present.php';
require_once dirname(__DIR__, 3) . '/app/features/catalog/write.php';
require_login();
$pdo = db();
$gate = require_dataset($pdo, request_string('ref'), 'discover');
require_describe($gate);
$id = (int) $gate['id'];
if (request_string('ref_columns') === '1') {
    $rid = request_integer('references_dataset');
    $names = $rid !== null && db_bool($pdo, 'SELECT dl_can_discover(:id)', ['id' => $rid]) ? column_names_for_pick($pdo, $rid) : [];
    header('Vary: HX-Request');
    echo view('catalog/ref-columns.php', ['names' => $names, 'selected' => null]);
    exit;
}
$d = find_dataset($pdo, $id) ?? respond_not_found('No such dataset.');
$c = find_column($pdo, $id, request_string('column')) ?? respond_not_found('No such column.');
if ($c['removed_snapshot_id'] !== null) {
    refuse(422, 'That column was removed from the dataset; its description stays as it was.');
}
$v = column_current($pdo, (int) $c['column_id']);
$refColumns = $v['references_dataset_id'] !== null && db_bool($pdo, 'SELECT dl_can_discover(:id)', ['id' => $v['references_dataset_id']])
    ? column_names_for_pick($pdo, $v['references_dataset_id']) : [];
log_screen_view($pdo, 'column-edit', ['entity_type' => 'dataset', 'entity_id' => $id]);
if (wants_json()) {
    respond_screen(['dataset_id' => $id, 'column_id' => (int) $c['column_id'], 'name' => $c['name'], 'values' => $v, 'reference_columns' => $refColumns]);
}
render_screen('Describe ' . $c['name'], view('catalog/column-form.php', ['d' => $d, 'c' => $c, 'v' => $v, 'ref_columns' => $refColumns]),
    ['activeNav' => 'browse', 'screen' => 'column-edit', 'entity' => 'column', 'recordId' => (string) $c['column_id'], 'datasetKey' => $d['key']]);
