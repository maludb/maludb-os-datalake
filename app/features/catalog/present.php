<?php
declare(strict_types=1);

/**
 * The catalog's JSON (whitelisted presenters — never a raw row): a dataset card, a dataset, a stub, a column, a profile, usage. The
 * screens' JSON mode and (Phase 4) the records tools answer these shapes (docs/datalake-mcp-tool-surface.md: find_datasets, get_dataset,
 * dataset_columns, profile_history, dataset_usage). A profile never carries a personal value raw, nor a confidential column's top values.
 */

/** A browse card / a find_datasets row. */
function present_dataset_row(array $r): array
{
    $i = static fn ($v): ?int => $v === null ? null : (int) $v;
    return ['dataset_id' => (int) $r['dataset_id'], 'key' => $r['key'], 'zone' => $r['zone'], 'title' => $r['title'], 'owner_member_id' => $i($r['owner_member_id']),
            'owner_name' => $r['owner_name'] ?? null, 'steward_member_id' => $i($r['steward_member_id']), 'department_id' => $i($r['department_id']),
            'status' => $r['status'], 'certification' => $r['certification'], 'classification' => $r['classification'],
            'has_personal_data' => (bool) $r['has_personal_data'], 'row_count' => $i($r['row_count']), 'data_updated_at' => json_ts($r['data_updated_at']),
            'my_level' => $r['my_level'], 'is_stub' => false, 'open_incidents' => (int) ($r['open_incidents'] ?? 0), 'usage_30d' => (int) ($r['usage_30d'] ?? 0),
            'health' => $r['health'] ?? 'green', 'tags' => array_values((array) $r['tags']), 'successor_key' => $r['successor_key'] ?? null,
            'url' => '/datasets/' . $r['key']];
}

/** get_dataset: the entry, the contract's version, health, tags, the glossary terms of its columns, the engine's size. */
function present_dataset(array $d): array
{
    $i = static fn ($v): ?int => $v === null ? null : (int) $v;
    return [
        'dataset_id' => (int) $d['dataset_id'], 'key' => $d['key'], 'zone' => $d['zone'], 'title' => $d['title'], 'description' => $d['description'],
        'description_source' => $d['description_source'], 'accepted_by' => $d['accepted_by'], 'purpose' => $d['purpose'], 'usage' => $d['usage'],
        'limitations' => $d['limitations'],
        'owner' => ['member_id' => $i($d['owner_member_id']), 'name' => $d['owner_name']], 'steward' => ['member_id' => $i($d['steward_member_id']), 'name' => $d['steward_name']],
        'department' => ['department_id' => $i($d['department_id']), 'name' => $d['department_name']],
        'status' => $d['status'], 'deprecation_note' => $d['deprecation_note'], 'successor' => $d['successor'],
        'certification' => $d['certification'], 'certified_at' => json_ts($d['certified_at']),
        'certification_lost' => certification_lost_recent($d) ? ['reason' => $d['certification_lost_reason'], 'at' => json_ts($d['certification_lost_at'])] : null,
        'certification_missing' => array_values((array) ($d['health']['certification_missing'] ?? [])),
        'health' => $d['health']['health'] ?? 'green', 'freshness' => $d['health']['freshness'] ?? null,
        'tier' => $d['tier'], 'classification' => $d['classification'], 'has_personal_data' => (bool) $d['has_personal_data'],
        'contract' => $d['contract_version'] === null ? null : ['version' => $d['contract_version']],
        'row_count' => $i($d['row_count']), 'byte_size' => $i($d['byte_size']), 'engine' => $d['engine'],
        'data_updated_at' => json_ts($d['data_updated_at']), 'metadata_updated_at' => json_ts($d['metadata_updated_at']), 'created_at' => json_ts($d['created_at']),
        'freshness_warn_after' => interval_words($d['freshness_warn_seconds'] ?? null), 'freshness_error_after' => interval_words($d['freshness_error_seconds'] ?? null),
        'evolution_mode' => $d['evolution_mode'],
        'tags' => array_map(static fn (array $t): array => ['slug' => $t['slug'], 'label' => $t['label'], 'colour' => $t['colour'], 'kind' => $t['kind']], $d['tag_rows']),
        'terms' => array_map(static fn (array $t): array => ['term_id' => (int) $t['term_id'], 'name' => $t['name'], 'status' => $t['status']], $d['terms']),
        'my_level' => $d['my_level'], 'is_stub' => false, 'open_incidents' => (int) $d['open_incidents'], 'usage_30d' => (int) $d['usage_30d'],
        'url' => '/datasets/' . $d['key'],
    ];
}

