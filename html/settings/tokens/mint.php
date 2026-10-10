<?php
declare(strict_types=1);
/**
 * Action `token_mint` (log `token.mint`: the label and the expiry, never the token): a personal read token for the two MCP servers, shown
 * once — on the next screen, or in this JSON answer — and never again; only its hash is stored. expires_in_days 1 to 3650, blank for never.
 * People only. An API key acting as the person is key_mint.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/write.php';
dl_handler_begin();
require_human();
$pdo = db();
$errors = [];
$label = dl_text('label', null, 80, 'A label', $errors, true);
$days = dl_int('expires_in_days', null, 1, 3650, 'The days until it expires', $errors, true);
if ($label === null && !isset($errors['label'])) {
    $errors['label'] = 'A label is required (up to 80 characters) — what will use this token.';
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$minted = dl_guard($pdo, static fn (): array => mint_token($pdo, (int) current_member_id(), $label, $days));
log_activity($pdo, 'token.mint', 'mcp_access_token', $minted['id'], ['after' => ['label' => $label, 'scope' => 'mcp', 'expires_in_days' => $days]]);
emit_action_status(true, ['did' => 'Minted the MCP token "' . $label . '" — shown once', 'record_id' => $minted['id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['did' => 'Minted the MCP token "' . $label . '" — shown once', 'record_id' => $minted['id'], 'token' => $minted['secret'], 'location' => '/settings/tokens/#token-' . $minted['id']]);
}
$_SESSION['minted_secret'] = ['kind' => 'token', 'id' => $minted['id'], 'label' => $label, 'secret' => $minted['secret']];
saved_go('/settings/tokens/', 'tokenChanged');
