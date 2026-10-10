<?php
declare(strict_types=1);
/** Action `key_revoke` (log `key.revoke`; confirm): an API key of mine — or anyone's for a holder of keys.all — stops at once; another's answers 404. People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/write.php';
dl_handler_begin();
require_human();
$pdo = db();
$me = (int) current_member_id();
$any = has_right('keys.all');
$id = request_integer('api_key') ?? request_integer('id');
$row = $id === null ? null : find_token($pdo, $me, $id, 'api', $any);
if ($row === null || $row['revoked_at'] !== null || !dl_guard($pdo, static fn (): bool => revoke_key($pdo, $me, $id, $any))) {
    refuse(404, 'Key not found.');
}
log_activity($pdo, 'key.revoke', 'mcp_access_token', $id, ['before' => ['label' => $row['label'], 'member_id' => (int) $row['member_id']]]);
dl_done('Revoked the API key "' . $row['label'] . '"', $id, dl_land('/settings/tokens/', 'key_revoked'), 'tokenChanged');
