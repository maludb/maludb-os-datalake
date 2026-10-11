<?php
declare(strict_types=1);
/**
 * POST /access/grants/check.php — the row filter's dry run (a read door, CSRF; the grant form's Check): **dataset**, **row_filter** (the template).
 * The tokens bound for me by the database, the filter checked node by node and bound by DuckDB through the query service — no row read. The
 * owner of the dataset or grants.manage. JSON {valid, row_filter (bound), error}; HTMX the row-filter field again with "valid" or the node.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/access/queries.php';
require_post();
require_login();
verify_csrf();
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
if (!may_grant_on($pdo, 'dataset', (int) $d['id'])) {
    refuse(403, 'Only the owner of ' . $d['key'] . ' or a Lake admin grants on it.');
}
$template = trim((string) (req_val('row_filter') ?? ''));
$check = $template === '' ? ['ok' => false, 'error' => 'Type a filter to check — or leave it empty to grant every row.'] : row_filter_check((int) current_member_id(), (string) $d['key'], $template);
if (wants_json()) {
    respond_screen(['valid' => $check['ok'], 'row_filter' => $check['bound'] ?? null, 'error' => $check['error'] ?? null, 'checked_against' => $check['checked_against'] ?? null]);
}
echo view('access/partials/row-filter-input.php', ['value' => $template, 'columns' => dataset_column_names($pdo, (int) $d['id']), 'dataset_key' => $d['key'],
    'error' => $check['ok'] ? null : $check['error'], 'check' => $check]);
