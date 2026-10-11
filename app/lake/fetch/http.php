<?php
declare(strict_types=1);

/**
 * The ONE outbound HTTP client of the lake (Inventory's app/sources/http.php ported, inv_ → dl_, slice 6; "all outbound HTTP goes through
 * app/lake/fetch/http.php"). It enforces the crawl policy for every connector that fetches a file at a URL:
 *   - an honest User-Agent: the source's own, else `MaluDB-DataLake/1.0 (+{APP_URL})` (naming the business's lake);
 *   - robots.txt fetched once per host and honoured (a disallowed path is SKIPPED, never fetched; Crawl-delay raises the wait);
 *   - one request at a time per host (a lock file) at most rate_per_second (default 1), across processes;
 *   - timeouts, a byte ceiling, at most five redirects — never one to a login page (→ blocked);
 *   - a 403, a 429 or a bot wall (Cloudflare "Just a moment", "Access denied", PerimeterX, Akamai, Imperva) → blocked — never a login, a
 *     captcha or a proxy pool; DL_HTTP_PROXY honoured when set (one proxy the business runs);
 *   - every request appended to the client's facts (host, path, status, bytes, cached, blocked, ms) for the pull's policy.
 * DIFFERENCES FROM INVENTORY'S (the lake lands FILES, not listings): `sink` streams a 2xx body to a file instead of memory (a data file may
 * be hundreds of megabytes; DL_FETCH_MAX_BYTES, default 512 MiB), the file cache is optional (`cache` => false: the source keeps its own
 * ETag / Last-Modified in the pull's policy and sends the conditional headers itself, so a 304 is the connector's to read), HEAD for a probe,
 * and a URL policy (https only; no loopback, link-local or metadata address) that DL_FETCH_ALLOW_INSECURE=1 lifts OUTSIDE production — the
 * proofs' fixtures server is http on 127.0.0.1. The transport (cURL) is injectable: tests pass a callable that answers without the network.
 *
 * A response: ['ok' => bool, 'status' => int, 'headers' => [lower => string], 'body' => string, 'bytes' => int, 'cached' => bool,
 *              'blocked' => bool, 'skipped' => bool, 'reason' => ?string, 'url' => final URL, 'elapsed_ms' => int]
 */

require_once __DIR__ . '/robots.php';

final class DlHttp
{
    public const LOGIN_PATH = '~^/(account/login|account/signin|account/sign-in|login|signin|sign-in|sign_in|customer(s)?(/|$)|customer/account|users/sign_in|user/login|auth/login|wp-login\.php|my-account)(/|\?|$)?~i';

    /** @var callable(array): array */
    private $transport;
    private string $userAgent;
    private float $rate;
    private string $cacheDir;
    private int $timeout;
    private int $maxBytes;
    private ?string $proxy;
    private bool $honourRobots;
    private array $extraHeaders;
    private array $robots = [];     // host => ['robots' => parsed|null, 'state' => ok|none|blocked|error, 'crawl_delay' => ?float]
    private array $requests = [];
    private int $bytes = 0;
    private int $cached = 0;
    private int $blocked = 0;
    private int $robotsSkipped = 0;

