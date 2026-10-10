<?php
declare(strict_types=1);
/** Action `key_sql_switch` (log `key.sql_switch`; confirm): may this key of mine send ad-hoc SQL (POST /api/v1/sql)? Own and sql.editor; people only (pauses for an agent — `external_send`). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/write.php';
dl_handler_begin();
require_human();
if (!has_right('sql.editor')) {
    refuse(403, 'Ad-hoc SQL over the API needs the SQL editor.');
}
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('api_key') ?? request_integer('id');
$row = $id === null ? null : find_token($pdo, $me, $id, 'api');
if ($row === null || !token_live($row)) {
    refuse(404, 'Key not found.');
}
if (!req_has('allow_sql')) {
    refuse(422, 'Say yes or no for allow sql.');
}
$allow = dl_yes('allow_sql');
dl_guard($pdo, static fn () => set_key_sql($pdo, $me, $id, $allow));
log_activity($pdo, 'key.sql_switch', 'mcp_access_token', $id, ['before' => ['allow_sql' => (bool) $row['allow_sql']], 'after' => ['allow_sql' => $allow, 'label' => $row['label']]]);
dl_done(($allow ? 'The key "' . $row['label'] . '" may now send SQL' : 'The key "' . $row['label'] . '" may no longer send SQL'), $id,
    dl_land('/settings/tokens/', $allow ? 'key_sql_on' : 'key_sql_off'), 'tokenChanged', ['allow_sql' => $allow]);
