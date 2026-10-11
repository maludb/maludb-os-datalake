<?php
declare(strict_types=1);
/**
 * GET /exports/{id}/download — the door (query-service-editor.md): my own ready export, not expired — streamed as an attachment
 * (Content-Disposition: attachment, X-Content-Type-Options: nosniff), the session released before the bytes; logged `export.download`.
 * Anyone else's, an expired or a deleted one: 404, the same nothing.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/queries.php';
require_login();
require_right('export.own');
$pdo = db();
$id = (int) (request_integer('id') ?? 0);
$f = $id > 0 ? export_file($pdo, (int) current_member_id(), $id) : null;
if ($f === null) {
    respond_not_found('No such export — it may have expired.');
}
log_activity($pdo, 'export.download', 'export', $id, ['dataset_id' => $f['dataset_id'], 'after' => ['format' => $f['format'], 'bytes' => filesize($f['path'])]]);
$pdo->prepare('UPDATE exports SET downloaded_at = now() WHERE id = :id')->execute(['id' => $id]);
session_write_close();
header('Content-Type: ' . $f['mime']);
header('Content-Disposition: attachment; filename="' . $f['filename'] . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($f['path']));
header('Cache-Control: private, no-store');
readfile($f['path']);
