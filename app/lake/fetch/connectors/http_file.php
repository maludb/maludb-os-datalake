<?php
declare(strict_types=1);

/**
 * The `http_file` connector (sources-pulls.md): ONE file at a URL, read as a person's browser would — through the one polite client
 * (http.php: an honest user-agent, robots.txt honoured, one request a second, https only). The pull is a CONDITIONAL GET: the ETag and
 * Last-Modified of the last good pull (kept in its policy.state) go back as If-None-Match / If-Modified-Since; a 304 is status `ok` with
 * nothing landed; a changed file streams to the work directory and is landed once by its hash (the same bytes under a new ETag are refused
 * by the load's idempotency key, not loaded twice). A robots.txt that disallows the path, a 403, a 429 or a bot wall is `blocked`: the
 * source PAUSES with the reason — blocked means blocked; never a login, a captcha or a proxy pool.
 *
 * settings: url (https; no user:password@ in it), format (auto csv tsv xlsx json ndjson parquet), template_id? (a saved mapping),
 *           username? (with a `basic` credential), api_key_header? (with an `api_key` credential; X-API-Key by default).
 * credential (optional): bearer · api_key · basic — only the secret is sealed; the user name is a setting.
 */
final class DlConnectorHttpFile implements DlConnector
{
    public function capabilities(): array
    {
        return dl_capabilities(['label' => 'A file at a URL', 'icon' => 'feather-globe', 'needs_credential' => false, 'credential_kinds' => ['bearer', 'api_key', 'basic'],
            'description' => 'One CSV, spreadsheet, JSON or Parquet file at an https address, fetched politely on a schedule — it lands only when it changes.']);
    }

    public function validate(array $in, array $current = []): array
    {
        $errors = [];
        $settings = [];
        $url = dl_str($in['url'] ?? ($current['url'] ?? null), 2000);
        if ($url === null) {
            $errors['url'] = 'Give the address of the file.';
        } else {
            $p = parse_url($url);
            $scheme = strtolower((string) ($p['scheme'] ?? ''));
            $dev = !app_is_prod() && getenv('DL_FETCH_ALLOW_INSECURE') === '1';
            if ($p === false || ($p['host'] ?? '') === '' || !in_array($scheme, ['http', 'https'], true)) {
                $errors['url'] = 'The address is a full web address: https://host/path/file.csv.';
            } elseif ($scheme !== 'https' && !$dev) {
                $errors['url'] = 'The address must be https — a file that travels in the open is not fetched.';
            } elseif (isset($p['user']) || isset($p['pass'])) {
                $errors['url'] = 'Leave the user name and password out of the address — the name goes in the user name field and the secret in the credential.';
            } elseif (($why = dl_fetch_url_refusal($url)) !== null && !$dev) {
                $errors['url'] = $why === 'reserved_address' ? 'That address is this server\'s own or a reserved one — a file is fetched from the outside.' : 'That address cannot be fetched (' . $why . ').';
            } else {
                $settings['url'] = $url;
            }
        }
        $settings['format'] = dl_format_setting($in['format'] ?? ($current['format'] ?? null), $errors);
        $t = dl_template_setting($in['template_id'] ?? ($current['template_id'] ?? null), $errors);
        if ($t !== null) {
            $settings['template_id'] = $t;
        }
        $user = dl_str($in['username'] ?? ($current['username'] ?? null), 100);
        if ($user !== null) {
            if (!preg_match('~^[A-Za-z0-9._\-@+]{1,100}$~', $user)) {
                $errors['username'] = 'A user name is letters, digits and . _ - @ +.';
            } else {
                $settings['username'] = $user;
            }
        }
        $hdr = dl_str($in['api_key_header'] ?? ($current['api_key_header'] ?? null), 60);
        if ($hdr !== null) {
            if (!preg_match('~^[A-Za-z][A-Za-z0-9\-]{0,59}$~', $hdr)) {
                $errors['api_key_header'] = 'A header name is letters, digits and hyphens.';
            } elseif (!in_array(strtolower($hdr), ['host', 'user-agent', 'content-length', 'cookie', 'if-none-match', 'if-modified-since'], true)) {
                $settings['api_key_header'] = $hdr;
            } else {
                $errors['api_key_header'] = 'That header is the client\'s own.';
            }
        }
        return [$settings, $errors];
    }

    /** The client for a source (the crawl policy lives in it). */
    private function client(array $source, int $timeout, int $maxBytes): DlHttp
    {
        return new DlHttp(['user_agent' => $source['user_agent'] ?? null, 'rate_per_second' => $source['rate_per_second'] ?? 1, 'cache_dir' => $source['cache_dir'] ?? null,
            'transport' => $source['transport'] ?? null, 'timeout' => $timeout, 'max_bytes' => $maxBytes]);
    }

    /** The request headers the credential asks for (never logged). */
    private function auth(array $source): array
    {
        $c = $source['credential'] ?? null;
        if (!is_array($c)) {
            return [];
        }
        return match ($c['kind'] ?? '') {
            'bearer' => ['Authorization' => 'Bearer ' . (string) ($c['token'] ?? '')],
            'api_key' => [(string) dl_setting($source, 'api_key_header', 'X-API-Key') => (string) ($c['api_key'] ?? '')],
            'basic' => ['Authorization' => 'Basic ' . base64_encode((string) dl_setting($source, 'username', '') . ':' . (string) ($c['password'] ?? ''))],
            default => [],
        };
    }

