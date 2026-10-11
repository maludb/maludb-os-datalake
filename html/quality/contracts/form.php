<?php
declare(strict_types=1);
/**
 * /datasets/{id|key}/contract/edit (screen `contract-edit`; contracts-quality.md) — the next version's draft: prefilled from the open draft, the
 * active contract, or the raw input's columns (pick the raw dataset first — the record picker); per column the name, the logical and physical
 * types, required, unique, primary key, the source (the raw column), the description; the key; the ordering column; the SLA; the version
 * (suggested: a patch, a minor for an added column, a major when breaking); the breaking changes listed live (?fragment=breaking — the
 * database's rule, dl_contract_breaking_preview). POST with `odcs` (a document of ours, or another ODCS v3 contract written the same way):
 * the form prefilled from it, nothing saved. contracts.manage; the dataset curated. JSON: the prefill.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/contracts/write.php';
require_once dirname(__DIR__, 3) . '/app/features/contracts/present.php';
require_once dirname(__DIR__, 3) . '/app/features/contracts/odcs.php';
require_login();
require_right('contracts.manage');
$pdo = db();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isPost) {
    verify_csrf();
}
$ref = (string) ($_GET['ref'] ?? $_POST['ref'] ?? $_GET['dataset'] ?? $_POST['dataset'] ?? '');
$d = require_dataset($pdo, $ref, 'discover');
$id = (int) $d['id'];
if ($d['zone'] !== 'curated') {
    refuse(422, 'A contract is a curated dataset\'s — it types the promotion from raw.');
}
if (request_string('fragment') === 'breaking') {                  // the live list (Pattern A): the form's fields, nothing saved
    $f = contract_fields_from_request($_GET);
    $breaking = contract_breaking_preview($pdo, $id, $f['schema'], $f['key'] !== [] ? $f['key']
        : array_values(array_map(static fn (array $e): string => (string) $e['name'], array_filter($f['schema'], static fn (array $e): bool => !empty($e['primary_key'])))));
    $suggest = contract_suggest_version($pdo, $id, $f['schema'], $breaking);
    if (wants_json()) {
        respond_screen(['breaking' => $breaking, 'suggested_version' => $suggest]);
    }
    echo view('contracts/partials/breaking.php', ['breaking' => $breaking, 'suggest' => $suggest]);
    exit;
}
$notice = null;
$rawId = request_integer('raw');
$prefill = contract_draft_from($pdo, $id, $rawId);
if ($isPost && isset($_POST['odcs'])) {
    $parsed = odcs_parse((string) $_POST['odcs']);
    if ($parsed === null) {
        $notice = ['danger', 'That is not an ODCS contract this lake can read (a DataContract with a schema of properties).'];
    } else {
        $prefill = ['version' => $parsed['version'], 'schema' => $parsed['schema'], 'key' => $parsed['key'], 'ordering_column' => $parsed['ordering_column'],
                    'sla' => $parsed['sla'], 'contract' => $prefill['contract'], 'raw' => contract_raw_key($parsed['schema'])];
        $notice = ['info', 'Read from the ODCS document — check it, then Save the draft.'];
    }
}
$rawKey = $prefill['raw'];
$rawCols = [];
if ($rawKey !== null && ($rid = one_value($pdo, "SELECT dataset_id FROM mcp_datasets WHERE key = :k AND NOT is_stub", ['k' => $rawKey])) !== null) {
    $rawCols = array_keys(live_columns_of($pdo, (int) $rid));
    $rawId = (int) $rid;
}
$breaking = contract_breaking_preview($pdo, $id, $prefill['schema'], (array) $prefill['key']);
if (($prefill['version'] ?? '') === '' || ($prefill['contract'] === null && $notice === null)) {
    $prefill['version'] = contract_suggest_version($pdo, $id, $prefill['schema'], $breaking);
}
log_screen_view($pdo, 'contract-edit', ['entity_type' => 'dataset', 'entity_id' => $id, 'dataset_id' => $id]);
if (wants_json()) {
    respond_screen(['dataset' => ['dataset_id' => $id, 'key' => $d['key']], 'draft' => $prefill, 'raw_columns' => $rawCols, 'breaking' => $breaking,
        'imported' => $notice === null ? null : $notice[0] !== 'danger', 'notice' => $notice === null ? null : $notice[1]]);
}
render_screen('Contract draft — ' . $d['key'], view('contracts/form.php', ['d' => $d, 'p' => $prefill, 'raw_id' => $rawId, 'raw_cols' => $rawCols, 'breaking' => $breaking,
    'errors' => [], 'notice' => $notice]), ['activeNav' => 'quality', 'screen' => 'contract-edit', 'entity' => 'dataset', 'recordId' => (string) $id, 'datasetKey' => (string) $d['key']]);
