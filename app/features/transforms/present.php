<?php
declare(strict_types=1);

/**
 * Transforms' JSON and the words screens share (whitelisted presenters — never a raw row): a card, a transform, a run. The SQL is in the answer only for someone who reads
 * every input (`may_read_sql`); the frozen policy is never in it (no view carries it). The records tools find_transforms and get_transform answer these shapes (Phase 4).
 */

/** A transform's state: [key, bootstrap colour, words] — enabled, disabled, or not frozen (its SQL changed and was not frozen again: it cannot run). */
function transform_state(array $t): array
{
    if ($t['frozen_at'] === null) {
        return ['not_frozen', 'warning', 'Not frozen'];
    }
    return $t['enabled'] ? ['enabled', 'success', 'Enabled'] : ['disabled', 'secondary', 'Disabled'];
}

/** A run's status badge: [class, words]. */
function transform_run_badge(string $status): array
{
    [$c, $w] = TRANSFORM_RUN_STATUS[$status] ?? ['secondary', $status];
    return ['bg-soft-' . $c . ' text-' . ($c === 'light' ? 'dark' : $c), $w];
}

/** A run's error as [code, words]: the writer writes `code — words`; an error with no code is all words. */
function transform_error_parts(?string $error): array
{
    $error = trim((string) $error);
    if ($error === '') {
        return [null, ''];
    }
    if (preg_match('/^([a-z][a-z_]{3,40}) — (.+)$/su', $error, $m)) {
        return [$m[1], $m[2]];
    }
    return [null, $error];
}

function present_transform_input(array $i): array
{
    return ['dataset_id' => (int) $i['dataset_id'], 'key' => $i['key'], 'title' => $i['title'], 'zone' => $i['zone'], 'certification' => $i['certification'] ?? null];
}

/** A card / a find_transforms row. */
function present_transform_row(array $t): array
{
    [$state, , $stateWord] = transform_state($t);
    $i = static fn ($v): ?int => $v === null ? null : (int) $v;
    return ['transform_id' => (int) $t['transform_id'], 'name' => $t['name'], 'description' => $t['description'],
            'output' => ['dataset_id' => (int) $t['output_dataset_id'], 'key' => $t['output_key'], 'title' => $t['output_title'], 'zone' => $t['output_zone'], 'certification' => $t['output_certification']],
            'inputs' => array_map('present_transform_input', $t['inputs']), 'inputs_hidden' => (int) $t['input_hidden'],
            'mode' => $t['mode'], 'schedule' => $t['schedule'], 'schedule_words' => cron_words($t['schedule']), 'version' => (int) $t['version'],
            'owner' => ['member_id' => $i($t['owner_member_id']), 'name' => $t['owner_name']], 'state' => $state, 'state_words' => $stateWord, 'enabled' => (bool) $t['enabled'],
            'frozen_at' => json_ts($t['frozen_at']),
            'last_run' => $t['last_run_id'] === null ? null : ['transform_run_id' => (int) $t['last_run_id'], 'status' => $t['last_status'], 'version' => $i($t['last_version']),
                'rows_written' => $i($t['last_rows']), 'started_at' => json_ts($t['last_started_at']), 'finished_at' => json_ts($t['last_finished_at']),
                'error' => $t['last_error'] === null ? null : mb_substr((string) $t['last_error'], 0, 200)],
            'url' => '/transforms/' . (int) $t['transform_id']];
}

/** get_transform: the card plus the SQL (only when every input is readable by the caller), the output's key columns and what the caller may do. */
function present_transform(array $t, array $may = []): array
{
    return present_transform_row($t) + ['may_read_sql' => (bool) $t['may_read_sql'], 'sql' => $t['may_read_sql'] ? $t['sql'] : null,
        'output_key_columns' => $t['output_key_columns'] ?? [], 'updated_at' => json_ts($t['updated_at']), 'may' => $may];
}

function present_transform_run(array $r): array
{
    return ['transform_run_id' => (int) $r['transform_run_id'], 'version' => (int) $r['version'], 'status' => $r['status'], 'input_snapshots' => (object) ($r['input_snapshots'] ?? []),
            'output_snapshot' => $r['output_snapshot'] === null ? null : (int) $r['output_snapshot'], 'rows_written' => (int) $r['rows_written'],
            'error' => $r['error'] === null ? null : mb_substr((string) $r['error'], 0, 400), 'started_at' => json_ts($r['started_at']), 'finished_at' => json_ts($r['finished_at'])];
}

/** The form's values, from a transform or the defaults (a new transform: replace, on request). */
function transform_form_values(?array $t, array $given = []): array
{
    $v = ['name' => '', 'description' => '', 'output_dataset' => ($given['output_dataset'] ?? null) !== null && ctype_digit((string) $given['output_dataset']) ? (int) $given['output_dataset'] : null,
          'sql' => '', 'mode' => 'replace', 'schedule_kind' => 'manual', 'cron' => ''];
    if ($t !== null) {
        $kind = $t['schedule'] === null ? 'manual' : ($t['schedule'] === 'after_inputs' ? 'after_inputs' : 'cron');
        $v = ['name' => (string) $t['name'], 'description' => (string) ($t['description'] ?? ''), 'output_dataset' => (int) $t['output_dataset_id'],
              'sql' => $t['may_read_sql'] ? (string) $t['sql'] : '', 'mode' => (string) $t['mode'], 'schedule_kind' => $kind, 'cron' => $kind === 'cron' ? (string) $t['schedule'] : ''];
    }
    return $v;
}