/** A stub: the title, key, zone, owner's name, department and certification — and where to ask for access. Nothing else, ever. */
function present_dataset_stub(array $s): array
{
    return ['dataset_id' => (int) $s['dataset_id'], 'key' => $s['key'], 'zone' => $s['zone'], 'title' => $s['title'], 'is_stub' => true,
            'owner' => ['member_id' => $s['owner_member_id'] === null ? null : (int) $s['owner_member_id'], 'name' => $s['owner_name']],
            'department' => $s['department_name'], 'certification' => $s['certification'],
            'request_access' => '/access/requests/new?' . http_build_query(['dataset' => (int) $s['dataset_id'], 'level' => 'read'])];
}

/** Has the dataset lost its certification within 30 days (the header shows "Certification lost: {reason}")? */
function certification_lost_recent(array $d): bool
{
    return ($d['certification'] ?? '') === 'none' && ($d['certification_lost_at'] ?? null) !== null
        && strtotime((string) $d['certification_lost_at'] . ' UTC') > time() - 30 * 86400;
}

/** A column (dataset_columns' row shape) with its profile made safe for the caller's eyes. */
function present_column(array $c): array
{
    $i = static fn ($v): ?int => $v === null ? null : (int) $v;
    return ['column_id' => (int) $c['column_id'], 'name' => $c['name'], 'ordinal' => (int) $c['ordinal'], 'physical_type' => $c['physical_type'],
            'logical_type' => $c['logical_type'], 'nullable' => (bool) $c['nullable'], 'is_key' => (bool) $c['is_key'], 'is_system' => (bool) $c['is_system'],
            'business_name' => $c['business_name'], 'description' => $c['description'], 'description_source' => $c['description_source'],
            'classification' => $c['classification'], 'personal_kind' => $c['personal_kind'], 'proposed_personal_kind' => $c['proposed_personal_kind'],
            'proposed_confidence' => $c['proposed_confidence'] === null ? null : (float) $c['proposed_confidence'],
            'glossary_term_id' => $i($c['glossary_term_id']), 'term_name' => $c['term_name'] ?? null, 'critical' => (bool) $c['critical'],
            'references_dataset_id' => $i($c['references_dataset_id']), 'references_key' => $c['references_key'] ?? null, 'references_column' => $c['references_column'],
            'profile' => present_profile($c['profile'], (string) $c['classification'], $c['personal_kind'] ?? $c['proposed_personal_kind'] ?? null),
            'profiled_at' => json_ts($c['profiled_at']), 'removed' => $c['removed_snapshot_id'] !== null];
}

/**
 * A profile as the catalog may show it (catalog.md column-view): null %, distinct, the text length range and the type always; min and max
 * MASKED when the column is personal (proposed or confirmed — the writer already masked them; masked again here so a profile written
 * before a column was confirmed never shows raw), withheld when restricted; the top values masked when personal, WITHHELD when the column
 * is confidential or restricted. Null when there is no profile.
 */
function present_profile(?array $p, string $classification, ?string $personal): ?array
{
    if ($p === null || $p === []) {
        return null;
    }
    $restricted = $classification === 'restricted';
    $secret = $restricted || $classification === 'confidential';
    $kind = $personal ?? ($p['personal_kind'] ?? null);
    $mask = static fn ($v) => $v === null ? null : ($kind !== null && empty($p['masked']) ? mask_text((string) $v, $kind) : (string) $v);
    $num = static fn ($v): ?float => $v === null || !is_numeric($v) ? null : (float) $v;
    return [
        'type' => $p['type'] ?? null, 'null_pct' => $num($p['null_pct'] ?? null), 'distinct_approx' => isset($p['distinct_approx']) ? (int) $p['distinct_approx'] : null,
        'distinct_pct' => $num($p['distinct_pct'] ?? null), 'length_min' => isset($p['length_min']) ? (int) $p['length_min'] : null,
        'length_max' => isset($p['length_max']) ? (int) $p['length_max'] : null,
        'min' => $restricted ? null : $mask($p['min'] ?? null), 'max' => $restricted ? null : $mask($p['max'] ?? null),
        'top' => $secret ? null : array_map(static fn (array $t): array => ['value' => $mask($t['value'] ?? null), 'count' => (int) ($t['count'] ?? 0)], array_slice((array) ($p['top'] ?? []), 0, 10)),
        'masked' => $kind !== null, 'withheld' => $secret ? ($restricted ? 'restricted' : 'confidential') : null,
    ];
}