    public function __construct(array $opts = [])
    {
        $this->transport = $opts['transport'] ?? dl_http_curl_transport();
        $this->userAgent = dl_http_user_agent($opts);
        $rate = (float) ($opts['rate_per_second'] ?? 1);
        $this->rate = $rate > 0 ? $rate : 1.0;
        $this->cacheDir = rtrim((string) ($opts['cache_dir'] ?? dl_http_default_cache_dir()), '/');
        $this->timeout = max(1, (int) ($opts['timeout'] ?? 20));
        $this->maxBytes = max(1024, (int) ($opts['max_bytes'] ?? 8 * 1024 * 1024));
        $proxy = $opts['proxy'] ?? (getenv('DL_HTTP_PROXY') ?: null);
        $this->proxy = is_string($proxy) && trim($proxy) !== '' ? trim($proxy) : null;
        $this->honourRobots = (bool) ($opts['robots'] ?? true);
        $this->extraHeaders = is_array($opts['headers'] ?? null) ? $opts['headers'] : [];
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    public function get(string $url, array $opts = []): array
    {
        return $this->request('GET', $url, null, $opts);
    }

    public function head(string $url, array $opts = []): array
    {
        return $this->request('HEAD', $url, null, $opts);
    }

    public function post(string $url, string $body, array $opts = []): array
    {
        return $this->request('POST', $url, $body, $opts);
    }

    /** The policy facts of everything this client did (source_pulls.policy). */
    public function facts(): array
    {
        $robots = [];
        foreach ($this->robots as $host => $r) {
            $robots[$host] = ['state' => $r['state'], 'crawl_delay' => $r['crawl_delay']];
        }
        return [
            'user_agent' => $this->userAgent,
            'rate_per_second' => $this->rate,
            'proxy' => $this->proxy !== null,
            'robots' => $robots,
            'http_requests' => count(array_filter($this->requests, static fn($r) => !$r['skipped'])),
            'bytes' => $this->bytes,
            'cached' => $this->cached,
            'blocked' => $this->blocked,
            'robots_skipped' => $this->robotsSkipped,
            'requests' => $this->requests,
        ];
    }

    /** The parsed robots.txt of a URL's host (fetched once per host per client). */
    public function robotsFor(string $url): array
    {
        $p = parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '') {
            return ['robots' => null, 'state' => 'none', 'crawl_delay' => null];
        }
        if (!isset($this->robots[$host])) {
            $this->robots[$host] = ['robots' => null, 'state' => 'pending', 'crawl_delay' => null];
            $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
            $port = isset($p['port']) ? ':' . $p['port'] : '';
            $resp = $this->request('GET', "$scheme://$host$port/robots.txt", null, ['robots' => false, 'is_robots' => true, 'cache' => false]);
            if ($resp['blocked']) {
                $this->robots[$host] = ['robots' => ['groups' => ['*' => ['allow' => [], 'disallow' => ['/'], 'crawl_delay' => null]], 'sitemaps' => []], 'state' => 'blocked', 'crawl_delay' => null];
            } elseif ($resp['status'] === 200 && !preg_match('~<html~i', substr($resp['body'], 0, 512))) {
                $parsed = dl_robots_parse($resp['body']);
                $this->robots[$host] = ['robots' => $parsed, 'state' => 'ok', 'crawl_delay' => dl_robots_crawl_delay($parsed, $this->userAgent)];
            } elseif ($resp['status'] >= 500 || $resp['status'] === 0) {
                $this->robots[$host] = ['robots' => null, 'state' => 'error', 'crawl_delay' => null];
            } else {
                $this->robots[$host] = ['robots' => null, 'state' => 'none', 'crawl_delay' => null];
            }
        }
        return $this->robots[$host];
    }

    /** Does robots.txt let us fetch this URL? */
    public function allows(string $url): bool
    {
        $r = $this->robotsFor($url);
        if ($r['robots'] === null) {
            return $r['state'] !== 'blocked';
        }
        $p = parse_url($url);
        $path = (string) ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        return dl_robots_allows($r['robots'], $path, $this->userAgent);
    }

