<?php
declare(strict_types=1);

/**
 * One query run (screen `query-run-view` — query-service-editor.md): who (and on whose behalf, which agent run), the source, the datasets, the
 * masks and the row filters applied (their NAMES — the column a mask covers, the dataset a filter bounds; never a filter's text to a
 * non-auditor), rows, truncated, time, status, the refusal's rule. Through mcp_query_runs: its caller, the person it acted for, the owner of a
 * dataset it read, audit.read. The SQL: the view's own rule (the caller, the person it acted for, audit.read — null to a dataset's owner).
 *   find_run($pdo, $id) → the row with names, or null
 */
function find_run(PDO $pdo, int $id): ?array
{
    $r = one_row($pdo, 'SELECT q.*, c.display_name AS caller_name, c.member_kind AS caller_kind, b.display_name AS on_behalf_name
                          FROM mcp_query_runs q LEFT JOIN mcp_members c ON c.member_id = q.caller_member_id LEFT JOIN mcp_members b ON b.member_id = q.on_behalf_of
                         WHERE q.query_run_id = :id', ['id' => $id]);
    if ($r === null) {
        return null;
    }
    $r['masked'] = json_decode((string) $r['masked_columns'], true) ?: [];
    $r['filtered'] = array_keys(json_decode((string) (one_value($pdo, 'SELECT row_filters::text FROM query_runs WHERE id = :id', ['id' => $id]) ?? '{}'), true) ?: []);
    $r['datasets_list'] = pg_text_array((string) $r['datasets']);
    $r['saved_query_title'] = $r['saved_query_id'] === null ? null
        : one_value($pdo, 'SELECT title FROM mcp_saved_queries WHERE saved_query_id = :id', ['id' => (int) $r['saved_query_id']]);
    return $r;
}
