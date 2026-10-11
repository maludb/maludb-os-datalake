<?php
declare(strict_types=1);
/** /groups?q=&kind=&archived= (screen `groups`) — groups as cards (mcp_groups: insiders; an external sees none). New group for grants.manage. JSON: the groups. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/groups/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/groups/present.php';
require_login();
require_right('catalog.browse');
if (!empty(current_member()['is_external'])) {
    refuse(403, 'Groups are for people inside.');
}
$pdo = db();
$q = mb_substr(trim(request_string('q')), 0, 120);
$kind = isset(GROUP_KINDS[request_string('kind')]) ? request_string('kind') : null;
$archived = request_bool('archived');
$rows = find_groups($pdo, $q, $kind, $archived);
log_screen_view($pdo, 'groups');
if (wants_json()) {
    respond_screen(['groups' => array_map('present_group', $rows), 'may_add' => has_right('grants.manage')]);
}
$notices = ['archived' => ['success', 'Archived — its grants stay until revoked and reach nobody.']];
render_screen('Groups', view('groups/index.php', ['rows' => $rows, 'q' => $q, 'kind' => $kind, 'archived' => $archived, 'may_add' => has_right('grants.manage'),
    'notice' => dl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'access', 'screen' => 'groups']);