    private function request(string $method, string $url, ?string $body, array $opts): array
    {
        $t0 = microtime(true);
        $honourRobots = $opts['robots'] ?? $this->honourRobots;
        $isRobots = (bool) ($opts['is_robots'] ?? false);
        $useCache = (bool) ($opts['cache'] ?? true);
        $sink = isset($opts['sink']) && is_string($opts['sink']) ? $opts['sink'] : null;
        $hops = 0;
        $current = $url;
        $firstHost = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $secretHeaders = array_map('strtolower', (array) ($opts['secret_headers'] ?? ['authorization']));
        while (true) {
            $p = parse_url($current);
            $host = strtolower((string) ($p['host'] ?? ''));
            $path = (string) ($p['path'] ?? '/');
            if ($host === '' || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)) {
                return $this->done($t0, $current, $host, $path, ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'bad_url'], false, false, 'bad_url');
            }
            if (($why = dl_fetch_url_refusal($current)) !== null) {
                return $this->done($t0, $current, $host, $path, ['status' => 0, 'headers' => [], 'body' => '', 'error' => null], false, false, 'url_refused:' . $why);
            }
            if ($hops > 0 && preg_match(self::LOGIN_PATH, $path)) {
                return $this->done($t0, $current, $host, $path, ['status' => 0, 'headers' => [], 'body' => '', 'error' => null], false, true, 'login_redirect');
            }
            if ($honourRobots && !$isRobots && !$this->allows($current)) {
                $this->robotsSkipped++;
                return $this->done($t0, $current, $host, $path, ['status' => 0, 'headers' => [], 'body' => '', 'error' => null], false, false, 'robots_disallow', true);
            }
            $cacheKey = $method === 'GET' && $useCache && $sink === null ? $this->cacheKey($current) : null;
            $cachedEntry = $cacheKey !== null ? $this->cacheRead($cacheKey) : null;
            $headers = array_merge([
                'User-Agent' => $this->userAgent,
                'Accept' => $opts['accept'] ?? '*/*',
                'Accept-Language' => 'en',
            ], $this->extraHeaders, is_array($opts['headers'] ?? null) ? $opts['headers'] : []);
            if ($host !== $firstHost) {                       // a redirect to another host never carries the credential along
                foreach (array_keys($headers) as $hk) {
                    if (in_array(strtolower((string) $hk), $secretHeaders, true)) {
                        unset($headers[$hk]);
                    }
                }
            }
            if ($cachedEntry !== null) {
                if ($cachedEntry['etag'] !== null) {
                    $headers['If-None-Match'] = $cachedEntry['etag'];
                }
                if ($cachedEntry['last_modified'] !== null) {
                    $headers['If-Modified-Since'] = $cachedEntry['last_modified'];
                }
            }
            if ($body !== null) {
                $headers['Content-Type'] = $opts['content_type'] ?? 'application/json';
            }
            $this->waitForHost($host, function () use ($method, $current, $headers, $body, $opts, $sink, &$resp) {
                $resp = ($this->transport)([
                    'method' => $method, 'url' => $current, 'headers' => $headers, 'body' => $body,
                    'timeout' => (int) ($opts['timeout'] ?? $this->timeout), 'max_bytes' => (int) ($opts['max_bytes'] ?? $this->maxBytes),
                    'proxy' => $this->proxy, 'sink' => $sink,
                ]);
            });
            $resp = dl_http_response_shape($resp);
            $status = $resp['status'];
            if ($status === 304 && $cachedEntry !== null) {
                $resp['body'] = $cachedEntry['body'];
                $resp['headers'] = array_merge($cachedEntry['headers'], $resp['headers']);
                $resp['status'] = 200;
                return $this->done($t0, $current, $host, $path, $resp, true, false, null);
            }
            if (in_array($status, [301, 302, 303, 307, 308], true) && isset($resp['headers']['location'])) {
                $this->record($host, $path, $status, strlen($resp['body']), false, false, 'redirect', $t0);
                if (++$hops > 5) {
                    return $this->done($t0, $current, $host, $path, $resp, false, false, 'too_many_redirects', false, false);
                }
                $current = dl_http_resolve($current, $resp['headers']['location']);
                if (in_array($status, [301, 302, 303], true)) {
                    $method = 'GET';
                    $body = null;
                }
                continue;
            }
            $blockedReason = dl_http_blocked_reason($status, $resp['headers'], $resp['body']);
            if ($blockedReason !== null) {
                return $this->done($t0, $current, $host, $path, $resp, false, true, $blockedReason);
            }
            if ($cacheKey !== null && $status === 200 && $resp['error'] === null) {
                $this->cacheWrite($cacheKey, $resp);
            }
            return $this->done($t0, $current, $host, $path, $resp, false, false, $resp['error'] !== null ? 'transport:' . $resp['error'] : null);
        }
    }

    private function done(float $t0, string $url, string $host, string $path, array $resp, bool $cached, bool $blocked, ?string $reason, bool $skipped = false, bool $record = true): array
    {
        $bytes = $cached || $skipped ? 0 : (int) ($resp['sunk'] ?? strlen($resp['body']));
        if ($record) {
            $this->record($host, $path, $resp['status'], $bytes, $cached, $blocked, $reason, $t0, $skipped);
        }
        return [
            'ok' => !$blocked && !$skipped && $resp['status'] >= 200 && $resp['status'] < 300 && ($resp['error'] ?? null) === null && ($reason === null || str_starts_with((string) $reason, 'redirect')),
            'status' => $resp['status'],
            'headers' => $resp['headers'],
            'body' => $skipped ? '' : $resp['body'],
            'bytes' => $bytes,
            'cached' => $cached,
            'blocked' => $blocked,
            'skipped' => $skipped,
            'reason' => $reason,
            'url' => $url,
            'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    private function record(string $host, string $path, int $status, int $bytes, bool $cached, bool $blocked, ?string $reason, float $t0, bool $skipped = false): void
    {
        $this->requests[] = ['host' => $host, 'path' => $path, 'status' => $status, 'bytes' => $bytes, 'cached' => $cached, 'blocked' => $blocked, 'skipped' => $skipped, 'reason' => $reason, 'ms' => (int) round((microtime(true) - $t0) * 1000)];
        $this->bytes += $bytes;
        if ($cached) {
            $this->cached++;
        }
        if ($blocked) {
            $this->blocked++;
        }
    }

    /** One request at a time per host, spaced by max(1/rate, Crawl-delay): a lock file carries the last request's time across processes. */
    private function waitForHost(string $host, callable $do): void
    {
        $wait = 1.0 / $this->rate;
        $delay = $this->robots[$host]['crawl_delay'] ?? null;
        if ($delay !== null && $delay > $wait) {
            $wait = min((float) $delay, 60.0);
        }
        $dir = $this->cacheDir . '/hosts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $lockPath = $dir . '/' . preg_replace('~[^a-z0-9.\-]~', '_', $host) . '.lock';
        $fh = @fopen($lockPath, 'c+');
        if ($fh === false) {
            $do();
            return;
        }
        try {
            flock($fh, LOCK_EX);
            $last = (float) trim((string) stream_get_contents($fh));
            $sleep = $last + $wait - microtime(true);
            if ($sleep > 0) {
                usleep((int) ceil($sleep * 1_000_000));
            }
            $do();
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, sprintf('%.6f', microtime(true)));
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function cacheKey(string $url): string
    {
        return sha1($this->userAgent . "\n" . $url);
    }

    private function cachePath(string $key): string
    {
        return $this->cacheDir . '/' . substr($key, 0, 2) . '/' . $key;
    }

    private function cacheRead(string $key): ?array
    {
        $base = $this->cachePath($key);
        if (!is_file($base . '.meta.json') || !is_file($base . '.body')) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($base . '.meta.json'), true);
        if (!is_array($meta)) {
            return null;
        }
        return ['etag' => $meta['etag'] ?? null, 'last_modified' => $meta['last_modified'] ?? null, 'headers' => $meta['headers'] ?? [], 'body' => (string) file_get_contents($base . '.body')];
    }

    private function cacheWrite(string $key, array $resp): void
    {
        $etag = $resp['headers']['etag'] ?? null;
        $lm = $resp['headers']['last-modified'] ?? null;
        if ($etag === null && $lm === null) {
            return;
        }
        $base = $this->cachePath($key);
        $dir = dirname($base);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return;
        }
        $keep = array_intersect_key($resp['headers'], array_flip(['content-type', 'etag', 'last-modified']));
        @file_put_contents($base . '.body', $resp['body'], LOCK_EX);
        @file_put_contents($base . '.meta.json', json_encode(['etag' => $etag, 'last_modified' => $lm, 'headers' => $keep, 'stored_at' => time()]), LOCK_EX);
    }
}

