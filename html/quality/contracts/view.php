<?php
declare(strict_types=1);
/**
 * /datasets/{id|key}/contract?version= (screen `contract-view`; contracts-quality.md) — the active contract (or a version; with none active, the
 * draft): the schema as a table (name, logical and physical type, required, unique, key, source, description, classification), the key, the
 * ordering column, the SLA in words, the versions (draft / active / deprecated) with their breaking changes; Download ODCS; Edit draft;
 * Publish (a draft) with its impact shown first (dl_impact: datasets, saved queries, metrics, consumers downstream). discover on the dataset.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/contracts/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$d = require_dataset($pdo, request_string('ref') !== '' ? request_string('ref') : request_string('dataset'), 'discover');
$id = (int) $d['id'];
$version = request_string('version');
$k = find_contract($pdo, $id, $version === '' ? null : $version);
if ($k === null && $version !== '') {
    respond_not_found('No such version.');
}
$versions = contract_versions($pdo, $id);
$draft = contract_draft($pdo, $id);
$breaking = $draft === null ? [] : (json_decode((string) one_value($pdo, 'SELECT dl_contract_breaking_changes(:c)', ['c' => (int) $draft['contract_id']]), true) ?: []);
$impact = $draft !== null && has_right('contracts.manage') ? contract_impact($pdo, $id) : null;
$may = ['edit' => has_right('contracts.manage') && $d['zone'] === 'curated', 'publish' => has_right('contracts.manage') && $draft !== null];
log_screen_view($pdo, 'contract-view', ['entity_type' => 'dataset', 'entity_id' => $id, 'dataset_id' => $id]);
if (wants_json()) {
    respond_screen(['dataset' => ['dataset_id' => $id, 'key' => $d['key'], 'zone' => $d['zone']], 'contract' => present_contract($k),
        'versions' => array_map('present_contract_version', $versions), 'draft' => present_contract($draft), 'draft_breaking' => $breaking,
        'impact' => $impact === null ? null : present_impact($impact), 'may' => $may, 'odcs_url' => $k === null ? null : '/datasets/' . $d['key'] . '/contract.odcs.yaml' . ($version !== '' ? '?version=' . rawurlencode($version) : '')]);
}
$notices = ['drafted' => ['success', 'Draft saved. Publish it when it is right — its impact is shown first.'], 'published' => ['success', 'Published — promotion types by it from now on.']];
render_screen('Contract — ' . $d['key'], view('contracts/view.php', ['d' => $d, 'k' => $k, 'versions' => $versions, 'draft' => $draft, 'breaking' => $breaking, 'impact' => $impact,
    'may' => $may, 'version' => $version, 'notice' => dl_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'quality', 'screen' => 'contract-view', 'entity' => 'dataset', 'recordId' => (string) $id, 'datasetKey' => (string) $d['key']]);
