<?php
/**
 * Router for `php -S 127.0.0.1:9001 -t html tests/dev_router.php` — the vhost's rewrites (deploy/apache-datalake.conf), so the proofs run
 * without Apache: /sso, /sso/logout, /api/v1/health and the token API (/api/v1/{name}[/{id|key}[/{do}]] → api/v1/{name}.php with id/key/do);
 * the uploads in parts /upload/{start|part|finish}; the picker /pick/; a column of a dataset /datasets/{id|key}/columns/{name}[/edit] →
 * datasets/columns/{view|form}.php (ref, column) and a dataset's edit by its key /datasets/{zone.name}/edit → datasets/form.php (key) — slice 1;
 * a dataset by its key /datasets/{zone.name}[/{tab}] → datasets/view.php (key, tab); a page of a record (/incidents/12/resolve) and the canonical URLs (/x/new, /x/{id}/edit, /x/{id}, /x → x.php or x/index.php);
 * and, under the scratch environment only, the proofs' fixture doors /__proof/<name>.php (tests/phase2/routes/).
 * A rewrite to a file its slice has not built yet answers 404, as Apache does. Anything that is a real file is served as it is.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__) . '/html';
if ($path !== '/' && is_file($root . $path)) {
    if (str_ends_with($path, '.webmanifest')) { header('Content-Type: application/manifest+json'); readfile($root . $path); return true; }
    return false;                                             // a static file or a real .php file
}
$set = static function (array $kv): void { foreach ($kv as $k => $v) { $_GET[$k] = $v; $_REQUEST[$k] = $v; } };
// the proofs' own doors (tests/phase2/routes/ — the gate's fixture route): only under the scratch environment, never a file of html/
if (preg_match('#^/__proof/([a-z_-]+)\.php$#', $path, $m) && (string) getenv('DL_DEV_ENV') !== '' && is_file(__DIR__ . '/phase2/routes/' . $m[1] . '.php')) {
    $_SERVER['SCRIPT_NAME'] = $path;
    require __DIR__ . '/phase2/routes/' . $m[1] . '.php';
    return true;
}
$map = ['/sso' => '/sso.php', '/sso/logout' => '/sso/logout.php', '/api/v1/health' => '/api/v1/health.php'];
$rel = rtrim($path, '/') ?: '/';
if (isset($map[$rel])) { $target = $map[$rel]; }
elseif (preg_match('#^/api/v1/([a-z_]+)/([0-9]+)(?:/([a-z_]+))?$#', $path, $m)) { $set(['id' => $m[2], 'do' => $m[3] ?? '']); $target = '/api/v1/' . $m[1] . '.php'; }
elseif (preg_match('#^/api/v1/([a-z_]+)/([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)(?:/([a-z_]+))?$#', $path, $m)) { $set(['key' => $m[2], 'do' => $m[3] ?? '']); $target = '/api/v1/' . $m[1] . '.php'; }
elseif (preg_match('#^/api/v1/([a-z_]+)(?:/([a-z_]+))?$#', $path, $m)) { $target = '/api/v1/' . $m[1] . (isset($m[2]) ? '/' . $m[2] : '') . '.php'; }
elseif (str_starts_with($path, '/api/')) { http_response_code(404); echo 'Not found'; return true; }
elseif (preg_match('#^/upload/(start|part|finish)$#', $path, $m)) { $target = '/upload/' . $m[1] . '.php'; }
elseif (preg_match('#^/datasets/([0-9]+|(?:raw|curated|product|sandbox)\.[a-z][a-z0-9_]*)/columns/([A-Za-z0-9_]+)(/edit)?/?$#', $path, $m)) { $set(['ref' => $m[1], 'column' => $m[2]]); $target = '/datasets/columns/' . (($m[3] ?? '') !== '' ? 'form' : 'view') . '.php'; }
elseif (preg_match('#^/datasets/((?:raw|curated|product|sandbox)\.[a-z][a-z0-9_]*)/edit/?$#', $path, $m)) { $set(['key' => $m[1]]); $target = '/datasets/form.php'; }
elseif (preg_match('#^/datasets/((?:raw|curated|product|sandbox)\.[a-z][a-z0-9_]*)(?:/([a-z_-]+))?$#', $path, $m)) { $set(['key' => $m[1], 'tab' => $m[2] ?? '']); $target = '/datasets/view.php'; }
elseif (preg_match('#^/([a-z_-]+)/new$#', $path, $m)) { $target = '/' . $m[1] . '/form.php'; }
elseif (preg_match('#^/([a-z_-]+)/([0-9]+)/edit$#', $path, $m)) { $set(['id' => $m[2]]); $target = '/' . $m[1] . '/form.php'; }
elseif (preg_match('#^/([a-z_-]+)/([0-9]+)$#', $path, $m)) { $set(['id' => $m[2]]); $target = '/' . $m[1] . '/view.php'; }
elseif (preg_match('#^/([a-z_-]+)/([0-9]+)/([a-z_-]+)$#', $path, $m)) { $set(['id' => $m[2]]); $target = '/' . $m[1] . '/' . $m[3] . '.php'; }
elseif ($rel === '/') { $target = '/index.php'; }
elseif (is_file($root . $rel . '.php')) { $target = $rel . '.php'; }
elseif (is_file($root . $rel . '/index.php')) { $target = $rel . '/index.php'; }
else { http_response_code(404); echo 'Not found'; return true; }
if (!is_file($root . $target)) { http_response_code(404); echo 'Not found'; return true; }
$_SERVER['SCRIPT_NAME'] = $target;
$_SERVER['SCRIPT_FILENAME'] = $root . $target;
chdir(dirname($root . $target));
require $root . $target;
return true;
