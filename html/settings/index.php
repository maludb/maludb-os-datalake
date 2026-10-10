<?php
declare(strict_types=1);
/**
 * /settings/ — the person's own settings (screen `settings`): HOW I AM TOLD (email, text, the kinds of notice, the kinds by text, the daily
 * digest) and MY TIME ZONE (the directory's — changed in the operating system). Texts go to the phone verified in the operating system — this
 * application never sees it; what the kernel last said about it is shown in words. An agent reads its own (JSON).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/present.php';
require_login();
$pdo = db();
$me = (int) current_member_id();
$prefs = find_prefs($pdo, $me);
$tz = member_timezone();
$refusal = last_text_refusal($pdo, $me);
log_screen_view($pdo, 'settings');
$may = ['settings' => has_right('settings.manage')];
if (wants_json()) {
    respond_screen(['notify' => present_prefs($prefs) + ['text_note' => $refusal], 'kinds' => NOTICE_KINDS, 'timezone' => $tz, 'may' => $may]);
}
$notices = ['prefs_saved' => ['success', 'Saved how you are told.']];
$launcher = rtrim((string) env('OS_LAUNCHER_URL', '/'), '/');
render_screen('My settings', view('settings/index.php', ['prefs' => $prefs, 'refusal' => $refusal, 'tz' => $tz, 'may' => $may,
    'osChannels' => $launcher . '/settings/channels', 'osProfile' => $launcher . '/settings', 'recent' => recent_sent($pdo, $me),
    'notice' => dl_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'my-settings', 'screen' => 'settings', 'entity' => 'settings']);
