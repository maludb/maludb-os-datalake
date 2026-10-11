<?php
declare(strict_types=1);

/**
 * The contract as an Open Data Contract Standard (ODCS) v3.2.0 document — our own small writer (★32, no library) and the reader of what it
 * writes (the contract form's "Import ODCS YAML", so a contract kept in a repository comes back as a draft). The writer emits block YAML whose
 * every scalar is a JSON value (a JSON string is a YAML double-quoted scalar, a JSON array a YAML flow sequence): any YAML 1.2 parser reads it,
 * and odcs_parse() reads exactly that subset back. Mapping: our columns → schema[0].properties (name, logicalType, physicalType, required,
 * unique, primaryKey + primaryKeyPosition, description, classification, criticalDataElement, transformSourceObjects / transformLogic for the raw
 * column), the key → primaryKey positions, the ordering column → customProperties, the SLA → slaProperties (property, value, unit), the
 * dataset's checks → each property's / the object's `quality` (library metrics where ODCS has one, else `sql`).
 */

/** A JSON scalar as a YAML scalar. */
function odcs_scalar(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}

/** Block YAML of a PHP value (maps and lists of maps in blocks; scalars and lists of scalars as JSON). */
function odcs_emit(mixed $v, int $indent = 0): string
{
    $pad = str_repeat('  ', $indent);
    $out = '';
    if (is_array($v) && array_is_list($v)) {
        foreach ($v as $item) {
            if (is_array($item) && !array_is_list($item) && $item !== []) {
                $first = true;
                foreach ($item as $k => $x) {
                    $lead = $first ? $pad . '- ' : $pad . '  ';
                    $first = false;
                    $out .= odcs_pair($lead, (string) $k, $x, $indent + 1);
                }
            } else {
                $out .= $pad . '- ' . odcs_scalar($item) . "\n";
            }
        }
        return $out;
    }
    foreach ((array) $v as $k => $x) {
        $out .= odcs_pair($pad, (string) $k, $x, $indent);
    }
    return $out;
}

function odcs_pair(string $lead, string $k, mixed $x, int $indent): string
{
    if (is_array($x) && $x !== [] && (!array_is_list($x) || (isset($x[0]) && is_array($x[0])))) {
        return $lead . $k . ":\n" . odcs_emit($x, $indent + 1);
    }
    return $lead . $k . ': ' . odcs_scalar($x === [] ? [] : $x) . "\n";
}

/** ISO 8601 duration → [value, unit] in ODCS's units (d, h, m, y …). */
function odcs_duration(string $iso): array
{
    if (preg_match('/^P(\d+)Y$/', $iso, $m)) {
        return [(int) $m[1], 'y'];
    }
    if (preg_match('/^P(\d+)M$/', $iso, $m)) {
        return [(int) $m[1], 'month'];
    }
    if (preg_match('/^P(\d+)W$/', $iso, $m)) {
        return [(int) $m[1] * 7, 'd'];
    }
    if (preg_match('/^P(\d+)D$/', $iso, $m)) {
        return [(int) $m[1], 'd'];
    }
    if (preg_match('/^PT(\d+)H$/', $iso, $m)) {
        return [(int) $m[1], 'h'];
    }
    if (preg_match('/^PT(\d+)M$/', $iso, $m)) {
        return [(int) $m[1], 'min'];
    }
    return [$iso, 'iso8601'];
}

/** [value, unit] → an ISO 8601 duration (odcs_duration's inverse). */
function odcs_iso(mixed $value, string $unit): ?string
{
    if ($unit === 'iso8601') {
        return is_string($value) ? $value : null;
    }
    $n = (int) $value;
    return match ($unit) { 'y', 'year', 'years' => "P{$n}Y", 'month', 'months' => "P{$n}M", 'd', 'day', 'days' => "P{$n}D",
        'h', 'hour', 'hours' => "PT{$n}H", 'min', 'minute', 'minutes' => "PT{$n}M", default => null };
}

