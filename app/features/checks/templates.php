<?php
declare(strict_types=1);

/**
 * The check templates (contracts-quality.md, screen `check-add`): each kind's words, its default ODCS dimension, whether it names a column, the
 * params it takes and how they read in words ("not null", "between 0 and 10,000", "row count within ±20 % of the last load"). The kinds and the
 * dimensions are db/010's CHECK lists — the database refuses anything else; this file only says them.
 */

const CHECK_KINDS = [
    'not_null'        => ['label' => 'Not null',        'icon' => 'feather-slash',       'dimension' => 'completeness', 'column' => true,  'row' => true,
                          'what' => 'Every row has a value in the column.'],
    'unique'          => ['label' => 'Unique',          'icon' => 'feather-key',         'dimension' => 'uniqueness',   'column' => true,  'row' => false,
                          'what' => 'No two rows share a value in the column.'],
    'accepted_values' => ['label' => 'Accepted values', 'icon' => 'feather-list',        'dimension' => 'validity',     'column' => true,  'row' => true,
                          'what' => 'The column holds only the values you list.'],
    'range'           => ['label' => 'Range',           'icon' => 'feather-sliders',     'dimension' => 'accuracy',     'column' => true,  'row' => true,
                          'what' => 'The column\'s values lie between a minimum and a maximum.'],
    'regex'           => ['label' => 'Pattern',         'icon' => 'feather-code',        'dimension' => 'conformity',   'column' => true,  'row' => true,
                          'what' => 'The column\'s values match a pattern (a regular expression).'],
    'row_count'       => ['label' => 'Row count',       'icon' => 'feather-hash',        'dimension' => 'completeness', 'column' => false, 'row' => false,
                          'what' => 'The table holds a number of rows within bounds, or within a change of the last count.'],
    'freshness'       => ['label' => 'Freshness',       'icon' => 'feather-clock',       'dimension' => 'timeliness',   'column' => false, 'row' => false,
                          'what' => 'The data is no older than a number of hours.'],
    'referential'     => ['label' => 'Referential',     'icon' => 'feather-link-2',      'dimension' => 'consistency',  'column' => true,  'row' => false,
                          'what' => 'Every value in the column exists in another dataset\'s column.'],
    'schema'          => ['label' => 'Schema',          'icon' => 'feather-columns',     'dimension' => 'conformity',   'column' => false, 'row' => false,
                          'what' => 'No breaking change to the columns waits for a decision.'],
    'custom_sql'      => ['label' => 'Custom SQL',      'icon' => 'feather-terminal',    'dimension' => 'validity',     'column' => false, 'row' => false,
                          'what' => 'A SELECT over this dataset that returns the failing rows — none passes.'],
];
const CHECK_DIMENSIONS = ['accuracy', 'completeness', 'conformity', 'consistency', 'coverage', 'timeliness', 'uniqueness', 'validity'];
const CHECK_WHEN = ['on_load' => 'On every load', 'hourly' => 'Every hour', 'daily' => 'Every day'];
/** The row-level kinds: a promotion counts them on the rows it promotes (a blocking one quarantines its failures). */
const CHECK_ROW_KINDS = ['not_null', 'accepted_values', 'range', 'regex'];

/** A number in words for a threshold (10000 → "10,000"; 0.5 → "0.5"). */
function check_num(mixed $n): string
{
    if ($n === null || $n === '') {
        return '—';
    }
    $f = (float) $n;
    return floor($f) === $f && abs($f) < 1e15 ? number_format($f, 0) : rtrim(rtrim(number_format($f, 6, '.', ','), '0'), '.');
}

/** A check in words (the checks table, the incident page, the Quality tab). */
function check_words(array $check): string
{
    $p = is_array($check['params'] ?? null) ? $check['params'] : (json_decode((string) ($check['params'] ?? '{}'), true) ?: []);
    $col = (string) ($check['column_name'] ?? $check['column'] ?? '');
    $on = $col === '' ? '' : ' (' . $col . ')';
    switch ((string) $check['kind']) {
        case 'not_null':
            $m = (int) ($p['max_failures'] ?? 0);
            return 'not null' . $on . ($m > 0 ? ', up to ' . check_num($m) . ' missing' : '');
        case 'unique':
            return 'unique' . (isset($p['columns']) && is_array($p['columns']) && $p['columns'] !== [] ? ' (' . implode(', ', $p['columns']) . ')' : $on);
        case 'accepted_values':
            $v = array_map('strval', (array) ($p['values'] ?? []));
            return 'one of ' . (count($v) > 6 ? implode(', ', array_slice($v, 0, 6)) . ' … (' . count($v) . ')' : implode(', ', $v)) . $on;
        case 'range':
            $lo = $p['min'] ?? null;
            $hi = $p['max'] ?? null;
            return ($lo !== null && $hi !== null ? 'between ' . check_num($lo) . ' and ' . check_num($hi)
                : ($lo !== null ? 'at least ' . check_num($lo) : ($hi !== null ? 'at most ' . check_num($hi) : 'any value'))) . $on;
        case 'regex':
            return 'matches ' . (string) ($p['pattern'] ?? '') . $on;
        case 'row_count':
            $parts = [];
            if (($p['min'] ?? null) !== null && ($p['max'] ?? null) !== null) {
                $parts[] = 'between ' . check_num($p['min']) . ' and ' . check_num($p['max']) . ' rows';
            } elseif (($p['min'] ?? null) !== null) {
                $parts[] = 'at least ' . check_num($p['min']) . ' rows';
            } elseif (($p['max'] ?? null) !== null) {
                $parts[] = 'at most ' . check_num($p['max']) . ' rows';
            }
            if (($p['change_pct'] ?? null) !== null) {
                $parts[] = 'row count within ±' . check_num($p['change_pct']) . ' % of the last load';
            }
            return $parts === [] ? 'row count recorded' : implode('; ', $parts);
        case 'freshness':
            return ($p['max_age_hours'] ?? null) !== null ? 'data no older than ' . check_num($p['max_age_hours']) . ' hours' : 'data within its freshness expectation';
        case 'referential':
            return 'every value' . $on . ' exists in ' . (string) ($p['dataset_key'] ?? '?') . '.' . (string) ($p['column'] ?? '?');
        case 'schema':
            return 'no breaking schema change waiting';
        case 'custom_sql':
            return 'custom SQL finds no failing row';
    }
    return (string) $check['kind'];
}

