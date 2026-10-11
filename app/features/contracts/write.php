<?php
declare(strict_types=1);

/**
 * Contracts — the writes (contract_draft_save, contract_publish; contracts-quality.md). A draft is the next version, freely changed; publishing
 * it is the database's (dl_contract_publish — db/010: a breaking change must be a new major, the previous active deprecated, the dataset's key and
 * ordering recorded, certification rechecked), then the lineage edge raw → curated (`promote`). A published contract is frozen (a trigger).
 */
require_once __DIR__ . '/queries.php';

/**
 * A draft's fields checked: [clean, errors]. $f: version, schema (list of {name, logical_type, physical_type, required, unique, primary_key,
 * source, description, classification, personal_kind, critical}), key (list; empty → the primary-key columns), ordering_column, sla (object of
 * ISO 8601 durations), contract (the draft being changed). Errors name the field: version, schema, key, ordering_column, sla.<name>,
 * columns.<i>.<field>.
 */
function contract_validate(PDO $pdo, int $dataset, array $f): array
{
    $errors = [];
    $version = trim((string) ($f['version'] ?? ''));
    if (!preg_match('/^\d{1,4}\.\d{1,5}\.\d{1,6}$/', $version)) {
        $errors['version'] = 'The version is major.minor.patch, as 1.0.0.';
    } else {
        $active = one_value($pdo, "SELECT version FROM mcp_contracts WHERE dataset_id = :d AND status = 'active'", ['d' => $dataset]);
        if ($active !== null && version_compare($version, (string) $active, '<=')) {
            $errors['version'] = $version . ' is not above the active ' . $active . '.';
        }
        $taken = one_value($pdo, "SELECT contract_id FROM mcp_contracts WHERE dataset_id = :d AND version = :v AND status <> 'draft'", ['d' => $dataset, 'v' => $version]);
        if ($taken !== null) {
            $errors['version'] = $version . ' is already a published version.';
        }
    }
    $schema = [];
    $raw = null;
    $names = [];
    $rows = is_array($f['schema'] ?? null) ? array_values($f['schema']) : [];
    if ($rows === []) {
        $errors['schema'] = 'A contract has at least one column.';
    }
    if (count($rows) > 500) {
        $errors['schema'] = 'Up to 500 columns.';
        $rows = [];
    }
    $rawCols = [];
    foreach ($rows as $i => $e) {
        $e = is_array($e) ? $e : [];
        $name = trim((string) ($e['name'] ?? ''));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $name) || str_starts_with(strtolower($name), '_dl_')) {
            $errors["columns.$i.name"] = 'A column name is letters, digits and _ (not starting _dl_).';
            continue;
        }
        if (isset($names[strtolower($name)])) {
            $errors["columns.$i.name"] = 'Two columns are named ' . $name . '.';
            continue;
        }
        $names[strtolower($name)] = $name;
        $phys = contract_physical((string) ($e['physical_type'] ?? 'VARCHAR'));
        if ($phys === null) {
            $errors["columns.$i.physical_type"] = $name . ': the type is one of VARCHAR, BIGINT, INTEGER, DECIMAL(p,s), DOUBLE, BOOLEAN, DATE, TIMESTAMP, TIMESTAMPTZ.';
            continue;
        }
        $logical = (string) ($e['logical_type'] ?? '') ?: contract_logical_of($phys);
        if (!in_array($logical, CONTRACT_LOGICAL, true)) {
            $errors["columns.$i.logical_type"] = $name . ': the logical type is one of ' . implode(', ', CONTRACT_LOGICAL) . '.';
            continue;
        }
        $source = trim((string) ($e['source'] ?? ''));
        $parts = explode('.', $source);
        if (count($parts) !== 3 || $parts[0] !== 'raw') {
            $errors["columns.$i.source"] = $name . ': its source is a raw column, as raw.orders.order_id.';
            continue;
        }
        $rk = $parts[0] . '.' . $parts[1];
        if ($raw !== null && $rk !== $raw) {
            $errors['schema'] = 'A curated dataset promotes from one raw dataset — two inputs are a transform';
            continue;
        }
        $raw = $rk;
        $schema[] = ['name' => $name, 'logical_type' => $logical, 'physical_type' => $phys, 'required' => contract_yes($e['required'] ?? false),
            'unique' => contract_yes($e['unique'] ?? false), 'primary_key' => contract_yes($e['primary_key'] ?? false), 'source' => $source,
            'description' => ($d = trim((string) ($e['description'] ?? ''))) === '' ? null : mb_substr($d, 0, 4000),
            'classification' => in_array($e['classification'] ?? null, ['public', 'internal', 'confidential', 'restricted'], true) ? $e['classification'] : null,
            'personal_kind' => ($e['personal_kind'] ?? '') === '' ? null : (string) $e['personal_kind'], 'critical' => contract_yes($e['critical'] ?? false)];
        $rawCols[$i] = $parts[2];
    }
    if ($raw !== null) {
        $rawId = one_value($pdo, "SELECT dataset_id FROM mcp_datasets WHERE key = :k AND zone = 'raw' AND NOT is_stub AND status <> 'retired'", ['k' => $raw]);
        if ($rawId === null) {
            $errors['schema'] = 'There is no raw dataset ' . $raw . ' you may see.';
        } else {
            $live = array_map('strtolower', array_keys(live_columns_of($pdo, (int) $rawId)));
            foreach ($rawCols as $i => $c) {
                if (!in_array(strtolower($c), $live, true)) {
                    $errors["columns.$i.source"] = $raw . ' has no column ' . $c . '.';
                }
            }
        }
    }
    $key = array_values(array_filter(array_map('strval', (array) ($f['key'] ?? [])), static fn (string $x): bool => $x !== ''));
    if ($key === []) {
        $key = array_values(array_map(static fn (array $e): string => $e['name'], array_filter($schema, static fn (array $e): bool => $e['primary_key'])));
    }
    if ($key === [] && $schema !== []) {
        $errors['key'] = 'Name the key — the columns that identify a row (promotion keeps the latest row of each).';
    }
    foreach ($key as $k) {
        if (!in_array($k, $names, true)) {
            $errors['key'] = 'The key names ' . $k . ', which is not a column of the contract.';
        }
    }
    foreach ($schema as &$e) {
        $e['primary_key'] = in_array($e['name'], $key, true);
    }
    unset($e);
    $ordering = trim((string) ($f['ordering_column'] ?? ''));
    $ordering = $ordering === '' ? null : $ordering;
    if ($ordering !== null && $ordering !== '_dl_loaded_at' && !in_array($ordering, $names, true)) {
        $errors['ordering_column'] = 'The ordering column is one of the contract\'s columns (or _dl_loaded_at, when the row arrived).';
    }
    $sla = [];
    foreach ((array) ($f['sla'] ?? []) as $k => $v) {
        $v = strtoupper(trim((string) $v));
        if ($v === '') {
            continue;
        }
        if (!isset(CONTRACT_SLA[$k])) {
            $errors['sla'] = 'The SLA names ' . implode(', ', array_keys(CONTRACT_SLA)) . '.';
            continue;
        }
        if (!preg_match('/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/', $v)) {
            $errors["sla.$k"] = CONTRACT_SLA[$k] . ' is an ISO 8601 duration, as P1D (a day) or PT6H (six hours).';
            continue;
        }
        $sla[$k] = $v;
    }
    return [['version' => $version, 'schema' => $schema, 'key' => $key, 'ordering_column' => $ordering, 'sla' => $sla, 'raw' => $raw,
             'contract' => isset($f['contract']) && $f['contract'] !== '' && $f['contract'] !== null ? (int) $f['contract'] : null], $errors];
}