/** An ODCS quality rule for one of our checks (library metrics where ODCS has one). */
function odcs_quality(array $c): array
{
    $p = (array) ($c['params'] ?? []);
    $base = ['name' => $c['kind'] . ($c['column_name'] ? '_' . $c['column_name'] : '') . '_' . (int) $c['check_id'], 'dimension' => $c['dimension'],
             'severity' => $c['severity'], 'description' => check_words($c)];
    return $base + match ((string) $c['kind']) {
        'not_null' => ['type' => 'library', 'metric' => 'nullValues', 'mustBeLessOrEqualTo' => (int) ($p['max_failures'] ?? 0)],
        'unique' => ['type' => 'library', 'metric' => 'duplicateValues', 'mustBe' => 0],
        'accepted_values' => ['type' => 'library', 'metric' => 'invalidValues', 'arguments' => ['validValues' => array_values((array) ($p['values'] ?? []))], 'mustBe' => 0],
        'regex' => ['type' => 'library', 'metric' => 'invalidValues', 'arguments' => ['pattern' => (string) ($p['pattern'] ?? '')], 'mustBe' => 0],
        'row_count' => ['type' => 'library', 'metric' => 'rowCount'] + array_filter(['mustBeGreaterOrEqualTo' => $p['min'] ?? null, 'mustBeLessOrEqualTo' => $p['max'] ?? null],
            static fn ($x) => $x !== null),
        'custom_sql' => ['type' => 'sql', 'query' => (string) ($p['sql'] ?? ''), 'mustBe' => 0],
        default => ['type' => 'custom', 'engine' => 'datalake', 'implementation' => ['kind' => $c['kind'], 'params' => (object) $p]],
    };
}

/** The contract as ODCS v3.2.0 YAML. $dataset: the catalog row (key, name, title, description, purpose, usage, limitations); $checks: the dataset's. */
function odcs_yaml(array $contract, array $dataset, array $checks = []): string
{
    $key = array_values((array) ($contract['key'] ?? []));
    $byColumn = [];
    $table = [];
    foreach ($checks as $c) {
        if (!(bool) ($c['enabled'] ?? true)) {
            continue;
        }
        if (($c['column_name'] ?? null) !== null) {
            $byColumn[(string) $c['column_name']][] = odcs_quality($c);
        } else {
            $table[] = odcs_quality($c);
        }
    }
    $props = [];
    foreach ((array) $contract['schema'] as $e) {
        $pos = array_search($e['name'], $key, true);
        $parts = explode('.', (string) ($e['source'] ?? ''));
        $prop = ['name' => $e['name'], 'logicalType' => $e['logical_type'] ?? contract_logical_of((string) $e['physical_type']), 'physicalType' => $e['physical_type'],
                 'required' => (bool) ($e['required'] ?? false), 'unique' => (bool) ($e['unique'] ?? false), 'primaryKey' => $pos !== false];
        if ($pos !== false) {
            $prop['primaryKeyPosition'] = $pos + 1;
        }
        if (($e['description'] ?? null) !== null) {
            $prop['description'] = $e['description'];
        }
        if (($e['classification'] ?? null) !== null) {
            $prop['classification'] = $e['classification'];
        }
        if (!empty($e['critical'])) {
            $prop['criticalDataElement'] = true;
        }
        if (count($parts) === 3) {
            $prop['transformSourceObjects'] = [$parts[0] . '.' . $parts[1]];
            $prop['transformLogic'] = $parts[2];
        }
        if (($e['personal_kind'] ?? null) !== null) {
            $prop['customProperties'] = [['property' => 'personalKind', 'value' => $e['personal_kind']]];
        }
        if (isset($byColumn[$e['name']])) {
            $prop['quality'] = $byColumn[$e['name']];
        }
        $props[] = $prop;
    }
    $object = ['name' => (string) $dataset['name'], 'physicalName' => (string) $dataset['key'], 'logicalType' => 'object', 'physicalType' => 'table',
               'properties' => $props];
    if (($dataset['description'] ?? null) !== null) {
        $object['description'] = (string) $dataset['description'];
    }
    if ($table !== []) {
        $object['quality'] = $table;
    }
    $doc = ['apiVersion' => 'v3.2.0', 'kind' => 'DataContract', 'id' => 'datalake:' . $dataset['key'] . ':' . $contract['version'],
            'name' => (string) ($dataset['title'] ?? $dataset['key']), 'version' => (string) $contract['version'], 'status' => (string) $contract['status']];
    $desc = array_filter(['purpose' => $dataset['purpose'] ?? null, 'usage' => $dataset['usage'] ?? null, 'limitations' => $dataset['limitations'] ?? null],
        static fn ($x) => $x !== null && $x !== '');
    if ($desc !== []) {
        $doc['description'] = $desc;
    }
    $doc['schema'] = [$object];
    $sla = [];
    foreach ((array) ($contract['sla'] ?? []) as $k => $iso) {
        [$v, $u] = odcs_duration((string) $iso);
        $sla[] = ['property' => $k, 'value' => $v, 'unit' => $u];
    }
    if ($sla !== []) {
        $doc['slaProperties'] = $sla;
    }
    $custom = [];
    if (($contract['ordering_column'] ?? null) !== null) {
        $custom[] = ['property' => 'orderingColumn', 'value' => $contract['ordering_column']];
    }
    if ($custom !== []) {
        $doc['customProperties'] = $custom;
    }
    return "# ODCS v3.2.0 — the Data Lake's contract for " . $dataset['key'] . ' (version ' . $contract['version'] . ")\n" . odcs_emit($doc);
}