/**
 * The URL policy: https only, and never an address that is this server or its neighbourhood's plumbing (loopback, link-local — the cloud
 * metadata address among them — or unspecified). DL_FETCH_ALLOW_INSECURE=1 lifts both OUTSIDE production (the proofs' fixtures server).
 * A host name is resolved and judged by what it resolves to. Returns the reason, or null when the URL may be fetched.
 */
function dl_fetch_url_refusal(string $url): ?string
{
    $p = parse_url($url);
    $scheme = strtolower((string) ($p['scheme'] ?? ''));
    $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return 'bad_url';
    }
    if (function_exists('app_is_prod') && !app_is_prod() && getenv('DL_FETCH_ALLOW_INSECURE') === '1') {
        return null;
    }
    if ($scheme !== 'https') {
        return 'https_only';
    }
    return dl_fetch_host_refusal($host);
}

/**
 * The host policy every connector that dials out shares — http_file (through dl_fetch_url_refusal, at every hop), sftp_file and postgres, at the
 * source's saving and again at every pull: a name is resolved and judged by what it resolves to. Never a reserved address (loopback 127/8 and ::1,
 * link-local 169.254/16 with the metadata address, 0.0.0.0, fe80::/10, 240/4) and never one of this machine's own interfaces' addresses —
 * dl_sources_guard (db/009) refuses `localhost` by NAME only, and an alias (127.0.0.2, the server's own address, a name resolving to either) would
 * reach this server's databases and services (the planning model's review of slice 6). DL_FETCH_ALLOW_INSECURE=1 lifts it OUTSIDE production (the
 * proofs' fixtures server and their outside database at 127.0.0.2). A name that does not resolve passes here: its connection fails on its own.
 * Returns the reason, or null.
 */
