<?php
declare(strict_types=1);
/** Action `template_delete` (log template.delete — its maker or loads.manage; confirm): **template**. A load that used it keeps its own mapping. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/write.php';
dl_handler_begin();
require_right('sandbox.upload');
$pdo = db();
$t = find_template($pdo, (string) (req_val('template') ?? '')) ?? respond_not_found('No such saved mapping.');
if (!may_manage_template($t)) {
    refuse(403, 'Only its maker or a data engineer may delete the mapping ' . $t['name'] . '.');
}
$id = (int) $t['template_id'];
dl_guard($pdo, static function () use ($pdo, $id, $t): void {
    $pdo->beginTransaction();
    template_delete($pdo, $id);
    log_activity($pdo, 'template.delete', 'template', $id, ['before' => ['name' => $t['name'], 'kind' => $t['kind']]]);
    $pdo->commit();
});
dl_done('Deleted the mapping ' . $t['name'], $id, dl_land('/templates', 'deleted'), 'templateChanged');