    public function probe(array $source): array
    {
        $url = (string) dl_setting($source, 'url', '');
        if ($url === '') {
            return dl_probe_result('misconfigured', 'The source has no address.');
        }
        $http = $this->client($source, 20, 1024 * 1024);
        $secret = array_map('strtolower', array_merge(array_keys($this->auth($source)), ['authorization']));
        $r = $http->head($url, ['headers' => $this->auth($source), 'cache' => false, 'secret_headers' => $secret]);
        if (in_array($r['status'], [405, 501], true)) {                              // a server that does not answer HEAD: ask for the first byte
            $r = $http->get($url, ['headers' => $this->auth($source) + ['Range' => 'bytes=0-0'], 'cache' => false, 'secret_headers' => $secret, 'max_bytes' => 1024 * 1024]);
        }
        $facts = ['status' => $r['status'], 'content_type' => $r['headers']['content-type'] ?? null, 'content_length' => isset($r['headers']['content-length']) ? (int) $r['headers']['content-length'] : null,
                  'etag' => $r['headers']['etag'] ?? null, 'last_modified' => $r['headers']['last-modified'] ?? null, 'robots' => $http->facts()['robots'], 'user_agent' => $http->userAgent()];
        if ($r['skipped'] ?? false) {
            return dl_probe_result('blocked', 'The site\'s robots.txt disallows this path for ' . $http->userAgent() . ' — blocked means blocked.', $facts);
        }
        if ($r['blocked']) {
            return dl_probe_result('blocked', 'The host refuses us (' . ($r['reason'] ?? 'blocked') . ') — blocked means blocked.', $facts);
        }
        if (str_starts_with((string) ($r['reason'] ?? ''), 'url_refused')) {
            return dl_probe_result('misconfigured', 'That address cannot be fetched (' . substr((string) $r['reason'], 12) . ').', $facts);
        }
        if ($r['ok'] || $r['status'] === 206) {
            $size = $facts['content_length'] === null ? '' : ', ' . number_format((int) $facts['content_length']) . ' bytes';
            return dl_probe_result('ok', 'The file answers (HTTP ' . $r['status'] . $size . ').', $facts);
        }
        return dl_probe_result('misconfigured', 'The file could not be read (' . ($r['status'] > 0 ? 'HTTP ' . $r['status'] : (string) ($r['reason'] ?? 'no answer')) . ').', $facts);
    }

    public function pull(array $source, callable $emit): array
    {
        $url = (string) dl_setting($source, 'url', '');
        if ($url === '') {
            throw new DlMisconfigured('The source has no address.');
        }
        $maxBytes = max(1048576, (int) (getenv('DL_FETCH_MAX_BYTES') ?: 536870912));
        $http = $this->client($source, 600, $maxBytes);
        $state = (array) ($source['state'] ?? []);
        $prev = (array) ($state['http'] ?? []);
        $headers = $this->auth($source);
        if (($prev['etag'] ?? null) !== null) {
            $headers['If-None-Match'] = (string) $prev['etag'];
        }
        if (($prev['last_modified'] ?? null) !== null) {
            $headers['If-Modified-Since'] = (string) $prev['last_modified'];
        }
        $workDir = (string) $source['work_dir'];
        $dest = $workDir . '/download-' . bin2hex(random_bytes(5));
        $r = $http->get($url, ['sink' => $dest, 'cache' => false, 'headers' => $headers, 'timeout' => 600, 'max_bytes' => $maxBytes,
            'secret_headers' => array_map('strtolower', array_merge(array_keys($this->auth($source)), ['authorization']))]);
        $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'file';
        $extra = ['state' => ['http' => (object) $prev]];
        if ($r['status'] === 304) {
            @unlink($dest);
            return dl_pull_stats($http, 1, [], 'ok', $extra + ['note' => 'not modified']);
        }
        if (($r['skipped'] ?? false) || $r['blocked']) {
            @unlink($dest);
            $why = ($r['skipped'] ?? false)
                ? 'robots.txt disallows ' . (string) parse_url($url, PHP_URL_PATH) . ' for ' . $http->userAgent()
                : 'the host refuses us (' . (string) ($r['reason'] ?? 'blocked') . ')';
            return dl_pull_stats($http, 0, [$why], 'blocked', $extra + ['pause' => $why]);
        }
        if (!$r['ok']) {
            @unlink($dest);
            $why = str_starts_with((string) ($r['reason'] ?? ''), 'url_refused') ? 'the address cannot be fetched (' . substr((string) $r['reason'], 12) . ')'
                : ($r['status'] > 0 && $r['status'] !== 200 ? 'HTTP ' . $r['status'] : 'the file could not be fetched (' . (string) ($r['reason'] ?? 'no answer') . ')');
            return dl_pull_stats($http, 0, [$why], 'failed', $extra);
        }
        if (!is_file($dest) || filesize($dest) === 0) {
            @unlink($dest);
            return dl_pull_stats($http, 1, ['the file is empty'], 'failed', $extra);
        }
        $res = $emit(['path' => $dest, 'name' => $name, 'format' => (string) dl_setting($source, 'format', 'auto'), 'mode' => 'append', 'cursor' => null]);
        $new = ['etag' => $r['headers']['etag'] ?? null, 'last_modified' => $r['headers']['last-modified'] ?? null];
        $errors = isset($res['error']) && $res['error'] !== null ? [(string) $res['error']] : [];
        return dl_pull_stats($http, 1, $errors, $errors === [] ? 'ok' : 'failed', ['state' => ['http' => (object) ($errors === [] ? $new : $prev)]]);
    }

    public function search(array $source, string $q, int $limit = 20): array
    {
        throw new DlNotSupported('unsupported');
    }

    public function lookup(array $source, array $identifiers): array
    {
        throw new DlNotSupported('unsupported');
    }
}
