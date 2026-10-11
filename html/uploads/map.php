<?php
declare(strict_types=1);
/**
 * /uploads/{id}/map — GET: the screen `upload-map` (uploads-loads.md); POST: the action `upload_map_confirm` (log upload.map — sandbox.upload,
 * a raw target loads.manage and write; people only).
 * GET: while the writer reads the file, "Reading the file…" (polling /uploads/{id}/sniff every 2 s); then the mapping — what was found (the
 * format, the encoding, the delimiter: corrected by "Read it again", with the header row 1–50), the sheet (a workbook), each source column →
 * its name, its type and the personal-data proposal with its confidence (keep or clear), the first 50 rows; the target (my sandbox, or for an
 * engineer a raw dataset — append or replace); the key; apply a saved mapping (?template=) or save this one. The uploader's own only (404
 * otherwise — an upload is nobody else's, an admin's neither). JSON: present_upload() with the sample (the uploader's own file).
 * POST: upload, target (sandbox | raw), target_dataset, name, mode, sheet, header_row, columns[i][name|type|personal_kind|skip] (or a JSON
 * object by source column), dedupe_key[], template, save_template → map_confirm(): the dataset, the load, the decided columns, the job.
 * Lands on the load's page, which follows the writer.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/uploads/write.php';
require_once dirname(__DIR__, 2) . '/app/features/uploads/present.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
$pdo = db();
$id = request_integer('id') ?? request_integer('upload') ?? (int) (req_val('upload') ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    dl_handler_begin();
    require_right('sandbox.upload');
    require_human();
    $u = $id > 0 ? find_upload($pdo, $id) : null;
    if ($u === null) {
        respond_not_found('No such upload.');
    }
    $columns = $_POST['columns'] ?? null;
    if (is_string($columns)) {
        $columns = json_decode($columns, true);
        if (!is_array($columns)) {
            dl_refuse_fields(['columns' => 'columns is an object of source column to name, type and personal kind.']);
        }
    }
    $tpl = null;
    if (($t = req_val('template')) !== null && $t !== '') {
        $tpl = find_template($pdo, $t);
        if ($tpl === null) {
            dl_refuse_fields(['template' => 'That saved mapping is not here.']);
        }
    }
    $target = req_val('target') ?? 'sandbox';
    $in = ['target' => $target, 'target_dataset' => req_val('target_dataset') ?: null,
           'name' => $target === 'raw' && (string) (req_val('raw_name') ?? '') !== '' ? (string) req_val('raw_name') : (req_val('name') ?? ''),
           'mode' => req_val('mode') ?? 'append', 'sheet' => req_val('sheet') ?: null, 'header_row' => req_has('header_row') ? (int) req_val('header_row') : null,
           'columns' => is_array($columns) ? $columns : null, 'dedupe_key' => request_list('dedupe_key') ?? null, 'template' => $tpl,
           'save_template' => req_val('save_template') ?? ''];
    if ($in['header_row'] === null) {
        unset($in['header_row']);
    }
    if ($in['dedupe_key'] === null) {
        unset($in['dedupe_key']);
    }
    if ($in['sheet'] === null) {
        unset($in['sheet']);
    }
    [$mapping, $errors] = map_validate($pdo, $u, $in);
    if ($errors !== []) {
        dl_refuse_fields($errors);
    }
    $r = dl_guard($pdo, static function () use ($pdo, $u, $mapping): array {
        try {
            return map_confirm($pdo, $u, $mapping, (int) current_member_id());
        } catch (FieldRefused $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            dl_refuse_fields($e->fields);
        }
    });
    dl_done('Loading into ' . $r['dataset_key'], $r['load_id'], '/loads/' . $r['load_id'], 'jobQueued',
        ['dataset_id' => $r['dataset_id'], 'dataset' => $r['dataset_key'], 'load_id' => $r['load_id'], 'job_id' => $r['job_id'], 'created' => $r['created'],
         'template_id' => $r['template_id']]);
}

require_login();
require_right('sandbox.upload');
$u = $id > 0 ? find_upload($pdo, $id) : null;
if ($u === null) {
    respond_not_found('No such upload.');
}
$sheet = request_string('sheet') !== '' ? request_string('sheet') : null;
$tpl = request_string('template') !== '' ? find_template($pdo, request_string('template')) : null;
log_screen_view($pdo, 'upload-map', ['entity_type' => 'upload', 'entity_id' => (int) $u['id']]);
if (wants_json()) {
    respond_screen(['upload' => present_upload($u, $sheet ?? ($tpl['mapping']['sheet'] ?? null)), 'may' => ['raw' => has_right('loads.manage')],
        'template' => $tpl === null ? null : ['template_id' => (int) $tpl['template_id'], 'name' => $tpl['name']]]);
}
$me = (int) current_member_id();
render_screen('Map ' . $u['filename'], view('upload/map.php', ['u' => $u, 'sheet' => $sheet ?? ($tpl['mapping']['sheet'] ?? null), 'tpl' => $tpl, 'me' => $me,
    'may_raw' => has_right('loads.manage'), 'templates' => find_templates($pdo, null, null, 50)]), ['activeNav' => 'upload', 'screen' => 'upload-map', 'entity' => 'upload', 'recordId' => (string) $u['id']]);
