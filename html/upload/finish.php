<?php
declare(strict_types=1);
/**
 * POST /upload/finish — upload: the parts assembled and hashed, the size and the claimed hash checked, the bytes judged (a program is
 * refused), landed once by its hash (`deduped` when these bytes were here already), the sniff queued for the writer (uploads-loads.md).
 * Answers {upload_id, status, deduped, map_url, job_id}; 422 missing_parts {missing} | size_mismatch | hash_mismatch | executable.
 * Logged upload.finish (the extension, the size, deduped).
 */
require_once dirname(__DIR__, 2) . '/app/features/uploads/door.php';
upload_door_begin();
$pdo = db();
$upload = request_integer('upload') ?? (int) (req_val('upload') ?? 0);
try {
    $s = $upload > 0 ? upload_session_of($pdo, $upload, (int) current_member_id()) : null;
    if ($s === null) {
        throw new UploadRefused(404, 'not_found', 'Not found.');
    }
    $r = upload_finish($pdo, $upload);
} catch (UploadRefused $e) {
    if ($e->reason === 'executable' && isset($s)) {
        log_activity($pdo, 'upload.refused', 'upload', $upload, ['after' => ['extension' => upload_ext((string) $s['filename']), 'code' => 'executable']]);
    }
    upload_door_refused($e);
}
log_activity($pdo, 'upload.finish', 'upload', $upload, ['after' => ['extension' => upload_ext((string) $s['filename']), 'byte_size' => (int) $s['byte_size'], 'deduped' => $r['deduped']]]);
dl_done($r['deduped'] ? 'Already here — landed once' : 'Landed', $upload, '/uploads/' . $upload . '/map', 'uploadChanged',
    ['upload_id' => $upload, 'status' => $r['status'], 'deduped' => $r['deduped'], 'map_url' => '/uploads/' . $upload . '/map', 'job_id' => $r['job_id']]);
