<?php
declare(strict_types=1);
/**
 * /access/personal-data?kind=&confirmed=&dataset= (screen `personal-data`) — the map: every personal or proposed column of the datasets I may
 * discover; Confirm / Reject for a person who may (the owner, the steward, catalog.edit — never an agent). JSON: the columns.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/personal/queries.php';
require_login();
require_right('catalog.browse');
if (!empty(current_member()['is_external'])) {
    refuse(403, 'The personal-data map is for people inside.');
}
$pdo = db();
$me = (int) current_member_id();
$f = ['kind' => request_string('kind') ?: null, 'confirmed' => in_array(request_string('confirmed'), ['yes', 'no'], true) ? request_string('confirmed') : null, 'dataset' => null];
if (request_string('dataset') !== '') {
    $f['dataset'] = (int) require_dataset($pdo, request_string('dataset'), 'discover')['id'];
}
$result = personal_map($pdo, $f, max(1, min(1000, request_integer('page') ?? 1)));
log_screen_view($pdo, 'personal-data');
if (wants_json()) {
    respond_screen(['filters' => $f, 'page' => $result['page'], 'total' => $result['total'], 'columns' => array_map(static fn (array $c): array => [
        'column_id' => (int) $c['column_id'], 'dataset' => ['id' => (int) $c['dataset_id'], 'key' => (string) $c['key']], 'name' => (string) $c['name'],
        'classification' => (string) $c['classification'], 'personal_kind' => $c['personal_kind'], 'proposed_kind' => $c['proposed_personal_kind'],
        'proposed_confidence' => $c['proposed_confidence'] === null ? null : (float) $c['proposed_confidence'], 'classified_by' => (string) $c['classified_by'],
        'confirmed_at' => json_ts($c['classification_confirmed_at'] ?? null), 'may_confirm' => $c['classification_confirmed_at'] === null && may_confirm_personal($c, $me)], $result['rows'])]);
}
$notices = ['confirmed' => ['success', 'Confirmed — masked below read unmasked.'], 'rejected' => ['success', 'Rejected — not personal data.'], 'classified' => ['success', 'Saved.']];
render_screen('Personal data', view('access/personal-data.php', ['f' => $f, 'result' => $result, 'kinds' => personal_kinds($pdo), 'notice' => dl_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'access', 'screen' => 'personal-data']);
