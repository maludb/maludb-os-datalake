<?php
declare(strict_types=1);
/**
 * POST /upload/start — the first upload door (uploads-loads.md; DocCloud's protocol): filename, byte_size, sha256? (when the browser knows it),
 * upload? (an open session of mine for the same file — resume after a broken connection: the answer lists the parts already received) →
 * {upload_id, part_bytes, parts_total, parts_received, resumed}; 413 too_large, 422 empty. A browser without JavaScript posts the whole file
 * as `file` (multipart, ≤ 64 MB): start + part + finish in one go, then the mapping screen. Logged upload.start (the extension and the size —
 * never the name) and, for the one-request form, upload.finish.
 */
require_once dirname(__DIR__, 2) . '/app/features/uploads/door.php';
upload_door_begin();
$pdo = db();
$me = (int) current_member_id();
try {
    if (isset($_FILES['file']) && is_array($_FILES['file'])) {
        $f = $_FILES['file'];
        if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            throw new UploadRefused((int) ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 413 : 422, 'no_file', 'Choose a file to upload (at most 64 MB without JavaScript).');
        }
        $name = upload_safe_name((string) $f['name']);
        $r = upload_one($pdo, $me, $name, (string) $f['tmp_name']);
        log_activity($pdo, 'upload.start', 'upload', $r['upload_id'], ['after' => ['extension' => upload_ext($name), 'byte_size' => (int) $f['size'], 'parts' => 1, 'whole' => true]]);
        log_activity($pdo, 'upload.finish', 'upload', $r['upload_id'], ['after' => ['extension' => upload_ext($name), 'byte_size' => (int) $f['size'], 'deduped' => $r['deduped']]]);
        dl_done($r['deduped'] ? 'Already here — landed once' : 'Landed', $r['upload_id'], '/uploads/' . $r['upload_id'] . '/map', 'uploadChanged',
            ['upload_id' => $r['upload_id'], 'deduped' => $r['deduped']]);
    }
    $name = upload_safe_name((string) (req_val('filename') ?? ''));
    $resume = request_integer('upload');
    $s = upload_start($pdo, $me, $name, (int) (req_val('byte_size') ?? -1), req_val('sha256'), $resume !== null && $resume > 0 ? $resume : null);
} catch (UploadRefused $e) {
    upload_door_refused($e);
}
if (!$s['resumed']) {
    log_activity($pdo, 'upload.start', 'upload', $s['upload_id'], ['after' => ['extension' => upload_ext($name), 'byte_size' => (int) req_val('byte_size'), 'parts' => $s['parts_total']]]);
}
emit_action_status(true, ['did' => $s['resumed'] ? 'Resumed the upload' : 'Started the upload', 'record_id' => $s['upload_id']]);
json_response(['ok' => true] + $s);
