<?php
declare(strict_types=1);
/**
 * Action `prefs_save` (log `settings.prefs`): a person's own notice choices — email_enabled and text_enabled (yes/no), kinds[] (the notices to
 * be told about), text_kinds[] (the notices to be texted about), digest (yes/no). A field left out stays as it was. Both channels off is
 * allowed: that person is told nothing but in the app. Always about oneself; an agent may set its own.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/write.php';
dl_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$f = [];
foreach (['email_enabled', 'text_enabled', 'digest'] as $k) {
    if (req_has($k)) {
        $f[$k] = dl_yes($k);
    }
}
foreach (['kinds', 'text_kinds'] as $k) {
    $list = request_list($k);
    if ($list === null) {
        continue;
    }
    $bad = array_diff($list, array_keys(NOTICE_KINDS));
    if ($bad !== []) {
        refuse(422, 'Unknown notice: ' . implode(', ', array_map(static fn (string $x): string => mb_substr($x, 0, 30), $bad)) . '.');
    }
    $f[$k] = $list;
}
$r = dl_guard($pdo, static function () use ($pdo, $me, $f): array {
    $pdo->beginTransaction();
    $r = save_prefs($pdo, $me, $f);
    log_activity($pdo, 'settings.prefs', 'notification_prefs', $me, ['before' => $r['before'], 'after' => $r['after']]);
    $pdo->commit();
    return $r;
});
dl_done('Saved how you are told', $me, dl_land(return_path('/settings/'), 'prefs_saved'), 'prefsChanged',
    ['email_enabled' => $r['prefs']['email_enabled'], 'text_enabled' => $r['prefs']['text_enabled']]);
