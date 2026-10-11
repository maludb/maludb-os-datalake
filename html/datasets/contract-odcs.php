<?php
declare(strict_types=1);
/**
 * /datasets/{id|key}/contract.odcs.yaml?version= (a door — contracts-quality.md): the active contract (or a version) as ODCS v3.2.0 YAML, written by
 * our own small writer (app/features/contracts/odcs.php) with the dataset's enabled checks as quality rules. discover on the dataset. JSON: the
 * contract and the YAML as text.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/contracts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/contracts/odcs.php';
require_once dirname(__DIR__, 2) . '/app/features/checks/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$d = require_dataset($pdo, request_string('ref') !== '' ? request_string('ref') : request_string('key'), 'discover');
$id = (int) $d['id'];
$version = request_string('version');
$k = find_contract($pdo, $id, $version === '' ? null : $version) ?? respond_not_found('There is no contract for ' . $d['key'] . ' yet.');
$row = one_row($pdo, 'SELECT key, title, description, purpose, usage, limitations, split_part(key, \'.\', 2) AS name FROM mcp_datasets WHERE dataset_id = :d', ['d' => $id]);
$yaml = odcs_yaml($k, $row ?? ['key' => $d['key'], 'name' => $d['name'] ?? '', 'title' => $d['title'] ?? ''], find_checks($pdo, ['dataset' => $id, 'enabled' => true]));
log_activity($pdo, 'contract.download', 'contract', (int) $k['contract_id'], ['dataset_id' => $id, 'after' => ['version' => $k['version'], 'bytes' => strlen($yaml)]]);
if (wants_json()) {
    respond_screen(['contract' => present_contract($k), 'yaml' => $yaml]);
}
header('Content-Type: application/yaml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . str_replace('.', '_', (string) $d['key']) . '-' . $k['version'] . '.odcs.yaml"');
header('X-Content-Type-Options: nosniff');
echo $yaml;
