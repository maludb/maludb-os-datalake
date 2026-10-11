<?php
declare(strict_types=1);
/**
 * Action `metric_certify` (log metric.certify | metric.uncertify — metrics.manage; pauses for an agent: other): **metric**, certify (yes — the default — or no). Certifying checks that the metric runs for
 * the certifier now (a dry run through the query service); uncertifying clears the badge. A change to the definition removes the badge by itself.
 */
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
$on = dl_yes('certify', true);
dl_guard($pdo, static function () use ($pdo, $id, $m, $on): void {
    $pdo->beginTransaction();
    metric_certify($pdo, $id, $on, (int) current_member_id());
    log_activity($pdo, $on ? 'metric.certify' : 'metric.uncertify', 'metric', $id, ['dataset_id' => (int) $m['dataset_id'], 'before' => ['certified' => (bool) $m['certified']], 'after' => ['certified' => $on, 'dataset' => $m['dataset_key']]]);
    $pdo->commit();
});
dl_done(($on ? 'Certified ' : 'Removed the badge from ') . $m['title'], $id, dl_land(return_path('/metrics/' . $id), $on ? 'certified' : 'uncertified'), 'metricChanged', ['certified' => $on]);
