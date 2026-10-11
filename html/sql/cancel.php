<?php
declare(strict_types=1);
/**
 * POST /sql/cancel — the editor's Cancel (a read door: POST + CSRF, sql.editor): `request_id` = my running request (the page's); the query
 * service on every instance is asked — the one running it interrupts it (the run ends `cancelled` and is audited so); another person's id
 * answers not_yours. Logged `query.cancel` (the request id, whether it stopped). JSON {cancelled, rule}; HTMX a one-line partial.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/editor/queries.php';
require_post();
require_login();
verify_csrf();
require_right('sql.editor');
$pdo = db();
$rid = trim((string) ($_POST['request_id'] ?? ''));
if (!preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $rid)) {
    respond_invalid(['Name the running request (request_id).'], ['request_id' => 'Name the running request.']);
}
session_write_close();
$a = lake_cancel($rid);
log_activity($pdo, 'query.cancel', 'query_run', null, ['after' => ['request_id' => $rid, 'cancelled' => $a['cancelled'], 'rule' => $a['rule']]]);
if (wants_json()) {
    respond_screen(['cancelled' => $a['cancelled'], 'rule' => $a['rule']]);
}
echo '<div class="fs-12 text-muted" id="sql-cancel-answer">' . ($a['cancelled'] ? 'Cancelled.' : ($a['rule'] === 'not_yours' ? 'That run is not yours to cancel.' : 'Nothing of yours was running.')) . '</div>';
