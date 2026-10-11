<?php
declare(strict_types=1);
/**
 * /access/grants?dataset=&zone=&tag=&principal=&include_revoked= (screen `grants`) — the ledger of grants I may see (mcp_grants: grants.manage
 * every grant; a dataset's owner the grants on it; a grantee their own), newest first; Revoke where I may; Add grant. JSON: the grants.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/access/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$f = ['dataset' => null, 'zone' => request_string('zone') ?: null, 'tag' => request_integer('tag'), 'principal' => request_string('principal') ?: null,
      'include_revoked' => request_bool('include_revoked') ? '1' : null];
$scope = null;
if (request_string('dataset') !== '') {
    $d = require_dataset($pdo, request_string('dataset'), 'discover');
    $f['dataset'] = (int) $d['id'];
    $scope = (string) $d['key'];
} elseif ($f['zone'] !== null && in_array($f['zone'], GRANT_ZONES, true)) {
    $scope = 'zone ' . $f['zone'];
} elseif ($f['tag'] !== null) {
    $scope = 'tag ' . (string) (one_value($pdo, 'SELECT slug FROM mcp_tags WHERE tag_id = :t', ['t' => $f['tag']]) ?? $f['tag']);
}
$result = find_grants($pdo, $f, max(1, min(1000, request_integer('page') ?? 1)));
$mayAdd = has_right('grants.manage') || ($f['dataset'] !== null && may_grant_on($pdo, 'dataset', $f['dataset']))
    || db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM mcp_datasets WHERE owner_member_id = :me AND NOT is_stub AND status <> 'retired')", ['me' => (int) current_member_id()]);
log_screen_view($pdo, 'grants', $f['dataset'] === null ? [] : ['entity_type' => 'dataset', 'entity_id' => $f['dataset'], 'dataset_id' => $f['dataset']]);
if (wants_json()) {
    respond_screen(['filters' => $f, 'page' => $result['page'], 'total' => $result['total'],
                    'grants' => array_map(static fn (array $g): array => present_grant($g) + ['may_revoke' => $g['revoked_at'] === null
                        && may_grant_on($pdo, (string) $g['target_kind'], $g['dataset_id'] === null ? null : (int) $g['dataset_id'])], $result['rows'])]);
}
$notices = ['granted' => ['success', 'Granted — it applies from the next query.'], 'revoked' => ['success', 'Revoked — it stops at once.']];
render_screen('Grants', view('access/grants.php', ['f' => $f, 'result' => $result, 'may_add' => $mayAdd, 'scope_label' => $scope, 'notice' => dl_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'access', 'screen' => 'grants', 'entity' => $f['dataset'] === null ? '' : 'dataset', 'recordId' => $f['dataset'] === null ? '' : (string) $f['dataset'], 'datasetKey' => $f['dataset'] === null ? '' : (string) $scope]);