/**
 * A kind's params from the request: the form's fields (`values` one per line or comma-separated, `min`, `max`, `pattern`, `change_pct`,
 * `max_age_hours`, `dataset_key` + `ref_column`, `sql`, `max_failures`) or an agent's `params` (a JSON object). Field errors go to $errors.
 */
function check_params_from_request(string $kind, array &$errors, ?array $keep = null): array
{
    $given = $_POST['params'] ?? null;
    if (is_string($given) && trim($given) !== '') {
        $given = json_decode($given, true);
        if (!is_array($given)) {
            $errors['params'] = 'The params are a JSON object as the kind needs.';
            return $keep ?? [];
        }
    }
    $src = is_array($given) ? $given : [];
    $field = static function (string $name) use ($src): mixed {
        if (array_key_exists($name, $src)) {
            return $src[$name];
        }
        return array_key_exists($name, $_POST) && !is_array($_POST[$name]) ? trim((string) $_POST[$name]) : null;
    };
    $num = static function (string $name, string $label) use ($field, &$errors): int|float|null {
        $v = $field($name);
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            $errors[$name] = $label . ' is a number.';
            return null;
        }
        return str_contains((string) $v, '.') || str_contains(strtolower((string) $v), 'e') ? (float) $v : (int) $v;
    };
    $p = [];
    switch ($kind) {
        case 'not_null':
            $m = $num('max_failures', 'The allowance');
            if ($m !== null) {
                $p['max_failures'] = max(0, (int) $m);
            }
            break;
        case 'unique':
            break;
        case 'accepted_values':
            $v = $field('values');
            $list = is_array($v) ? $v : preg_split('/\R|,/', (string) $v);
            $list = array_values(array_unique(array_filter(array_map(static fn ($x): string => trim((string) $x), $list), static fn (string $x): bool => $x !== '')));
            if ($list === []) {
                $errors['values'] = 'List the accepted values, one per line.';
            } elseif (count($list) > 500) {
                $errors['values'] = 'Up to 500 accepted values.';
            }
            $p['values'] = $list;
            break;
        case 'range':
            $lo = $num('min', 'The minimum');
            $hi = $num('max', 'The maximum');
            if ($lo === null && $hi === null && !isset($errors['min']) && !isset($errors['max'])) {
                $errors['min'] = 'Give a minimum, a maximum or both.';
            } elseif ($lo !== null && $hi !== null && $lo > $hi) {
                $errors['max'] = 'The maximum is at least the minimum.';
            }
            $p += array_filter(['min' => $lo, 'max' => $hi], static fn ($x) => $x !== null);
            break;
        case 'regex':
            $pat = (string) ($field('pattern') ?? '');
            if ($pat === '' || mb_strlen($pat) > 500) {
                $errors['pattern'] = 'The pattern is 1 to 500 characters.';
            } elseif (@preg_match('/' . str_replace('/', '\/', $pat) . '/u', '') === false) {
                $errors['pattern'] = 'That pattern is not a regular expression.';
            }
            $p['pattern'] = $pat;
            break;
        case 'row_count':
            $lo = $num('min', 'The minimum');
            $hi = $num('max', 'The maximum');
            $ch = $num('change_pct', 'The change');
            if ($lo === null && $hi === null && $ch === null && !isset($errors['min'], $errors['max'], $errors['change_pct'])) {
                $errors['min'] = 'Give bounds, a change in percent, or both.';
            }
            if ($ch !== null && ($ch <= 0 || $ch > 1000)) {
                $errors['change_pct'] = 'The change is a percent above 0, up to 1,000.';
            }
            $p += array_filter(['min' => $lo, 'max' => $hi, 'change_pct' => $ch], static fn ($x) => $x !== null);
            break;
        case 'freshness':
            $h = $num('max_age_hours', 'The age');
            if ($h !== null && ($h <= 0 || $h > 24 * 366)) {
                $errors['max_age_hours'] = 'The age is in hours, above 0 and up to a year.';
            }
            if ($h !== null) {
                $p['max_age_hours'] = $h;
            }
            break;
        case 'referential':
            $ds = (string) ($field('dataset_key') ?? '');
            $col = (string) ($field('ref_column') ?? $field('column_ref') ?? ($src['column'] ?? ''));
            if ($ds === '') {
                $errors['dataset_key'] = 'Name the dataset the values must exist in.';
            }
            if ($col === '') {
                $errors['ref_column'] = 'Name its column.';
            }
            $p['dataset_key'] = $ds;
            $p['column'] = $col;
            break;
        case 'schema':
            break;
        case 'custom_sql':
            $sql = (string) ($field('sql') ?? '');
            if ($sql === '' || mb_strlen($sql) > 4000) {
                $errors['sql'] = 'The SQL is 1 to 4,000 characters: a SELECT returning the failing rows.';
            }
            $p['sql'] = $sql;
            break;
    }
    return $p;
}
