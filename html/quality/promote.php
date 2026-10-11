<?php
declare(strict_types=1);
/**
 * Action `promote_run` (log promote.run — promote.run and write on the curated dataset): **dataset** — the raw rows that arrived since the last
 * promotion, typed by the active contract, as a `promote` job for the writer (refused without one: "Publish a contract first — promotion types
 * by it"). The person's row says it was queued; the writer's (source system) says what it promoted. GET ?dataset= is the Quality tab's polling
 * door for the latest promotion (not a manifest action): the status partial, or its JSON.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/quality/queries.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    require_login();
    $pdo = db();
    $d = require_dataset($pdo, request_string('dataset'), 'discover');
    $p = last_promotion($pdo, (int) $d['id']);
    if (wants_json()) {
        respond_screen(['dataset_id' => (int) $d['id'], 'promotion' => $p === null ? null : ['job_id' => (int) $p['job_id'], 'status' => $p['status'], 'words' => promotion_words($p),
            'result' => array_intersect_key($p['result'], array_flip(['status', 'rows_in', 'rows_promoted', 'rows_quarantined', 'rows_deduped', 'contract', 'full', 'snapshot_id']))]]);
    }
    if ($p !== null && in_array($p['status'], ['done', 'failed'], true) && request_bool('was_running')) {
        hx_trigger('promotionFinished');
    }
    echo view('quality/partials/promotion.php', ['p' => $p, 'dataset' => (int) $d['id']]);
    exit;
}
dl_handler_begin();
require_right('promote.run');
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'write');
if ($d['zone'] !== 'curated') {
    dl_refuse_fields(['dataset' => 'Promotion is raw → curated: name a curated dataset.']);
}
$id = (int) $d['id'];
$job = dl_guard($pdo, static function () use ($pdo, $id, $d): int {
    $pdo->beginTransaction();
    $job = promote($pdo, $id, false, (int) current_member_id());
    log_activity($pdo, 'promote.run', 'dataset', $id, ['dataset_id' => $id, 'after' => ['dataset' => $d['key'], 'job_id' => $job, 'phase' => 'queued']]);
    $pdo->commit();
    return $job;
});
dl_done('Promotion queued', $id, dl_land(return_path('/datasets/' . $d['key'] . '?tab=quality'), 'promoting'), 'promotionQueued', ['job_id' => $job]);
