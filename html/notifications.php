<?php
declare(strict_types=1);
/** /notifications — my notices, unread first (screen `notifications`); ?unread=1 only the unread; ?count=1 the bell's own partial (Pattern A). */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/settings/queries.php';
require_once dirname(__DIR__) . '/app/features/settings/present.php';
require_login();
$pdo = db();
$me = (int) current_member_id();
if (request_string('count') === '1') {
    header('Vary: HX-Request');
    echo view('notifications/partials/bell.php', ['unread' => bell_count($pdo, $me)]);
    exit;
}
$unreadOnly = request_string('unread') === '1';
$rows = find_my_notifications($pdo, $me, $unreadOnly, 100);
log_screen_view($pdo, 'notifications');
if (wants_json()) {
    respond_screen(['unread' => bell_count($pdo, $me), 'notifications' => array_map('present_notification', $rows)]);
}
render_screen('Notifications', view('notifications/page.php', ['rows' => $rows, 'tz' => member_timezone(), 'unreadOnly' => $unreadOnly]),
    ['activeNav' => 'notifications', 'screen' => 'notifications', 'entity' => 'notification']);
