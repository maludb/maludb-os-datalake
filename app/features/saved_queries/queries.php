<?php
declare(strict_types=1);

/**
 * Saved queries (slice 3 — query-service-editor.md): mine and shared, VERIFIED by an engineer and unverified BY THEMSELVES when their SQL
 * changes (db/011's trigger — Metabase's rule, D9). Every read through mcp_saved_queries (the wall: my own, or shared and every dataset it
 * reads readable by me). A run is the editor's run_sql() with the query's id in the audit; its parameters bound by the service as literals.
 *
 *   find_saved_queries($pdo, $filters, $page)      [rows, total] — q (title / question), verified (yes | no), dataset (an id), owner (an id)
 *   find_saved_query($pdo, $id)                    the query with its datasets (keys I may discover), owner and verifier — or null
 *   saved_query_bind($defs, $given, &$errors)      the values for a run, each by its type (text, integer, number, date), defaults filled
 *   run_saved_query($pdo, $member, $id, $params)   as run_sql() + saved_query_id (null when the wall hides the query)
 *   saved_query_params_from_request()              the parameters' definitions from a form (rows) or an agent (JSON)
 */

const SAVED_QUERY_PAGE = 25;
const PARAM_TYPES = ['text' => 'Text', 'integer' => 'Whole number', 'number' => 'Number', 'date' => 'Date'];
/** The allow-list's rules: a SQL refused by one of these can never run, so it is never saved (a policy refusal is saved and shown). */
const SHAPE_RULES = ['empty', 'too_long', 'parse', 'one_statement', 'select_only', 'too_complex', 'function', 'table_function', 'catalog_reference',
                     'unknown_table', 'time_travel', 'unbound_parameter'];

