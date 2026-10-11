<?php
declare(strict_types=1);
/**
 * /upload (screen `upload`; uploads-loads.md): the drop zone — many files at once, each sent in parts of 8 MB by assets/js/upload.js (resumable:
 * a broken connection carries on from the part it stopped at), its row showing the parts' progress, the hash check, "Already here" when the
 * bytes landed before, then "Map it". Without JavaScript the same form posts one file whole (≤ 64 MB). Below: my last uploads. sandbox.upload.
 * JSON: my uploads (no sample), the part size and the ceiling.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/uploads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/uploads/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_right('sandbox.upload');
$pdo = db();
$me = (int) current_member_id();
$mine = my_uploads($pdo, $me, 20);
$partBytes = (int) one_value($pdo, "SELECT dl_setting_int('upload_part_bytes')");
$maxBytes = (int) one_value($pdo, "SELECT dl_setting_int('max_upload_bytes')");
log_screen_view($pdo, 'upload');
if (wants_json()) {
    respond_screen(['uploads' => array_map('present_upload_row', $mine), 'part_bytes' => $partBytes, 'max_bytes' => $maxBytes,
        'formats' => ['csv', 'tsv', 'txt', 'xlsx', 'xlsm', 'xls', 'ods', 'json', 'ndjson', 'jsonl', 'parquet']]);
}
$notices = ['discarded' => ['success', 'Discarded. Nothing of it was loaded.']];
render_screen('Upload', view('upload/index.php', ['mine' => $mine, 'part_bytes' => $partBytes, 'max_bytes' => $maxBytes,
    'notice' => dl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'upload', 'screen' => 'upload']);
