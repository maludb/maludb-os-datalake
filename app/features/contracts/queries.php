<?php
declare(strict_types=1);

/**
 * Contracts — the reads (contracts-quality.md; the records tool `get_contract`). A curated dataset's contract in ODCS's words: the schema
 * (name, logical and physical type, required, unique, primary key, the raw column it reads — `source` = `{raw key}.{column}`, divergence 1 —,
 * description, classification, personal kind), the key, the ordering column, the SLA as ISO durations. The database decides what is breaking
 * (dl_contract_breaking_preview — db/024 — for a schema not saved yet; dl_contract_breaking_changes for a draft) and what a publish does.
 */

const CONTRACT_PHYSICAL = ['VARCHAR', 'BIGINT', 'INTEGER', 'DOUBLE', 'BOOLEAN', 'DATE', 'TIMESTAMP', 'TIMESTAMPTZ'];   // + DECIMAL(p,s)
const CONTRACT_LOGICAL = ['string', 'integer', 'number', 'boolean', 'date', 'timestamp', 'object', 'array'];
const CONTRACT_SLA = ['freshness' => 'Freshness', 'retention' => 'Retention', 'time_to_detect' => 'Time to detect', 'time_to_notify' => 'Time to notify',
                      'time_to_repair' => 'Time to repair'];

