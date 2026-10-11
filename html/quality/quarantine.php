<?php
declare(strict_types=1);
/**
 * /datasets/{id|key}/quarantine?reason=&page= (screen `quarantine`; contracts-quality.md divergence 4) — the rows a promotion held back, read
 * through the QUERY SERVICE (`preview` with `quarantine: true`): at `write` on the curated dataset, each column masked as the curated column it
 * feeds is masked for the caller (dl_quarantine_policy — db/024); `_dl_reason` first, then the columns as they arrived; the count per reason, a
 * filter by reason; 100 a page. The read is audited like any other. JSON: the service's answer.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/quality/queries.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$d = require_dataset($pdo, request_string('ref') !== '' ? request_string('ref') : request_string('dataset'), 'write');
$id = (int) $d['id'];
if ($d['zone'] !== 'curated') {
    respond_not_found('Only a curated dataset has a quarantine.');
}
$reason = request_string('reason');
$reason = $reason === '' ? null : mb_substr($reason, 0, 200);
$page = max(1, min(10000, request_integer('page') ?? 1));
$a = quarantine_rows($pdo, (int) current_member_id(), $id, $reason, $page);
log_screen_view($pdo, 'quarantine', ['entity_type' => 'dataset', 'entity_id' => $id, 'dataset_id' => $id]);
if (($a['status'] ?? '') === 'ok') {
    log_activity($pdo, 'query.run', 'dataset', $id, ['dataset_id' => $id, 'after' => ['query_run_id' => (int) ($a['query_run_id'] ?? 0), 'what' => 'quarantine', 'rows' => count($a['rows'] ?? [])]]);
}
if (wants_json()) {
    respond_screen(['dataset' => ['dataset_id' => $id, 'key' => $d['key']], 'reason' => $reason, 'page' => $page,
        'quarantine' => ['status' => $a['status'] ?? 'error', 'refusal_rule' => $a['refusal_rule'] ?? null, 'message' => ($a['status'] ?? '') === 'ok' ? ($a['message'] ?? null) : lake_refusal_message($a),
            'columns' => $a['columns'] ?? [], 'rows' => $a['rows'] ?? [], 'masked_columns' => $a['masked_columns'] ?? [], 'reasons' => (object) ($a['reasons'] ?? []),
            'total' => (int) ($a['total'] ?? 0), 'query_run_id' => $a['query_run_id'] ?? null, 'next_offset' => $a['next_offset'] ?? null]]);
}
render_screen('Quarantine — ' . $d['key'], view('quality/quarantine.php', ['d' => $d, 'a' => $a, 'reason' => $reason, 'page' => $page]),
    ['activeNav' => 'quality', 'screen' => 'quarantine', 'entity' => 'dataset', 'recordId' => (string) $id, 'datasetKey' => (string) $d['key']]);
