<?php
declare(strict_types=1);
/**
 * Action `key_rotate` (log `key.rotate`; confirm): a new key for one of mine, the same label, limits and SQL switch (dl_key_rotate, db/005);
 * the old key keeps working for the lake's overlap (24 hours) and then ends. The new key is shown once. Own; people only.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/write.php';
dl_handler_begin();
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('api_key') ?? request_integer('id');
$old = $id === null ? null : find_token($pdo, $me, $id, 'api');
if ($old === null || !token_live($old)) {
    refuse(404, 'Key not found.');
}
if ((bool) one_value($pdo, "SELECT EXISTS (SELECT 1 FROM mcp_access_tokens WHERE rotated_from = :id AND revoked_at IS NULL)", ['id' => $id])) {
    refuse(422, 'This key was already rotated — rotate the key that replaced it.');
}
$new = dl_guard($pdo, static function () use ($pdo, $me, $id): array {
    $pdo->beginTransaction();
    $new = rotate_key($pdo, $me, $id);
    $pdo->commit();
    return $new;
});
$ends = (string) one_value($pdo, 'SELECT expires_at FROM mcp_access_tokens WHERE id = :id', ['id' => $id]);
log_activity($pdo, 'key.rotate', 'mcp_access_token', $new['id'], ['before' => ['api_key' => $id], 'after' => ['label' => $old['label'], 'rotated_from' => $id, 'old_ends_at' => json_ts($ends)]]);
$did = 'Rotated the API key "' . $old['label'] . '" — the old one works until ' . format_ts($ends, member_timezone(), 'M j, g:i A');
emit_action_status(true, ['did' => $did, 'record_id' => $new['id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['did' => $did, 'record_id' => $new['id'], 'key' => $new['secret'], 'rotated_from' => $id, 'old_expires_at' => json_ts($ends), 'location' => '/settings/tokens/#key-' . $new['id']]);
}
$_SESSION['minted_secret'] = ['kind' => 'key', 'id' => $new['id'], 'label' => $old['label'], 'secret' => $new['secret'], 'rotated_from' => $id];
saved_go('/settings/tokens/', 'tokenChanged');
