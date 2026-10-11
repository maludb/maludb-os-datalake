<?php
declare(strict_types=1);
/**
 * Action `upload_discard` (log upload.discard — own; people): **upload** — an upload no load used: its parts go, its session expires and its
 * sample is dropped; its landing blob, referenced by nothing, goes with the landing window (the writer's, slice 8). A loaded file is refused:
 * roll its load back instead (record_id = the load).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/uploads/write.php';
dl_handler_begin();
require_right('sandbox.upload');
require_human();
$pdo = db();
$id = (int) (req_val('upload') ?? 0);
$u = $id > 0 ? find_upload($pdo, $id) : null;
if ($u === null) {
    respond_not_found('No such upload.');
}
dl_guard($pdo, static function () use ($pdo, $id, $u): void {
    $pdo->beginTransaction();
    upload_discard($pdo, $id);
    log_activity($pdo, 'upload.discard', 'upload', $id, ['after' => ['extension' => upload_ext((string) $u['filename']), 'byte_size' => (int) $u['byte_size']]]);
    $pdo->commit();
});
dl_done('Discarded ' . $u['filename'], $id, dl_land('/upload', 'discarded'), 'uploadChanged');
