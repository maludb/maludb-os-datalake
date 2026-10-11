<?php
declare(strict_types=1);

/**
 * The sources' JSON and the words screens share (whitelisted presenters — never a raw row): a card, a source, a pull. A source's settings are
 * shown as the connector keeps them — they never hold a secret (the credential is sealed apart) — except that a URL's query string is
 * replaced by "?…" everywhere it is shown or logged (a person may have pasted a token into it). The screens' JSON and (Phase 4) the records
 * tools find_sources and get_source answer these shapes.
 */

/** A URL without its query string or fragment ("https://host/path?…" when there was one) — what a screen, a log or a JSON answer may say of it. */
function url_display(?string $url): ?string
{
    if ($url === null || $url === '') {
        return null;
    }
    $p = parse_url($url);
    if ($p === false || !isset($p['host'])) {
        return mb_substr(preg_replace('~[?#].*$~', '', $url) ?? '', 0, 300);
    }
    $out = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '');
    return mb_substr($out, 0, 300) . (isset($p['query']) || isset($p['fragment']) ? '?…' : '');
}

/** The connector's label and icon (Connector.php's registry). */
function connector_label(string $key): string
{
    return dl_fetch_connectors()[$key]['label'] ?? $key;
}

function connector_icon(string $key): string
{
    return dl_fetch_connectors()[$key]['icon'] ?? 'feather-link';
}

/** The schedule in words: "Manual", "Every hour", "Every day", "Every 90 minutes". */
function schedule_words(?int $minutes): string
{
    $m = (int) $minutes;
    return match (true) {
        $m <= 0 => 'Manual — pulled on request',
        $m === 60 => 'Every hour',
        $m === 1440 => 'Every day',
        $m === 10080 => 'Every week',
        $m % 1440 === 0 => 'Every ' . intdiv($m, 1440) . ' days',
        $m % 60 === 0 => 'Every ' . intdiv($m, 60) . ' hours',
        default => 'Every ' . $m . ' minutes',
    };
}

/** A source's state: [key, bootstrap colour, words, why?] — paused (with the reason), backing off (until when), active, switched off. */
function source_state(array $s): array
{
    if ($s['paused_at'] !== null) {
        return ['paused', 'warning', 'Paused', (string) ($s['paused_reason'] ?? '')];
    }
    if (!$s['active']) {
        return ['off', 'light', 'Switched off', ''];
    }
    if ($s['backoff_until'] !== null && strtotime((string) $s['backoff_until']) > time()) {
        return ['backing_off', 'secondary', 'Backing off', 'until ' . substr((string) $s['backoff_until'], 0, 16) . ' UTC'];
    }
    return ['active', 'success', 'Active', ''];
}

/** A pull's status badge: [class, words]. */
function pull_badge(string $status): array
{
    [$c, $w] = PULL_STATUS[$status] ?? ['secondary', $status];
    return ['bg-soft-' . $c . ' text-' . $c, $w];
}

/** The settings as a screen or the JSON may show them: the URL redacted, nothing else changed. */
function present_settings(array $settings): array
{
    if (isset($settings['url']) && is_string($settings['url'])) {
        $settings['url'] = url_display($settings['url']);
    }
    return $settings;
}

/** A card / a find_sources row. */
function present_source_row(array $r): array
{
    $i = static fn ($v): ?int => $v === null ? null : (int) $v;
    [$state, , , $why] = source_state($r);
    return ['source_id' => (int) $r['source_id'], 'name' => $r['name'], 'connector' => $r['connector'], 'connector_label' => connector_label((string) $r['connector']),
            'schedule_minutes' => $i($r['schedule_minutes']), 'schedule' => schedule_words($i($r['schedule_minutes'])), 'state' => $state, 'paused_reason' => $r['paused_reason'],
            'consecutive_failures' => (int) $r['consecutive_failures'], 'backoff_until' => json_ts($r['backoff_until']), 'last_ok_at' => json_ts($r['last_ok_at']),
            'last_pull' => $r['last_pull_id'] === null ? null : ['pull_id' => (int) $r['last_pull_id'], 'status' => $r['last_status'], 'started_at' => json_ts($r['last_started_at']),
                'finished_at' => json_ts($r['last_finished_at']), 'items_seen' => $i($r['last_items_seen']), 'items_landed' => $i($r['last_items_landed'])],
            'target' => $r['target_id'] === null ? null : ['dataset_id' => (int) $r['target_id'], 'key' => $r['target_key'], 'title' => $r['target_title']],
            'has_credential' => (bool) $r['has_credential'], 'url' => '/sources/' . (int) $r['source_id']];
}

