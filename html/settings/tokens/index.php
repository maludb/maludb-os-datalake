<?php
declare(strict_types=1);
/**
 * /settings/tokens/ — my MCP tokens and my API keys (screen `settings-tokens`): mint (the secret shown once, on this page), rotate (the old
 * key keeps 24 hours), revoke; the SQL switch for a holder of sql.editor. People only (an agent never holds a person's token or key).
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$tokens = find_my_tokens($pdo, $me);
$keys = find_my_keys($pdo, $me);
log_screen_view($pdo, 'settings-tokens');
$may = ['keys' => has_right('keys.own'), 'sql' => has_right('sql.editor')];
if (wants_json()) {
    respond_screen(['tokens' => array_map('present_token', $tokens), 'keys' => array_map('present_key', $keys), 'may' => $may,
        'records_url' => app_url('/mcp/records'), 'activity_url' => app_url('/mcp/activity'), 'api_url' => app_url('/api/v1/')]);
}
$minted = $_SESSION['minted_secret'] ?? null;
unset($_SESSION['minted_secret']);
$notices = ['token_revoked' => ['success', 'Revoked the token.'], 'key_revoked' => ['success', 'Revoked the key.'], 'key_sql_on' => ['success', 'The key may now send SQL.'],
            'key_sql_off' => ['success', 'The key may no longer send SQL.']];
render_screen('Tokens and keys', view('settings/tokens.php', ['tokens' => $tokens, 'keys' => $keys, 'minted' => $minted, 'tz' => member_timezone(), 'may' => $may,
    'defaults' => key_defaults($pdo), 'notice' => dl_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'tokens', 'screen' => 'settings-tokens', 'entity' => 'mcp_access_token']);