function dl_fetch_host_refusal(string $host): ?string
{
    $host = strtolower(trim($host, "[] \t"));
    if ($host === '') {
        return 'bad_host';
    }
    if (function_exists('app_is_prod') && !app_is_prod() && getenv('DL_FETCH_ALLOW_INSECURE') === '1') {
        return null;
    }
    $own = dl_fetch_own_addresses();
    $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : array_merge((array) (@gethostbynamel($host) ?: []), array_column((array) (@dns_get_record($host, DNS_AAAA) ?: []), 'ipv6'));
    foreach ($ips as $ip) {
        $ip = strtolower((string) $ip);
        if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ip = substr($ip, 7);                                           // an IPv4 address written the IPv6 way
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'reserved_address';                                       // loopback 127/8, ::1, 169.254/16 (the metadata address), 0.0.0.0, fe80::/10, 240/4
        }
        $bin = @inet_pton($ip);
        if ($bin !== false && isset($own[$bin])) {
            return 'reserved_address';                                       // this machine's own address, by another name
        }
    }
    return null;
}

/** This machine's own addresses (packed, as keys): every interface's (net_get_interfaces) and the host name's. */
function dl_fetch_own_addresses(): array
{
    $own = [];
    foreach ((array) (function_exists('net_get_interfaces') ? (@net_get_interfaces() ?: []) : []) as $iface) {
        foreach ((array) ($iface['unicast'] ?? []) as $u) {
            $a = preg_replace('~%.*$~', '', (string) ($u['address'] ?? '')) ?? '';
            if ($a !== '' && ($bin = @inet_pton($a)) !== false) {
                $own[$bin] = true;
            }
        }
    }
    foreach ((array) (@gethostbynamel((string) gethostname()) ?: []) as $a) {
        if (($bin = @inet_pton((string) $a)) !== false) {
            $own[$bin] = true;
        }
    }
    return $own;
}