function contract_yes(mixed $v): bool
{
    return $v === true || in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
}

/** Live column names of any dataset the caller may discover (mcp_dataset_columns): [name => type]. */
function live_columns_of(PDO $pdo, int $dataset): array
{
    $st = $pdo->prepare('SELECT name, physical_type FROM mcp_dataset_columns WHERE dataset_id = :d AND removed_snapshot_id IS NULL AND NOT is_system ORDER BY ordinal');
    $st->execute(['d' => $dataset]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(string) $r['name']] = (string) ($r['physical_type'] ?? '');
    }
    return $out;
}

/** Save the draft (insert, or update the dataset's one open draft): its id. Refuses in the database's words when a field is wrong. */
function contract_draft_save(PDO $pdo, int $dataset, array $f, int $by): int
{
    [$c, $errors] = contract_validate($pdo, $dataset, $f);
    if ($errors !== []) {
        throw new DomainException((string) reset($errors));
    }
    $draftId = $c['contract'];
    if ($draftId !== null) {
        $ok = one_value($pdo, "SELECT 1 FROM contracts WHERE id = :id AND dataset_id = :d AND status = 'draft'", ['id' => $draftId, 'd' => $dataset]);
        if ($ok === null) {
            throw new DomainException('That draft is not this dataset\'s, or it was published — draft a new version.');
        }
    } else {
        $draftId = ($v = one_value($pdo, "SELECT id FROM contracts WHERE dataset_id = :d AND status = 'draft' ORDER BY created_at DESC LIMIT 1", ['d' => $dataset])) === null ? null : (int) $v;
    }
    $args = ['v' => $c['version'], 's' => json_encode($c['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'k' => pg_array_literal($c['key']),
             'o' => $c['ordering_column'], 'sla' => json_encode((object) $c['sla'], JSON_UNESCAPED_SLASHES)];
    if ($draftId === null) {
        $st = $pdo->prepare('INSERT INTO contracts (dataset_id, version, status, schema, key, ordering_column, sla, description, created_by)
                             SELECT :d, :v, \'draft\', CAST(:s AS jsonb), CAST(:k AS text[]), :o, CAST(:sla AS jsonb),
                                    jsonb_strip_nulls(jsonb_build_object(\'purpose\', d.purpose, \'usage\', d.usage, \'limitations\', d.limitations)), :by
                               FROM datasets d WHERE d.id = :d RETURNING id');
        $st->execute($args + ['d' => $dataset, 'by' => $by]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE contracts SET version = :v, schema = CAST(:s AS jsonb), key = CAST(:k AS text[]), ordering_column = :o, sla = CAST(:sla AS jsonb)
                    WHERE id = :id')->execute($args + ['id' => $draftId]);
    return $draftId;
}

/** Publish a draft (dl_contract_publish decides), then record the edge raw → curated: ['version', 'breaking', 'raw']. */
function contract_publish(PDO $pdo, int $contract, int $by): array
{
    $r = json_decode((string) one_value($pdo, 'SELECT dl_contract_publish(:id)', ['id' => $contract]), true) ?: [];
    $row = one_row($pdo, 'SELECT dataset_id, schema FROM contracts WHERE id = :id', ['id' => $contract]);
    $raw = $row === null ? null : contract_raw_key(json_decode((string) $row['schema'], true) ?: []);
    if ($raw !== null) {
        $rawId = one_value($pdo, 'SELECT id FROM datasets WHERE key = :k', ['k' => $raw]);
        if ($rawId !== null) {
            $pdo->prepare("SELECT dl_lineage_edge(:f, NULL, :t, 'promote')")->execute(['f' => (int) $rawId, 't' => (int) $row['dataset_id']]);
        }
    }
    return ['version' => (string) ($r['version'] ?? ''), 'breaking' => (array) ($r['breaking'] ?? []), 'raw' => $raw];
}

/**
 * The draft's fields from a request: `schema` as a JSON list (an agent, the API) or the form's rows `columns[i][name|logical_type|physical_type|
 * required|unique|primary_key|source|description|classification|personal_kind]` with `raw` naming the raw dataset (a row's source is then its
 * raw column's name; a row whose name is empty is left out — that is how a column is dropped), key[] (else the rows ticked primary key),
 * ordering_column, sla[name] (ISO 8601 durations), version, contract. $src: $_POST or $_GET (the live fragment).
 */
function contract_fields_from_request(array $src): array
{
    $schema = [];
    if (isset($src['schema'])) {
        $s = is_array($src['schema']) ? $src['schema'] : json_decode((string) $src['schema'], true);
        $schema = is_array($s) ? array_values(array_filter($s, 'is_array')) : [];
    } else {
        $raw = trim((string) ($src['raw'] ?? ''));
        foreach ((array) ($src['columns'] ?? []) as $row) {
            if (!is_array($row) || trim((string) ($row['name'] ?? '')) === '') {
                continue;
            }
            $source = trim((string) ($row['source'] ?? ''));
            if ($source !== '' && substr_count($source, '.') === 0 && $raw !== '') {
                $source = $raw . '.' . $source;
            }
            $schema[] = ['name' => trim((string) $row['name']), 'logical_type' => (string) ($row['logical_type'] ?? ''), 'physical_type' => (string) ($row['physical_type'] ?? 'VARCHAR'),
                'required' => !empty($row['required']), 'unique' => !empty($row['unique']), 'primary_key' => !empty($row['primary_key']), 'source' => $source,
                'description' => (string) ($row['description'] ?? ''), 'classification' => (string) ($row['classification'] ?? ''), 'personal_kind' => (string) ($row['personal_kind'] ?? '')];
        }
    }
    $key = $src['key'] ?? [];
    $key = is_array($key) ? $key : array_filter(array_map('trim', explode(',', (string) $key)));
    $sla = $src['sla'] ?? [];
    $sla = is_array($sla) ? $sla : (json_decode((string) $sla, true) ?: []);
    return ['version' => trim((string) ($src['version'] ?? '')), 'schema' => $schema, 'key' => array_values(array_map('strval', $key)),
            'ordering_column' => trim((string) ($src['ordering_column'] ?? '')), 'sla' => $sla, 'contract' => $src['contract'] ?? null];
}
