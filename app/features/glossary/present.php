<?php
declare(strict_types=1);

/** The glossary's JSON (the records tools `glossary` and `get_term`). */
function present_term_row(array $t): array
{
    return ['term_id' => (int) $t['term_id'], 'name' => $t['name'], 'definition' => $t['definition'], 'synonyms' => array_values((array) $t['synonyms']),
            'parent_id' => $t['parent_id'] === null ? null : (int) $t['parent_id'], 'parent_name' => $t['parent_name'] ?? null, 'status' => $t['status'],
            'steward_member_id' => $t['steward_member_id'] === null ? null : (int) $t['steward_member_id'], 'steward_name' => $t['steward_name'] ?? null,
            'column_count' => (int) $t['column_count'], 'url' => '/glossary/' . (int) $t['term_id']];
}

function present_term(array $t, array $columns): array
{
    return ['term' => present_term_row($t) + ['approved_at' => json_ts($t['approved_at']), 'approved_by' => $t['approved_by_name'] ?? null,
            'updated_at' => json_ts($t['updated_at'])],
            'columns' => array_map(static fn (array $c): array => ['dataset_id' => (int) $c['dataset_id'], 'key' => $c['key'], 'column_id' => (int) $c['column_id'],
                'column' => $c['column']], $columns)];
}

/** A term's status badge: [class, words]. */
function term_status_badge(string $status): array
{
    return match ($status) {
        'approved'   => ['text-bg-success', 'Approved'],
        'deprecated' => ['text-bg-warning', 'Deprecated'],
        default      => ['text-bg-secondary', 'Draft'],
    };
}