/** A physical type the promotion can cast to, normalised (`decimal(12, 2)` → `DECIMAL(12,2)`), or null. */
function contract_physical(string $t): ?string
{
    $t = strtoupper(preg_replace('/\s+/', '', trim($t)) ?? '');
    if (in_array($t, CONTRACT_PHYSICAL, true)) {
        return $t;
    }
    if (preg_match('/^DECIMAL\((\d{1,2}),(\d{1,2})\)$/', $t, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 38 && (int) $m[2] <= (int) $m[1]) {
        return 'DECIMAL(' . (int) $m[1] . ',' . (int) $m[2] . ')';
    }
    return null;
}

/** The logical type a physical one implies (the form's default). */
function contract_logical_of(string $physical): string
{
    $p = strtoupper($physical);
    return match (true) {
        str_starts_with($p, 'DECIMAL'), $p === 'DOUBLE' => 'number',
        in_array($p, ['BIGINT', 'INTEGER'], true) => 'integer',
        $p === 'BOOLEAN' => 'boolean',
        $p === 'DATE' => 'date',
        str_starts_with($p, 'TIMESTAMP') => 'timestamp',
        default => 'string',
    };
}

function contract_decode(array $r): array
{
    foreach (['schema', 'sla', 'description', 'breaking_changes'] as $c) {
        if (array_key_exists($c, $r) && !is_array($r[$c])) {
            $r[$c] = json_decode((string) ($r[$c] ?? 'null'), true) ?? ($c === 'schema' || $c === 'breaking_changes' ? [] : []);
        }
    }
    if (array_key_exists('key', $r) && !is_array($r['key'])) {
        $r['key'] = pg_text_array((string) $r['key']);
    }
    $r['breaking'] = ($r['breaking'] ?? false) === true || ($r['breaking'] ?? '') === 't';
    return $r;
}

/** The active contract — or a version — of a dataset the caller may discover; with no active one, the newest draft. */
function find_contract(PDO $pdo, int $dataset, ?string $version): ?array
{
    $sql = 'SELECT k.*, (SELECT display_name FROM mcp_members m WHERE m.member_id = k.published_by) AS published_by_name FROM mcp_contracts k WHERE k.dataset_id = :d';
    $args = ['d' => $dataset];
    if ($version !== null && $version !== '') {
        $sql .= ' AND k.version = :v';
        $args['v'] = $version;
    } else {
        $sql .= " ORDER BY (k.status = 'active') DESC, (k.status = 'draft') DESC, k.created_at DESC LIMIT 1";
    }
    $r = one_row($pdo, $sql, $args);
    return $r === null ? null : contract_decode($r);
}

/** Every version, newest first: [{contract_id, version, status, breaking, breaking_changes, published_at, created_at}]. */
function contract_versions(PDO $pdo, int $dataset): array
{
    $st = $pdo->prepare("SELECT contract_id, version, status, breaking, breaking_changes, published_at, created_at FROM mcp_contracts WHERE dataset_id = :d
                          ORDER BY string_to_array(version, '.')::int[] DESC, created_at DESC");
    $st->execute(['d' => $dataset]);
    return array_map('contract_decode', $st->fetchAll());
}

/** The dataset's open draft (one at a time), or null. */
function contract_draft(PDO $pdo, int $dataset): ?array
{
    $r = one_row($pdo, "SELECT * FROM mcp_contracts WHERE dataset_id = :d AND status = 'draft' ORDER BY created_at DESC LIMIT 1", ['d' => $dataset]);
    return $r === null ? null : contract_decode($r);
}

/** The raw dataset a contract promotes from (every source names it — divergence 1): its key, or null. */
function contract_raw_key(array $schema): ?string
{
    $keys = [];
    foreach ($schema as $e) {
        $parts = explode('.', (string) ($e['source'] ?? ''));
        if (count($parts) === 3) {
            $keys[$parts[0] . '.' . $parts[1]] = true;
        }
    }
    return count($keys) === 1 ? (string) array_key_first($keys) : null;
}

/**
 * The next version's prefill (screen `contract-edit`): the open draft as it stands; else the active contract (its version bumped as a patch);
 * else the raw input's live columns — each a column of the same name reading it, typed by the latest load's confirmed mapping where that named
 * a type (VARCHAR otherwise), its personal kind and classification carried, the provenance columns left out. $rawDataset picks the raw input.
 */
function contract_draft_from(PDO $pdo, int $dataset, ?int $rawDataset): array
{
    if (($draft = contract_draft($pdo, $dataset)) !== null) {
        return ['version' => $draft['version'], 'schema' => $draft['schema'], 'key' => $draft['key'], 'ordering_column' => $draft['ordering_column'],
                'sla' => $draft['sla'], 'contract' => (int) $draft['contract_id'], 'raw' => contract_raw_key($draft['schema'])];
    }
    $active = one_row($pdo, "SELECT * FROM mcp_contracts WHERE dataset_id = :d AND status = 'active'", ['d' => $dataset]);
    if ($active !== null && $rawDataset === null) {
        $a = contract_decode($active);
        [$M, $m, $p] = array_map('intval', explode('.', (string) $a['version']));
        return ['version' => "$M.$m." . ($p + 1), 'schema' => $a['schema'], 'key' => $a['key'], 'ordering_column' => $a['ordering_column'], 'sla' => $a['sla'],
                'contract' => null, 'raw' => contract_raw_key($a['schema'])];
    }
    $out = ['version' => $active === null ? '1.0.0' : '', 'schema' => [], 'key' => [], 'ordering_column' => null, 'sla' => (object) [], 'contract' => null, 'raw' => null];
    if ($rawDataset === null) {
        return $out;
    }
    $raw = one_row($pdo, "SELECT dataset_id, key FROM mcp_datasets WHERE dataset_id = :d AND zone = 'raw' AND NOT is_stub", ['d' => $rawDataset]);
    if ($raw === null) {
        return $out;
    }
    $out['raw'] = (string) $raw['key'];
    $hint = [];
    $map = one_value($pdo, "SELECT mapping FROM loads WHERE dataset_id = :d AND status = 'loaded' AND mapping IS NOT NULL ORDER BY finished_at DESC NULLS LAST, id DESC LIMIT 1",
        ['d' => $rawDataset]);
    foreach ((array) ((json_decode((string) $map, true) ?: [])['columns'] ?? []) as $c) {
        if (is_array($c) && empty($c['skip']) && !empty($c['name']) && !empty($c['type'])) {
            $hint[(string) $c['name']] = (string) $c['type'];
        }
    }
    $st = $pdo->prepare('SELECT name, description, classification, personal_kind, is_key FROM mcp_dataset_columns WHERE dataset_id = :d AND removed_snapshot_id IS NULL
                          AND NOT is_system ORDER BY ordinal');
    $st->execute(['d' => $rawDataset]);
    foreach ($st->fetchAll() as $c) {
        $phys = contract_physical($hint[$c['name']] ?? 'VARCHAR') ?? 'VARCHAR';
        $out['schema'][] = ['name' => (string) $c['name'], 'logical_type' => contract_logical_of($phys), 'physical_type' => $phys, 'required' => false,
            'unique' => false, 'primary_key' => false, 'source' => $raw['key'] . '.' . $c['name'], 'description' => $c['description'],
            'classification' => $c['classification'], 'personal_kind' => $c['personal_kind']];
    }
    return $out;
}

/** What a publish reaches downstream (dl_impact): {datasets, saved_queries, metrics, consumers} — each list cut to what the caller may discover. */
function contract_impact(PDO $pdo, int $dataset): array
{
    $i = json_decode((string) one_value($pdo, 'SELECT dl_impact(:d)', ['d' => $dataset]), true) ?: [];
    $all = count((array) ($i['datasets'] ?? []));
    $visible = [];
    foreach ((array) ($i['datasets'] ?? []) as $x) {
        if (db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_datasets WHERE dataset_id = :id AND NOT is_stub)', ['id' => (int) $x['id']])) {
            $visible[] = $x;
        }
    }
    $i['datasets'] = $visible;
    $i['hidden'] = $all - count($visible);                    // downstream datasets the caller may not know of: counted, never named
    return $i;
}

/** The breaking changes of a schema not saved yet, against the active contract (the database's rule — db/024). */
function contract_breaking_preview(PDO $pdo, int $dataset, array $schema, array $key): array
{
    $v = one_value($pdo, 'SELECT dl_contract_breaking_preview(:d, CAST(:s AS jsonb), CAST(:k AS text[]))',
        ['d' => $dataset, 's' => json_encode(array_values($schema), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'k' => pg_array_literal(array_values($key))]);
    return json_decode((string) $v, true) ?: [];
}

/** The version a draft should carry: a major when breaking, a minor when a column is added, else a patch — over the active one. */
function contract_suggest_version(PDO $pdo, int $dataset, array $schema, array $breaking): string
{
    $active = one_row($pdo, "SELECT version, schema FROM mcp_contracts WHERE dataset_id = :d AND status = 'active'", ['d' => $dataset]);
    if ($active === null) {
        return '1.0.0';
    }
    [$M, $m, $p] = array_map('intval', explode('.', (string) $active['version']));
    if ($breaking !== []) {
        return ($M + 1) . '.0.0';
    }
    $old = array_column(json_decode((string) $active['schema'], true) ?: [], 'name');
    foreach ($schema as $e) {
        if (!in_array($e['name'] ?? null, $old, true)) {
            return "$M." . ($m + 1) . '.0';
        }
    }
    return "$M.$m." . ($p + 1);
}

/** The pending schema changes of the raw input a curated dataset promotes from (what drift_decide decides). */
function contract_pending_drift(PDO $pdo, int $dataset): array
{
    $k = one_row($pdo, "SELECT schema FROM mcp_contracts WHERE dataset_id = :d AND status = 'active'", ['d' => $dataset]);
    $raw = $k === null ? null : contract_raw_key(json_decode((string) $k['schema'], true) ?: []);
    if ($raw === null) {
        return [];
    }
    $st = $pdo->prepare("SELECT v.schema_version_id, v.dataset_id, d.key, v.version, v.changes, v.breaking, v.created_at FROM mcp_schema_versions v
                           JOIN mcp_datasets d ON d.dataset_id = v.dataset_id AND NOT d.is_stub WHERE d.key = :k AND v.decision = 'pending' ORDER BY v.version");
    $st->execute(['k' => $raw]);
    return array_map(static fn (array $r): array => ['changes' => json_decode((string) $r['changes'], true) ?: []] + $r, $st->fetchAll());
}

/** A raw dataset's live columns for the raw_column picker (params.dataset): dataset_columns ids, the column's name as its label. */
function raw_columns_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $where = "NOT c.is_system AND c.removed_snapshot_id IS NULL AND d.zone = 'raw' AND NOT d.is_stub AND (:q = '' OR c.name ILIKE :like)";
    $args = ['q' => $q, 'like' => picker_like($q)];
    if (($params['dataset'] ?? null) !== null) {
        $where .= ' AND c.dataset_id = :ds';
        $args['ds'] = (int) $params['dataset'];
    }
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_dataset_columns c JOIN mcp_datasets d ON d.dataset_id = c.dataset_id WHERE $where",
        "SELECT c.column_id, c.name, c.physical_type, d.key FROM mcp_dataset_columns c JOIN mcp_datasets d ON d.dataset_id = c.dataset_id WHERE $where ORDER BY d.key, c.ordinal", $args, $page);
    $r['rows'] = array_map(static fn (array $c): array => ['id' => (int) $c['column_id'], 'label' => (string) $c['name'], 'detail' => $c['key'] . ' · ' . ($c['physical_type'] ?? ''),
        'badge' => null, 'color' => 'secondary'], $r['rows']);
    return $r;
}
