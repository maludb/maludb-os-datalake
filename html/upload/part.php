<?php
declare(strict_types=1);
/**
 * POST /upload/part?upload=&n= — one part's bytes as the raw body (application/octet-stream) or a multipart `part` (uploads-loads.md). The
 * uploader's own session; a part sent again replaces the first (the resume). Answers {part_no, parts_done, parts_total}. Not logged (the
 * start and the finish are).
 */
require_once dirname(__DIR__, 2) . '/app/features/uploads/door.php';
upload_door_begin();
$pdo = db();
$upload = request_integer('upload') ?? (int) (req_val('upload') ?? 0);
$n = request_integer('n') ?? (int) (req_val('n') ?? 0);
try {
    if ($upload <= 0 || upload_session_of($pdo, $upload, (int) current_member_id()) === null) {
        throw new UploadRefused(404, 'not_found', 'Not found.');
    }
    $tmp = request_bytes_to_file($upload, 'part') ?? throw new UploadRefused(422, 'no_bytes', 'Send the part\'s bytes as the body.');
    try {
        $r = upload_part($pdo, $upload, $n, $tmp);
    } finally {
        if (is_file($tmp) && str_contains($tmp, '/incoming-')) {
            @unlink($tmp);
        }
    }
} catch (UploadRefused $e) {
    upload_door_refused($e);
}
json_response(['ok' => true] + $r);
