<?php
declare(strict_types=1);
/**
 * /templates (screen `templates`; uploads-loads.md): saved mappings as cards — the name, the kind, the columns' count, who made it; edit (the
 * name and the mapping as a table) and delete for its maker or loads.manage. sandbox.upload. JSON: find_import_templates' shape.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/uploads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_login();
require_right('sandbox.upload');
$pdo = db();
$q = request_string('q');
$rows = find_templates($pdo, $q === '' ? null : $q, null, 200);
log_screen_view($pdo, 'templates');
if (wants_json()) {
    respond_screen(['rows' => array_map(static fn (array $t): array => ['template_id' => (int) $t['template_id'], 'name' => $t['name'], 'kind' => $t['kind'],
        'connector' => $t['connector'], 'mapping' => $t['mapping'], 'created_by' => $t['created_by'] === null ? null : (int) $t['created_by'],
        'created_by_name' => $t['created_by_name'], 'may_manage' => may_manage_template($t)], $rows)]);
}
$notices = ['saved' => ['success', 'Saved.'], 'deleted' => ['success', 'Deleted.']];
render_screen('Saved mappings', view('templates/index.php', ['rows' => $rows, 'q' => $q, 'notice' => dl_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'upload', 'screen' => 'templates']);
