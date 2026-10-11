<?php
declare(strict_types=1);

/**
 * The connector interface (sources-pulls.md; Inventory's app/sources/Connector.php ported, inv_ → dl_, slice 6) — one class per way of getting
 * a file into the landing area, the methods probe / pull / search / lookup / capabilities (search and lookup answer `unsupported`: the lake
 * has no listings to look up) and `validate`, which turns what a form or an agent sent into the connector's settings. A connector only READS
 * and EMITS FILES; dl_run_pull() (pulls.php) lands each file once by its hash, makes its load and queues the writer's job.
 *
 * $source is the array dl_source_for_connector() builds:
 *   ['id', 'name', 'connector', 'base_url', 'settings' => [...], 'credential' => [...]|null (DECRYPTED by credentials.php, the only file that
 *    may), 'rate_per_second', 'user_agent', 'timeout', 'cache_dir', 'now' => DateTimeImmutable, 'tz' => the business time zone,
 *    'state' => the last good pull's policy.state (an ETag, a window, a watermark, the files seen), 'work_dir' => a private scratch directory,
 *    'pdo' => PDO, 'dataset' => ['id', 'key', 'row_count']|null, 'transport' => null|callable (tests), 'sftp_runner' => null|callable (tests)]
 *
 * probe()  → ['state' => ok|blocked|misconfigured, 'message' => one sentence, 'facts' => [...]] — asks, changes nothing, lands nothing
 * pull()   → calls $emit(file) once per file or page — file = ['path', 'name', 'format', 'mode' => append|replace, 'cursor', 'rows'?] — and
 *            returns the pull's stats: ['status' => ok|partial|failed|blocked, 'items_seen' => n, 'http_requests' => n, 'bytes' => n,
 *            'cached' => n, 'errors' => [string…], 'policy' => the HTTP client's facts, 'state' => what the next pull needs,
 *            'pause' => ?string (the source pauses with this reason, no failure counted)]
 * validate($in, $current) → [settings, errors (field => sentence)]
 */
interface DlConnector
{
    public function validate(array $in, array $current = []): array;

    public function probe(array $source): array;

    public function pull(array $source, callable $emit): array;

    public function search(array $source, string $q, int $limit = 20): array;

    public function lookup(array $source, array $identifiers): array;

    public function capabilities(): array;
}

/** Thrown by search()/lookup(): the lake's connectors have no such door. */
final class DlNotSupported extends RuntimeException
{
}

/** A source that cannot be read as configured (a missing setting, a missing credential, an Extended connector). */
final class DlMisconfigured extends RuntimeException
{
}

/** The five connectors of version 1, in the order the screens show them; the rest of dl_connectors() is Extended (declared, not pulled). */
const DL_CONNECTORS_V1 = ['upload', 'http_file', 'sftp_file', 'k7', 'postgres'];

/** The file formats a source may name; `auto` reads the bytes. */
const DL_FILE_FORMATS = ['auto' => 'Find out from the file', 'csv' => 'CSV', 'tsv' => 'Tab-separated', 'xlsx' => 'Excel workbook', 'json' => 'JSON', 'ndjson' => 'JSON lines',
    'parquet' => 'Parquet'];

/** The probe result in one shape. */
function dl_probe_result(string $state, string $message, array $facts = []): array
{
    if (!in_array($state, ['ok', 'blocked', 'misconfigured'], true)) {
        throw new InvalidArgumentException("probe state $state");
    }
    return ['state' => $state, 'message' => $message, 'facts' => $facts];
}