/** The cache / lock directory: DL_FETCH_CACHE_DIR, else var/fetch under the application (created when missing), else the system temp. */
function dl_http_default_cache_dir(): string
{
    $dir = (string) (getenv('DL_FETCH_CACHE_DIR') ?: '');
    if ($dir === '') {
        $dir = dirname(__DIR__, 3) . '/var/fetch';
    }
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        $dir = sys_get_temp_dir() . '/datalake-fetch';
        @mkdir($dir, 0770, true);
    }
    return $dir;
}

/** The honest user-agent: the source's own, else MaluDB-DataLake/1.0 naming this installation (APP_URL). */
function dl_http_user_agent(array $opts): string
{
    $ua = $opts['user_agent'] ?? null;
    $ua = is_string($ua) ? trim(preg_replace('~[\r\n]+~', ' ', $ua) ?? '') : '';
    if ($ua !== '') {
        return $ua;
    }
    $url = trim((string) (function_exists('env') ? env('APP_URL', '') : getenv('APP_URL')));
    return 'MaluDB-DataLake/1.0 (+' . ($url !== '' ? $url : 'no contact configured; set APP_URL') . ')';
}

/** Resolve a (possibly relative) Location against the request URL. */
function dl_http_resolve(string $base, string $location): string
{
    $location = trim($location);
    if (preg_match('~^https?://~i', $location)) {
        return $location;
    }
    $p = parse_url($base);
    $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($location, '//')) {
        return ($p['scheme'] ?? 'https') . ':' . $location;
    }
    if (str_starts_with($location, '/')) {
        return $origin . $location;
    }
    $dir = rtrim(dirname($p['path'] ?? '/'), '/');
    return $origin . $dir . '/' . $location;
}

/** A transport's answer, made whole: status int, headers lower-cased strings, body string, error ?string, sunk ?int (bytes streamed to the sink). */
function dl_http_response_shape(mixed $resp): array
{
    if (!is_array($resp)) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'transport_returned_nothing'];
    }
    $headers = [];
    foreach ((array) ($resp['headers'] ?? []) as $k => $v) {
        $headers[strtolower((string) $k)] = is_array($v) ? implode(', ', $v) : (string) $v;
    }
    $out = ['status' => (int) ($resp['status'] ?? 0), 'headers' => $headers, 'body' => (string) ($resp['body'] ?? ''), 'error' => isset($resp['error']) && $resp['error'] !== '' ? (string) $resp['error'] : null];
    if (isset($resp['sunk'])) {
        $out['sunk'] = (int) $resp['sunk'];
    }
    return $out;
}

/** Why a response marks the source blocked: http_403, http_429, or a bot wall's name; null when it does not. */
function dl_http_blocked_reason(int $status, array $headers, string $body): ?string
{
    if ($status === 403) {
        return 'http_403';
    }
    if ($status === 429) {
        return trim($body) === 'local_rate_limited' ? 'http_429:local_rate_limited' : 'http_429';
    }
    $ct = strtolower($headers['content-type'] ?? '');
    $looksHtml = str_contains($ct, 'html') || ($ct === '' && preg_match('~<html|<!doctype~i', substr($body, 0, 1024)));
    if (!$looksHtml || strlen($body) > 512 * 1024) {
        return null;
    }
    $head = substr($body, 0, 65536);
    $patterns = [
        'bot_wall:cloudflare' => '~<title>\s*Just a moment\.{0,3}\s*</title>|cf-browser-verification|cf_chl_|challenge-platform|Checking your browser before accessing|cf-challenge~i',
        'bot_wall:perimeterx' => '~px-captcha|_pxhd|perimeterx|Press (&amp;|&) Hold to confirm you are~i',
        'bot_wall:akamai' => '~errors\.edgesuite\.net|Reference&#32;&#35;|<title>\s*Access Denied\s*</title>~i',
        'bot_wall:imperva' => '~Pardon Our Interruption|Incapsula incident|_Incapsula_Resource~i',
        'bot_wall:datadome' => '~datadome|captcha-delivery\.com~i',
        'bot_wall:captcha' => '~<title>[^<]*(Access denied|Verify you are human|Are you a robot|Bot Verification|Security Check)[^<]*</title>|(g-recaptcha|h-captcha)[^<]{0,200}(verify|human|robot)~i',
    ];
    foreach ($patterns as $name => $re) {
        if (preg_match($re, $head)) {
            return $name;
        }
    }
    if ($status === 503 && preg_match('~cloudflare~i', $head)) {
        return 'bot_wall:cloudflare';
    }
    return null;
}

