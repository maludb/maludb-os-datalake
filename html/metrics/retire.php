<?php
declare(strict_types=1);
/** Action `metric_retire` (log metric.retire — metrics.manage; confirm; pauses for an agent: deletion): **metric** — it leaves every list, the picker and the search; its history stays. Lands on /metrics. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/metrics/write.php';
require_once dirname(__DIR__, 2) . '/app/features/metrics/present.php';
dl_handler_begin();
require_right('metrics.manage');
$pdo = db();
$id = (int) (req_val('metric') ?? 0);
$m = $id > 0 ? find_metric($pdo, $id) : null;
if ($m === null) {
    respond_not_found('No such metric.');
}
dl_guard($pdo, static function () use ($pdo, $id, $m): void {
    $pdo->beginTransaction();
    metric_retire($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'metric.retire', 'metric', $id, ['dataset_id' => (int) $m['dataset_id'], 'before' => ['name' => $m['name'], 'dataset' => $m['dataset_key'], 'certified' => (bool) $m['certified']]]);
    $pdo->commit();
});
dl_done('Retired ' . $m['title'], $id, dl_land('/metrics', 'retired'), 'metricChanged');