/** The pull stats in one shape, from the HTTP client's facts (or none for a connector without a network). */
function dl_pull_stats(?DlHttp $http, int $seen, array $errors = [], ?string $status = null, array $extra = []): array
{
    $facts = $http ? $http->facts() : ['requests' => [], 'http_requests' => 0, 'bytes' => 0, 'cached' => 0, 'blocked' => 0, 'robots_skipped' => 0];
    if ($status === null) {
        $status = $facts['blocked'] > 0 ? ($seen > 0 ? 'partial' : 'blocked') : ($errors === [] ? 'ok' : ($seen > 0 ? 'partial' : 'failed'));
    }
    return array_merge([
        'status' => $status,
        'items_seen' => $seen,
        'http_requests' => (int) $facts['http_requests'],
        'bytes' => (int) $facts['bytes'],
        'cached' => (int) $facts['cached'],
        'errors' => array_values(array_map(static fn($e) => mb_substr((string) $e, 0, 200), $errors)),
        'policy' => $facts,
    ], $extra);
}

/** The capabilities array with every key present. */
function dl_capabilities(array $set): array
{
    return array_merge([
        'label' => '', 'description' => '', 'icon' => 'feather-link', 'pulls' => true, 'needs_credential' => false, 'credential_kinds' => [],
        'has_search' => false, 'has_lookup' => false,
    ], $set);
}

/** One setting of a source, with a default. */
function dl_setting(array $source, string $key, mixed $default = null): mixed
{
    $settings = $source['settings'] ?? [];
    if (is_string($settings)) {
        $settings = json_decode($settings, true) ?: [];
    }
    return array_key_exists($key, $settings) && $settings[$key] !== null && $settings[$key] !== '' ? $settings[$key] : $default;
}

/** A trimmed text of at most $max characters, or null when empty. */
function dl_str(mixed $v, int $max = 500): ?string
{
    if (!is_scalar($v)) {
        return null;
    }
    $t = trim((string) $v);
    return $t === '' ? null : mb_substr($t, 0, $max);
}

/** A saved mapping's id from a request value: null when empty, 0 when not a positive whole number (the form's sentence). */
function dl_template_setting(mixed $v, array &$errors): ?int
{
    if ($v === null || $v === '' || $v === '0' || $v === 0) {
        return null;
    }
    if (!is_scalar($v) || !ctype_digit((string) $v) || strlen((string) $v) > 18) {
        $errors['template_id'] = 'Choose a saved mapping from the list.';
        return null;
    }
    return (int) $v;
}

/** The format setting: one of DL_FILE_FORMATS, `auto` by default. */
function dl_format_setting(mixed $v, array &$errors): string
{
    $f = strtolower(trim((string) ($v ?? '')));
    if ($f === '') {
        return 'auto';
    }
    if (!isset(DL_FILE_FORMATS[$f])) {
        $errors['format'] = 'The format is one of ' . implode(', ', array_keys(DL_FILE_FORMATS)) . '.';
        return 'auto';
    }
    return $f;
}

/** The connectors, keyed, in the order the screens list them: ['class', 'label', 'description', 'capabilities']. */
function dl_fetch_connectors(): array
{
    static $list = null;
    if ($list === null) {
        $defs = ['upload' => DlConnectorUpload::class, 'http_file' => DlConnectorHttpFile::class, 'sftp_file' => DlConnectorSftpFile::class,
                 'k7' => DlConnectorK7::class, 'postgres' => DlConnectorPostgres::class];
        $list = [];
        foreach ($defs as $key => $class) {
            $caps = (new $class())->capabilities();
            $list[$key] = ['key' => $key, 'class' => $class, 'label' => $caps['label'], 'description' => $caps['description'], 'icon' => $caps['icon'], 'capabilities' => $caps];
        }
    }
    return $list;
}

/** The connector for a key; DlMisconfigured for one that does not exist (the Extended ones included, until built). */
function dl_fetch_connector(string $key): DlConnector
{
    $list = dl_fetch_connectors();
    if (!isset($list[$key])) {
        throw new DlMisconfigured('connector_extended: the ' . $key . ' connector is Extended — version 1 ships ' . implode(', ', array_keys($list)));
    }
    $class = $list[$key]['class'];
    return new $class();
}

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/connectors/upload.php';
require_once __DIR__ . '/connectors/http_file.php';
require_once __DIR__ . '/connectors/sftp_file.php';
require_once __DIR__ . '/connectors/k7.php';
require_once __DIR__ . '/connectors/postgres.php';