function find_saved_queries(PDO $pdo, array $f, int $page): array
{
    $where = ['true'];
    $args = [];
    if (($f['q'] ?? '') !== '') {
        $where[] = '(q.title ILIKE :q OR q.question ILIKE :q OR q.description ILIKE :q)';
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
    }
    if (($f['verified'] ?? '') === 'yes') {
        $where[] = 'q.verified_at IS NOT NULL';
    } elseif (($f['verified'] ?? '') === 'no') {
        $where[] = 'q.verified_at IS NULL';
    }
    if (isset($f['dataset'])) {
        $where[] = ':ds = ANY (q.dataset_ids)';
        $args['ds'] = (int) $f['dataset'];
    }
    if (isset($f['owner'])) {
        $where[] = 'q.owner_member_id = :owner';
        $args['owner'] = (int) $f['owner'];
    }
    $w = implode(' AND ', $where);
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_saved_queries q WHERE $w", $args);
    $st = $pdo->prepare("SELECT q.*, o.display_name AS owner_name, v.display_name AS verified_by_name,
                                (SELECT COALESCE(json_agg(json_build_object('dataset_id', d.dataset_id, 'key', d.key) ORDER BY d.key), '[]')
                                   FROM mcp_datasets d WHERE d.dataset_id = ANY (q.dataset_ids) AND NOT d.is_stub)::text AS datasets_json
                           FROM mcp_saved_queries q LEFT JOIN mcp_members o ON o.member_id = q.owner_member_id LEFT JOIN mcp_members v ON v.member_id = q.verified_by
                          WHERE $w ORDER BY q.verified_at IS NULL, q.updated_at DESC LIMIT " . SAVED_QUERY_PAGE . ' OFFSET ' . (max(1, $page) - 1) * SAVED_QUERY_PAGE);
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}

function find_saved_query(PDO $pdo, int $id): ?array
{
    $r = one_row($pdo, "SELECT q.*, o.display_name AS owner_name, v.display_name AS verified_by_name,
                               (SELECT COALESCE(json_agg(json_build_object('dataset_id', d.dataset_id, 'key', d.key) ORDER BY d.key), '[]')
                                  FROM mcp_datasets d WHERE d.dataset_id = ANY (q.dataset_ids) AND NOT d.is_stub)::text AS datasets_json
                          FROM mcp_saved_queries q LEFT JOIN mcp_members o ON o.member_id = q.owner_member_id LEFT JOIN mcp_members v ON v.member_id = q.verified_by
                         WHERE q.saved_query_id = :id", ['id' => $id]);
    if ($r === null) {
        return null;
    }
    $r['parameters'] = json_decode((string) $r['parameters'], true) ?: [];
    $r['datasets'] = json_decode((string) $r['datasets_json'], true) ?: [];
    return $r;
}

/** The values a run binds: each parameter by its type; an empty value takes the default; a required one with neither is a field error. */
function saved_query_bind(array $defs, array $given, array &$errors): array
{
    $out = [];
    foreach ($defs as $p) {
        $name = (string) $p['name'];
        $raw = array_key_exists($name, $given) ? trim((string) $given[$name]) : '';
        if ($raw === '') {
            $raw = (string) ($p['default'] ?? '');
        }
        if ($raw === '') {
            if (!empty($p['required'])) {
                $errors['p_' . $name] = 'Give ' . $name . ' a value.';
            } else {
                $out[$name] = null;
            }
            continue;
        }
        switch ($p['type'] ?? 'text') {
            case 'integer':
                if (!preg_match('/^-?\d{1,18}$/', $raw)) {
                    $errors['p_' . $name] = $name . ' is a whole number.';
                    continue 2;
                }
                $out[$name] = (int) $raw;
                break;
            case 'number':
                if (!is_numeric($raw)) {
                    $errors['p_' . $name] = $name . ' is a number.';
                    continue 2;
                }
                $out[$name] = (float) $raw;
                break;
            case 'date':
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
                if ($d === false || $d->format('Y-m-d') !== $raw) {
                    $errors['p_' . $name] = $name . ' is a date (YYYY-MM-DD).';
                    continue 2;
                }
                $out[$name] = $raw;
                break;
            default:
                $out[$name] = mb_substr($raw, 0, 1000);
        }
    }
    return $out;
}

/** Placeholder values for a dry run (every parameter bound, so the allow-list judges the SQL and not a missing value). */
function saved_query_probe_values(array $defs): array
{
    $out = [];
    foreach ($defs as $p) {
        $d = (string) ($p['default'] ?? '');
        $out[(string) $p['name']] = match ($p['type'] ?? 'text') {
            'integer' => $d !== '' && preg_match('/^-?\d+$/', $d) ? (int) $d : 0,
            'number' => $d !== '' && is_numeric($d) ? (float) $d : 0,
            'date' => $d !== '' ? $d : '2000-01-01',
            default => $d,
        };
    }
    return $out;
}

function run_saved_query(PDO $pdo, int $member, int $id, array $params, array $opts = []): ?array
{
    $q = find_saved_query($pdo, $id);
    if ($q === null) {
        return null;
    }
    return run_sql($pdo, $member, (string) $q['sql'], $opts['as_of'] ?? null, $params, ['saved_query' => $q] + $opts);
}

/** The parameters' definitions: a form's rows (param_name[], param_type[], param_default[], param_required[]) or an agent's JSON list. */
function saved_query_params_from_request(array &$errors): ?array
{
    if (array_key_exists('parameters', $_POST) && !is_array($_POST['parameters'])) {
        $raw = trim((string) $_POST['parameters']);
        $list = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($list)) {
            $errors['parameters'] = 'The parameters are a list of {name, type, default, required}.';
            return [];
        }
    } elseif (array_key_exists('param_name', $_POST)) {
        $list = [];
        foreach ((array) $_POST['param_name'] as $i => $n) {
            $list[] = ['name' => $n, 'type' => $_POST['param_type'][$i] ?? 'text', 'default' => $_POST['param_default'][$i] ?? '',
                       'required' => in_array((string) ($_POST['param_required'][$i] ?? ''), ['1', 'on', 'yes', 'true'], true)];
        }
    } else {
        return null;                                              // left out: kept as it was
    }
    $out = [];
    $seen = [];
    foreach ($list as $p) {
        $name = trim((string) ($p['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name)) {
            $errors['parameters'] = 'A parameter\'s name is lower-case letters, digits and underscores, starting with a letter ($' . $name . ' is not).';
            continue;
        }
        if (isset($seen[$name])) {
            $errors['parameters'] = 'Two parameters are named ' . $name . '.';
            continue;
        }
        $seen[$name] = true;
        $type = (string) ($p['type'] ?? 'text');
        if (!isset(PARAM_TYPES[$type])) {
            $errors['parameters'] = 'A parameter\'s type is text, integer, number or date.';
            continue;
        }
        $out[] = ['name' => $name, 'type' => $type, 'default' => mb_substr(trim((string) ($p['default'] ?? '')), 0, 200), 'required' => !empty($p['required'])];
    }
    if (count($out) > 20) {
        $errors['parameters'] = 'A query takes at most 20 parameters.';
    }
    return $out;
}
