<?php
/**
 * The slice 6 proof's FIXTURES SERVER — `php -S 127.0.0.1:9004 tests/phase3/slice6/fixtures_server.php` — a site the `http_file` connector fetches from:
 *   GET /robots.txt          User-agent: * · Disallow: /blocked/
 *   GET /data/<name>         the file the state maps <name> to (state.files[<name>] = {file, etag?, last_modified?, status?}), with its ETag / Last-Modified;
 *                            a request carrying If-None-Match = the current ETag answers 304; state.files[<name>].status (403, 404, 429, 500…) answers that
 *   GET /blocked/<name>      served — but robots.txt forbids it, so a polite client never asks (the log proves it)
 * The state is the JSON file $FX_STATE (the proofs rewrite it between steps); the files are in $FX_DIR; every request (method, path, the user-agent, If-None-Match,
 * whether an Authorization header came) is appended to $FX_LOG as one JSON line. Synthetic data only.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state = json_decode((string) @file_get_contents((string) getenv('FX_STATE')), true) ?: [];
$dir = rtrim((string) getenv('FX_DIR'), '/');
file_put_contents((string) getenv('FX_LOG'), json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'inm' => $_SERVER['HTTP_IF_NONE_MATCH'] ?? null,
    'ims' => $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null, 'auth' => isset($_SERVER['HTTP_AUTHORIZATION']) ? 'yes' : null, 'key' => $_SERVER['HTTP_X_API_KEY'] ?? null]) . "\n", FILE_APPEND);
if ($path === '/robots.txt') {
    header('Content-Type: text/plain');
    echo "User-agent: *\nDisallow: /blocked/\n";
    return;
}
if (preg_match('#^/(data|blocked)/([A-Za-z0-9_.\-]+)$#', $path, $m)) {
    $f = $state['files'][$m[2]] ?? null;
    if ($f === null) {
        http_response_code(404);
        echo 'no such file';
        return;
    }
    if (isset($f['status'])) {
        http_response_code((int) $f['status']);
        if ((int) $f['status'] === 403 && ($f['wall'] ?? false)) { header('Content-Type: text/html'); echo '<html><head><title>Just a moment...</title></head><body>cf-browser-verification</body></html>'; return; }
        echo 'refused';
        return;
    }
    $file = $dir . '/' . $f['file'];
    if (!is_file($file)) { http_response_code(404); echo 'gone'; return; }
    $etag = $f['etag'] ?? null;
    if ($etag !== null) { header('ETag: ' . $etag); }
    if (isset($f['last_modified'])) { header('Last-Modified: ' . $f['last_modified']); }
    if ($etag !== null && ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        return;
    }
    if ($m[1] === 'data' && ($f['auth'] ?? null) !== null) {                       // a protected file: the bearer or the key must match
        $ok = ($f['auth']['bearer'] ?? null) !== null && ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer ' . $f['auth']['bearer'];
        $ok = $ok || (($f['auth']['key'] ?? null) !== null && ($_SERVER['HTTP_X_API_KEY'] ?? '') === $f['auth']['key']);
        if (!$ok) { http_response_code(401); echo 'who are you'; return; }
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ['csv' => 'text/csv', 'json' => 'application/json', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'parquet' => 'application/octet-stream'][$ext] ?? 'application/octet-stream');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    return;
}
http_response_code(404);
echo 'not found';