/** get_source: the card plus the settings (the URL redacted), the credential's kind, label and last four, who made it, when. */
function present_source(array $s, array $may = []): array
{
    $c = $s['credential'] ?? null;
    return present_source_row($s) + [
        'role' => $s['role'], 'settings' => (object) present_settings((array) $s['settings']), 'base_url' => url_display($s['base_url'] ?? null),
        'credential' => $c === null ? null : ['kind' => $c['kind'], 'label' => $c['label'], 'last4' => $c['last4'], 'rotated_at' => json_ts($c['rotated_at']), 'set_at' => json_ts($c['created_at'])],
        'created_by' => ['member_id' => $s['created_by'] === null ? null : (int) $s['created_by'], 'name' => $s['created_by_name'] ?? null],
        'created_at' => json_ts($s['created_at']), 'updated_at' => json_ts($s['updated_at']), 'may' => $may,
    ];
}

/** A pull: counts, bytes, the error (≤ 200 characters) and the loads it made. */
function present_pull(array $p): array
{
    return ['pull_id' => (int) $p['pull_id'], 'kind' => $p['kind'], 'status' => $p['status'], 'started_at' => json_ts($p['started_at']), 'finished_at' => json_ts($p['finished_at']),
            'items_seen' => (int) $p['items_seen'], 'items_landed' => (int) $p['items_landed'], 'bytes' => (int) $p['bytes'], 'http_requests' => (int) $p['http_requests'],
            'error' => $p['error'] === null ? null : mb_substr((string) $p['error'], 0, 200), 'loads' => ['count' => (int) ($p['load_count'] ?? 0), 'ids' => array_values((array) ($p['load_ids'] ?? []))]];
}

/** A dataset a source feeds. */
function present_feed(array $d): array
{
    return ['dataset_id' => (int) $d['dataset_id'], 'key' => $d['key'], 'title' => $d['title'], 'zone' => $d['zone'], 'row_count' => $d['row_count'] === null ? null : (int) $d['row_count'],
            'data_updated_at' => json_ts($d['data_updated_at']), 'first_seen' => json_ts($d['first_seen'] ?? null), 'last_seen' => json_ts($d['last_seen'] ?? null)];
}

/**
 * The form's values: the connector's flat fields as the form names them (a nested setting flattened, a JSON setting as text), the common ones,
 * and — editing — the target dataset's id. $s null = a new source (the defaults: daily, one request a second).
 */
function source_form_values(string $connector, ?array $s): array
{
    $set = (array) ($s['settings'] ?? []);
    $json = static fn (mixed $v): string => $v === null || $v === [] || $v === '' ? '{}' : (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $sf = (array) ($set['sftp'] ?? []);
    $v = ['name' => (string) ($s['name'] ?? ''), 'target_dataset' => isset($set['target_dataset_id']) ? (int) $set['target_dataset_id'] : null, 'target_name' => '',
          'schedule_minutes' => (int) ($s['schedule_minutes'] ?? ($connector === 'upload' ? 0 : 1440)), 'rate_per_second' => (string) (float) ($s['rate_per_second'] ?? 1), 'user_agent' => (string) ($s['user_agent'] ?? ''),
          'template_id' => isset($set['template_id']) ? (int) $set['template_id'] : null, 'format' => (string) ($set['format'] ?? 'auto')];
    return $v + match ($connector) {
        'http_file' => ['url' => (string) ($set['url'] ?? ''), 'username' => (string) ($set['username'] ?? ''), 'api_key_header' => (string) ($set['api_key_header'] ?? '')],
        'sftp_file' => ['sftp_host' => (string) ($sf['host'] ?? ''), 'sftp_port' => (string) ($sf['port'] ?? 22), 'sftp_user' => (string) ($sf['user'] ?? ''), 'sftp_path' => (string) ($sf['path'] ?? ''),
                        'pattern' => (string) ($set['pattern'] ?? '*'), 'host_key_sha256' => (string) ($sf['host_key_sha256'] ?? '')],
        'k7' => ['provider' => (string) ($set['provider'] ?? ''), 'tool' => (string) ($set['tool'] ?? ''), 'arguments' => $json($set['arguments'] ?? null), 'rows_path' => (string) ($set['rows_path'] ?? ''),
                 'paging' => (string) ($set['paging'] ?? 'none'), 'page_size' => (string) ($set['page_size'] ?? 200), 'window' => (string) ($set['window'] ?? 'none'), 'inject' => $json($set['inject'] ?? null)],
        'postgres' => ['host' => (string) ($set['host'] ?? ''), 'port' => (string) ($set['port'] ?? 5432), 'dbname' => (string) ($set['dbname'] ?? ''), 'user' => (string) ($set['user'] ?? ''),
                       'sslmode' => (string) ($set['sslmode'] ?? 'require'), 'table' => (string) ($set['table'] ?? ''), 'watermark' => (string) ($set['watermark'] ?? ''), 'key' => implode(', ', (array) ($set['key'] ?? []))],
        default => [],
    };
}
