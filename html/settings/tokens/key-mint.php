<?php
declare(strict_types=1);
/**
 * Action `key_mint` (log `key.mint`: the label, the limits, the SQL switch and the expiry — never the key or its hash): an API key acting as
 * me (keys.own) — rate_per_minute 1 to 100000 and rate_per_day 1 to 10000000 (blank = the lake's defaults, filled by the database),
 * allow_sql (yes only for a holder of sql.editor: "Ad-hoc SQL over the API needs the SQL editor"), expires_in_days (blank for never).
 * The key is shown once. People only (it pauses for an agent — `external_send` — but an agent never reaches it).
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/write.php';
dl_handler_begin();
require_human();
require_right('keys.own');
$pdo = db();
$errors = [];
$label = dl_text('label', null, 80, 'A label', $errors, true);
if ($label === null && !isset($errors['label'])) {
    $errors['label'] = 'A label is required (up to 80 characters) — what will use this key.';
}
$perMinute = dl_int('rate_per_minute', null, 1, 100000, 'Calls a minute', $errors, true);
$perDay = dl_int('rate_per_day', null, 1, 10000000, 'Calls a day', $errors, true);
$days = dl_int('expires_in_days', null, 1, 3650, 'The days until it expires', $errors, true);
$allowSql = dl_yes('allow_sql');
if ($errors !== []) {
    dl_refuse_fields($errors);
}
if ($allowSql && !has_right('sql.editor')) {
    refuse(403, 'Ad-hoc SQL over the API needs the SQL editor.');
}
$minted = dl_guard($pdo, static fn (): array => mint_key($pdo, (int) current_member_id(), $label, $perMinute, $perDay, $allowSql, $days));
$k = find_token($pdo, (int) current_member_id(), $minted['id'], 'api');
log_activity($pdo, 'key.mint', 'mcp_access_token', $minted['id'], ['after' => ['label' => $label, 'rate_per_minute' => (int) $k['rate_per_minute'],
    'rate_per_day' => (int) $k['rate_per_day'], 'allow_sql' => $allowSql, 'expires_in_days' => $days]]);
$did = 'Minted the API key "' . $label . '" — shown once';
emit_action_status(true, ['did' => $did, 'record_id' => $minted['id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['did' => $did, 'record_id' => $minted['id'], 'key' => $minted['secret'], 'rate_per_minute' => (int) $k['rate_per_minute'],
        'rate_per_day' => (int) $k['rate_per_day'], 'allow_sql' => $allowSql, 'location' => '/settings/tokens/#key-' . $minted['id']]);
}
$_SESSION['minted_secret'] = ['kind' => 'key', 'id' => $minted['id'], 'label' => $label, 'secret' => $minted['secret']];
saved_go('/settings/tokens/', 'tokenChanged');
