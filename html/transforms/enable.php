<?php
declare(strict_types=1);
/** Action `transform_enable` (log transform.enable | transform.disable — transforms.manage or the owner): **transform**, enabled (yes — the default — or no). A disabled transform is not run by its schedule or by its inputs changing; a person may still run it. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/transforms/write.php';
require_once dirname(__DIR__, 2) . '/app/features/transforms/present.php';
dl_handler_begin();
require_any_right('transforms.manage|sandbox.transform');
$pdo = db();
$id = (int) (req_val('transform') ?? 0);
$t = $id > 0 ? find_transform($pdo, $id) : null;
if ($t === null) {
    respond_not_found('No such transform.');
}
require_manage_transform($t);
$on = dl_yes('enabled', true);
dl_guard($pdo, static function () use ($pdo, $id, $t, $on): void {
    $pdo->beginTransaction();
    transform_enable($pdo, $id, $on, (int) current_member_id());
    log_activity($pdo, $on ? 'transform.enable' : 'transform.disable', 'transform', $id, ['dataset_id' => (int) $t['output_dataset_id'], 'before' => ['enabled' => (bool) $t['enabled']], 'after' => ['enabled' => $on]]);
    $pdo->commit();
});
dl_done(($on ? 'Enabled ' : 'Disabled ') . $t['name'], $id, dl_land(return_path('/transforms/' . $id), $on ? 'enabled' : 'disabled'), 'transformChanged', ['enabled' => $on]);
