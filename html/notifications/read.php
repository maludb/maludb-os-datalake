<?php
declare(strict_types=1);
/** Action `notification_read` (log `notification.read`): one of my own notices read — or, with none named, every unread one (the manifest's "one — or none for all"). Agents may (their own). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/write.php';
dl_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('notification') ?? request_integer('id');
$n = dl_guard($pdo, static fn (): int => mark_notifications_read($pdo, $me, $id));
if ($id !== null && $n === 0 && one_value($pdo, 'SELECT 1 FROM notifications WHERE id = :i AND member_id = :m', ['i' => $id, 'm' => $me]) === null) {
    refuse(404, 'Not found.');
}
log_activity($pdo, 'notification.read', 'notification', $id, ['after' => ['count' => $n, 'all' => $id === null]]);
dl_done($id === null ? 'Marked ' . $n . ' notice' . ($n === 1 ? '' : 's') . ' read' : 'Marked the notice read', $id ?? $n, '/notifications', 'notificationChanged');