/**
 * Read back what odcs_yaml() writes (a block subset of YAML whose scalars are JSON): the draft's fields {version, schema, key,
 * ordering_column, sla}, or null when the text is not such a document. Unknown keys are ignored; a column's source is rebuilt from
 * transformSourceObjects + transformLogic.
 */
function odcs_parse(string $yaml): ?array
{
    $lines = [];
    foreach (preg_split('/\R/', $yaml) as $raw) {
        if (trim($raw) === '' || str_starts_with(ltrim($raw), '#')) {
            continue;
        }
        $lines[] = $raw;
    }
    $i = 0;
    try {
        $doc = odcs_block($lines, $i, 0);
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($doc) || ($doc['kind'] ?? '') !== 'DataContract' || !is_array($doc['schema'][0]['properties'] ?? null)) {
        return null;
    }
    $schema = [];
    $key = [];
    foreach ($doc['schema'][0]['properties'] as $p) {
        if (!is_array($p) || !isset($p['name'])) {
            continue;
        }
        $src = isset($p['transformSourceObjects'][0], $p['transformLogic']) ? $p['transformSourceObjects'][0] . '.' . $p['transformLogic'] : '';
        $pk = null;
        foreach ((array) ($p['customProperties'] ?? []) as $cp) {
            if (($cp['property'] ?? '') === 'personalKind') {
                $pk = $cp['value'] ?? null;
            }
        }
        $schema[] = ['name' => (string) $p['name'], 'logical_type' => (string) ($p['logicalType'] ?? ''), 'physical_type' => (string) ($p['physicalType'] ?? 'VARCHAR'),
            'required' => (bool) ($p['required'] ?? false), 'unique' => (bool) ($p['unique'] ?? false), 'primary_key' => (bool) ($p['primaryKey'] ?? false),
            'source' => $src, 'description' => $p['description'] ?? null, 'classification' => $p['classification'] ?? null, 'personal_kind' => $pk,
            'critical' => (bool) ($p['criticalDataElement'] ?? false)];
        if (!empty($p['primaryKey'])) {
            $key[(int) ($p['primaryKeyPosition'] ?? count($key) + 1)] = (string) $p['name'];
        }
    }
    ksort($key);
    $sla = [];
    foreach ((array) ($doc['slaProperties'] ?? []) as $s) {
        if (is_array($s) && isset($s['property'], $s['value'])) {
            $iso = odcs_iso($s['value'], (string) ($s['unit'] ?? 'd'));
            if ($iso !== null) {
                $sla[(string) $s['property']] = $iso;
            }
        }
    }
    $ordering = null;
    foreach ((array) ($doc['customProperties'] ?? []) as $cp) {
        if (($cp['property'] ?? '') === 'orderingColumn') {
            $ordering = (string) $cp['value'];
        }
    }
    return ['version' => (string) ($doc['version'] ?? ''), 'schema' => $schema, 'key' => array_values($key), 'ordering_column' => $ordering, 'sla' => $sla];
}

/** One block at an indentation (a map, or a list when its lines start "- "). */
function odcs_block(array $lines, int &$i, int $indent): mixed
{
    $isList = isset($lines[$i]) && str_starts_with(substr($lines[$i], $indent), '- ');
    $out = [];
    while ($i < count($lines)) {
        $line = $lines[$i];
        $lead = strlen($line) - strlen(ltrim($line, ' '));
        if ($lead < $indent) {
            break;
        }
        if ($lead > $indent) {
            throw new RuntimeException('unexpected indentation');
        }
        $body = substr($line, $indent);
        if ($isList) {
            if (!str_starts_with($body, '- ')) {
                break;
            }
            $rest = substr($body, 2);
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*):( (.*))?$/', $rest)) {
                // a map item: its first pair is on the dash's line, the rest two spaces in
                $lines[$i] = str_repeat(' ', $indent + 2) . $rest;
                $out[] = odcs_block($lines, $i, $indent + 2);
            } else {
                $out[] = json_decode($rest, true, 512, JSON_THROW_ON_ERROR);
                $i++;
            }
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*):( (.*))?$/', $body, $m)) {
            throw new RuntimeException('not a pair');
        }
        $i++;
        if (($m[3] ?? '') === '') {
            $next = $lines[$i] ?? '';
            $nl = strlen($next) - strlen(ltrim($next, ' '));
            $out[$m[1]] = $i < count($lines) && $nl > $indent ? odcs_block($lines, $i, $nl) : null;
        } else {
            $out[$m[1]] = json_decode($m[3], true, 512, JSON_THROW_ON_ERROR);
        }
    }
    return $out;
}
