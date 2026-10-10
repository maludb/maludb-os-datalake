<?php
declare(strict_types=1);
/**
 * /datasets/{id|key}/columns/{name|id} (screen `column-view`; catalog.md): a column's meaning, types, classification and personal kind
 * (read-only, with the action that changes them), the glossary term, the reference, the latest profile and the profile over time — made
 * safe by present_profile() (personal values masked, a confidential column's top values withheld). Discover on the dataset; a stub is
 * nothing here. Logs screen.view with dataset_id. JSON: present_column() and the profile history.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/catalog/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$gate = require_dataset($pdo, request_string('ref'), 'discover');
$id = (int) $gate['id'];
$d = find_dataset($pdo, $id) ?? respond_not_found('No such dataset.');
$c = find_column($pdo, $id, request_string('column')) ?? respond_not_found('No such column.');
$personal = $c['personal_kind'] ?? $c['proposed_personal_kind'];
$profile = present_profile($c['profile'], (string) $c['classification'], $personal);
$history = array_map(static fn (array $h): array => ['profile_id' => (int) $h['profile_id'], 'profiled_at' => $h['profiled_at'],
    'profile' => present_profile($h['profile'], (string) $c['classification'], $personal) ?? []], column_profile_history($pdo, $id, (string) $c['name'], 30));
log_screen_view($pdo, 'column-view', ['entity_type' => 'dataset', 'entity_id' => $id]);
if (wants_json()) {
    respond_screen(['dataset' => ['dataset_id' => $id, 'key' => $d['key'], 'title' => $d['title']], 'column' => present_column($c),
        'profile_history' => array_map(static fn (array $h): array => ['profiled_at' => json_ts($h['profiled_at']), 'profile' => $h['profile']], $history),
        'may' => ['describe' => may_describe_dataset($d)]]);
}
$notices = ['updated' => ['success', 'Saved.']];
render_screen($c['name'] . ' · ' . $d['title'], view('catalog/column.php', ['d' => $d, 'c' => $c, 'profile' => $profile, 'history' => $history, 'may' => ['describe' => may_describe_dataset($d)],
    'here' => here_url(), 'notice' => dl_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'browse', 'screen' => 'column-view', 'entity' => 'column', 'recordId' => (string) $c['column_id'], 'datasetKey' => $d['key']]);