/**
 * The cURL transport: no automatic redirects (the client judges each hop), a byte ceiling, gzip accepted. With `sink` (a path) a 2xx body
 * streams to that file (its size in `sunk`, the ceiling enforced as it arrives); any other answer's body is kept in memory, capped at
 * 512 kB (enough to recognise a bot wall).
 */
function dl_http_curl_transport(): callable
{
    return static function (array $req): array {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'curl_missing'];
        }
        $ch = curl_init();
        $headers = [];
        $body = '';
        $max = (int) $req['max_bytes'];
        $sink = isset($req['sink']) && is_string($req['sink']) ? $req['sink'] : null;
        $fh = null;
        $sunk = 0;
        $tooLarge = false;
        $hdrs = [];
        foreach ($req['headers'] as $k => $v) {
            $hdrs[] = $k . ': ' . $v;
        }
        $opts = [
            CURLOPT_URL => $req['url'],
            CURLOPT_CUSTOMREQUEST => $req['method'],
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => min(10, (int) $req['timeout']),
            CURLOPT_TIMEOUT => (int) $req['timeout'],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $len = strlen($line);
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $k = strtolower(trim($k));
                    $v = trim($v);
                    $headers[$k] = isset($headers[$k]) && $k !== 'location' ? $headers[$k] . ', ' . $v : $v;
                } elseif (preg_match('~^HTTP/~', $line)) {
                    $headers = []; // a new status line: the previous (e.g. 100 Continue) headers are discarded
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $max, &$tooLarge, $sink, &$fh, &$sunk): int {
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($sink !== null && $code >= 200 && $code < 300) {
                    if ($sunk + strlen($chunk) > $max) {
                        $tooLarge = true;
                        return -1;
                    }
                    if ($fh === null) {
                        $fh = @fopen($sink, 'wb');
                        if ($fh === false) {
                            $tooLarge = false;
                            return -1;
                        }
                    }
                    if (fwrite($fh, $chunk) !== strlen($chunk)) {
                        return -1;
                    }
                    $sunk += strlen($chunk);
                    return strlen($chunk);
                }
                $cap = $sink !== null ? 512 * 1024 : $max;
                if (strlen($body) + strlen($chunk) > $cap) {
                    if ($sink !== null) {
                        return strlen($chunk);             // the rest of an error page is not needed
                    }
                    $tooLarge = true;
                    return -1;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (strtoupper((string) $req['method']) === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }
        curl_setopt_array($ch, $opts);
        if ($req['body'] !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $req['body']);
        }
        if (!empty($req['proxy'])) {
            curl_setopt($ch, CURLOPT_PROXY, $req['proxy']);
        }
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);
        if ($fh !== null && $fh !== false) {
            fclose($fh);
        }
        if ($tooLarge) {
            return ['status' => $status, 'headers' => $headers, 'body' => '', 'error' => 'max_bytes', 'sunk' => $sunk];
        }
        if ($err !== null) {
            return ['status' => $status, 'headers' => $headers, 'body' => $body, 'error' => preg_replace('~[\r\n]+~', ' ', $err), 'sunk' => $sunk];
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'error' => null] + ($sink !== null ? ['sunk' => $sunk] : []);
    };
}
