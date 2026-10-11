<?php
declare(strict_types=1);

/** One run's JSON (the get_query_run tool's shape): the masks and filters by NAME, the SQL only as the view gives it. */
function present_run(array $r): array
{
    $masks = [];
    foreach ($r['masked'] as $key => $cols) {
        foreach ((array) $cols as $col => $mask) {
            $masks[] = $key . '.' . $col;
        }
    }
    return ['query_run_id' => (int) $r['query_run_id'], 'caller_member_id' => (int) $r['caller_member_id'], 'caller_name' => $r['caller_name'],
            'caller_kind' => $r['caller_kind'], 'on_behalf_of' => $r['on_behalf_of'] === null ? null : (int) $r['on_behalf_of'],
            'on_behalf_name' => $r['on_behalf_name'], 'agent_run_id' => $r['agent_run_id'] === null ? null : (int) $r['agent_run_id'], 'source' => $r['source'],
            'saved_query_id' => $r['saved_query_id'] === null ? null : (int) $r['saved_query_id'], 'saved_query_title' => $r['saved_query_title'],
            'sql' => $r['sql'], 'sql_hidden' => $r['sql'] === null, 'as_of' => $r['as_of'], 'datasets' => $r['datasets_list'], 'masked_columns' => $masks,
            'row_filtered_datasets' => $r['filtered'], 'rows' => (int) $r['rows_returned'], 'truncated' => (bool) $r['truncated'],
            'bytes' => (int) $r['bytes_returned'], 'ms' => (int) $r['ms'], 'status' => $r['status'], 'refusal_rule' => $r['refusal_rule'],
            'created_at' => json_ts($r['created_at'])];
}
