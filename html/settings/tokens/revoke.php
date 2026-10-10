<?php
declare(strict_types=1);
/** Action `token_revoke` (log `token.revoke`; confirm): an MCP token of mine — or anyone's for a holder of keys.all; another's answers 404. People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/write.php';
dl_handler_begin();
require_human();
$pdo = db();
$me = (int) current_member_id();
$any = has_right('keys.all');
$id = request_integer('token') ?? request_integer('id');
$row = $id === null ? null : find_token($pdo, $me, $id, 'mcp', $any);
if ($row === null || $row['revoked_at'] !== null || !dl_guard($pdo, static fn (): bool => revoke_token($pdo, $me, $id, $any))) {
    refuse(404, 'Token not found.');
}
log_activity($pdo, 'token.revoke', 'mcp_access_token', $id, ['before' => ['label' => $row['label'], 'member_id' => (int) $row['member_id']]]);
dl_done('Revoked the token "' . $row['label'] . '"', $id, dl_land('/settings/tokens/', 'token_revoked'), 'tokenChanged');
