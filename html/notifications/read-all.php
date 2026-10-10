<?php
declare(strict_types=1);
/** Action `notifications_read_all` (log `notification.read_all`): every unread notice of mine read — `all` must say yes. Agents may (their own). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/write.php';
dl_handler_begin();
$pdo = db();
$me = (int) current_member_id();
if (!dl_yes('all')) {
    refuse(422, 'Say yes to mark every notice read.');
}
$n = dl_guard($pdo, static fn (): int => mark_notifications_read($pdo, $me, null));
log_activity($pdo, 'notification.read_all', 'notification', null, ['after' => ['count' => $n]]);
dl_done('Marked ' . $n . ' notice' . ($n === 1 ? '' : 's') . ' read', $n, '/notifications', 'notificationChanged');