/** The writer's mask, in PHP (services/lake/profile.py mask_text): an email → a***@example.com; anything else → A****e. */
function mask_text(string $s, ?string $kind): string
{
    if ($kind === 'email' || preg_match('/^[^@\s]+@/', $s)) {
        return (string) preg_replace('/^(.)[^@]*/u', '$1***', $s);
    }
    if (mb_strlen($s) <= 2) {
        return '**';
    }
    return mb_substr($s, 0, 1) . str_repeat('*', min(mb_strlen($s) - 2, 6)) . mb_substr($s, -1);
}

/** profile_history's row. */
function present_profile_row(array $r, string $classification, ?string $personal): array
{
    return ['snapshot_id' => $r['snapshot_id'] === null ? null : (int) $r['snapshot_id'], 'load_id' => $r['load_id'] === null ? null : (int) $r['load_id'],
            'profile' => present_profile($r['profile'], $classification, $personal), 'profiled_at' => json_ts($r['profiled_at'])];
}

/** A follow ({what, since}) or null. */
function present_follow(?array $f): ?array
{
    return $f === null ? null : ['what' => $f['what'], 'since' => json_ts($f['created_at'])];
}

/** The health dot's colour class and word. */
function health_dot(string $health): array
{
    return match ($health) {
        'red'   => ['bg-danger', 'Failing'],
        'amber' => ['bg-warning', 'Needs a look'],      // slice 5: the spec's word (contracts-quality "Status vocabulary")
        default => ['bg-success', 'Healthy'],
    };
}

/** A status badge: [class, words] or null for active. */
function dataset_status_badge(string $status): ?array
{
    return match ($status) {
        'draft'      => ['text-bg-secondary', 'Draft'],
        'deprecated' => ['text-bg-warning', 'Deprecated'],
        'retired'    => ['text-bg-dark', 'Retired'],
        default      => null,
    };
}

/** A classification badge class (catalog.md "Status vocabulary"). */
function classification_class(string $c): string
{
    return ['public' => 'text-bg-light', 'internal' => 'text-bg-secondary', 'confidential' => 'text-bg-warning', 'restricted' => 'text-bg-danger'][$c] ?? 'text-bg-secondary';
}

/** A size in words: 1.2 MB. */
function bytes_words(?int $b): ?string
{
    if ($b === null) {
        return null;
    }
    foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'kB' => 1 << 10] as $u => $n) {
        if ($b >= $n) {
            return rtrim(rtrim(number_format($b / $n, 1), '0'), '.') . ' ' . $u;
        }
    }
    return $b . ' bytes';
}

/** "3 hours ago", "2 days ago", "just now" — a relative time in words; the full time in the title attribute. */
function ago_words(?string $utc): ?string
{
    if ($utc === null || $utc === '') {
        return null;
    }
    $s = time() - (int) strtotime($utc . (preg_match('/[zZ]|[+-]\d\d(:?\d\d)?$/', $utc) ? '' : ' UTC'));
    return match (true) {
        $s < 60          => 'just now',
        $s < 3600        => intdiv($s, 60) . ' min ago',
        $s < 86400       => intdiv($s, 3600) . ' hour' . (intdiv($s, 3600) === 1 ? '' : 's') . ' ago',
        $s < 86400 * 60  => intdiv($s, 86400) . ' day' . (intdiv($s, 86400) === 1 ? '' : 's') . ' ago',
        default          => format_ts($utc, member_timezone(), 'M j, Y'),
    };
}

/** Whether a tab's partial is built (its slice replaced tab-pending.php) — the JSON's `tabs` and the registry read it. */
function dataset_tab_built(string $tab): bool
{
    $f = dirname(__DIR__, 2) . '/views/datasets/partials/tab-' . $tab . '.php';
    return is_file($f) && !str_contains((string) file_get_contents($f), "'datasets/partials/tab-pending.php'");
}
