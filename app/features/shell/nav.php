<?php
declare(strict_types=1);

/**
 * The shell's menu: ONE table — the sidebar and the phone's tab bar read it, so a menu item and the right that opens its screen can
 * never disagree (sso-shell.md; design §9). An item shows only when the person holds its right (several joined by "|": any one).
 * The order is the design's: Home · Search · Browse · Products · Metrics · Ask · SQL · Queries · My sandbox · Upload · Sources · Loads ·
 * Transforms · Quality · Access · Glossary · Steward · Admin, then Me. A record's own screens hang under their list, not here.
 *   [nav id, url, icon, label, right, the manifest's screen id]
 */
const NAV_ITEMS = [
    'Data Lake' => [
        ['home',       '/',               'feather-home',           'Home',       'catalog.browse',                    'home'],
        ['search',     '/search',         'feather-search',         'Search',     'catalog.browse',                    'search'],
        ['browse',     '/browse',         'feather-database',       'Browse',     'catalog.browse',                    'browse'],
        ['products',   '/products',       'feather-package',        'Products',   'catalog.browse',                    'products'],
        ['metrics',    '/metrics',        'feather-trending-up',    'Metrics',    'metrics.use',                       'metric-list'],
        ['ask',        '/ask',            'feather-message-square', 'Ask',        'ask.use',                           'ask'],
        ['sql',        '/sql',            'feather-terminal',       'SQL',        'sql.editor',                        'sql'],
        ['queries',    '/queries',        'feather-bookmark',       'Queries',    'queries.run',                       'queries'],
        ['sandbox',    '/sandbox',        'feather-box',            'My sandbox', 'sandbox.upload',                    'sandbox'],
    ],
    'Build' => [
        ['upload',     '/upload',         'feather-upload-cloud',   'Upload',     'sandbox.upload',                    'upload'],
        ['sources',    '/sources',        'feather-link',           'Sources',    'sources.manage',                    'source-list'],
        ['loads',      '/loads',          'feather-download',       'Loads',      'loads.manage',                      'load-list'],
        ['transforms', '/transforms',     'feather-shuffle',        'Transforms', 'transforms.manage|sandbox.transform', 'transform-list'],
        ['quality',    '/quality',        'feather-check-circle',   'Quality',    'catalog.browse',                    'quality'],
    ],
    'Govern' => [
        ['access',     '/access',         'feather-lock',           'Access',     'access.request',                    'my-access'],
        ['glossary',   '/glossary',       'feather-book-open',      'Glossary',   'catalog.browse',                    'glossary'],
        ['steward',    '/steward',        'feather-feather',        'Steward',    'catalog.edit',                      'steward'],
        ['admin',      '/admin/settings', 'feather-sliders',        'Admin',      'settings.manage',                   'admin-settings'],
    ],
    'Me' => [
        ['my-settings',   '/settings/',        'feather-settings', 'My settings',   'catalog.browse', 'settings'],
        ['notifications', '/notifications',    'feather-bell',     'Notifications', 'catalog.browse', 'notifications'],
        ['tokens',        '/settings/tokens/', 'feather-key',      'Tokens',        'catalog.browse', 'settings-tokens'],
        ['activity',      '/activity',         'feather-clock',    'My activity',   'catalog.browse', 'activity'],
    ],
];

/** The slice that builds each menu item not built yet, in words — the placeholders and the registry read it (design §10). */
const NAV_SLICES = ['browse' => 'slice 1', 'glossary' => 'slice 1', 'upload' => 'slice 2', 'sandbox' => 'slice 2', 'loads' => 'slice 2',
    'sql' => 'slice 3', 'queries' => 'slice 3', 'access' => 'slice 4', 'quality' => 'slice 5', 'sources' => 'slice 6', 'products' => 'slice 7',
    'metrics' => 'slice 7', 'transforms' => 'slice 7', 'ask' => 'slice 10', 'steward' => 'slice 10', 'admin' => 'slice 10'];

/** The groups as the sidebar shows them (the first group has no caption). */
function nav_groups(): array
{
    return NAV_ITEMS;
}

/**
 * The phone's tabs (design §9): Home · Search · Ask · Me. Me opens the sidebar (the theme's offcanvas); without JavaScript it opens
 * My settings. [id, url, icon, label, right, kind] — kind is 'link' or 'menu'.
 */
function nav_tabs(): array
{
    return [
        ['home',   '/',          'feather-home',           'Home',   'catalog.browse', 'link'],
        ['search', '/search',    'feather-search',         'Search', 'catalog.browse', 'link'],
        ['ask',    '/ask',       'feather-message-square', 'Ask',    'ask.use',        'link'],
        ['me',     '/settings/', 'feather-user',           'Me',     'catalog.browse', 'menu'],
    ];
}

function nav_item(string $id): ?array
{
    foreach (NAV_ITEMS as $items) {
        foreach ($items as $i) {
            if ($i[0] === $id) {
                return $i;
            }
        }
    }
    return null;
}

/** A menu right may name several joined by "|": any one of them opens the item. */
function nav_has_right(string $spec): bool
{
    foreach (explode('|', $spec) as $right) {
        if (has_right($right)) {
            return true;
        }
    }
    return false;
}

/** Whether a controller exists and is no longer a placeholder (its slice shipped it). */
function nav_built(string $file): bool
{
    $path = dirname(__DIR__, 3) . '/html' . $file;
    return is_file($path) && !str_contains((string) file_get_contents($path), 'render_nav_stub(');
}

/** The highest Data Lake role the member holds, in words — the header's badge (dl_member_roles(), db/004). */
function role_badge(): string
{
    $m = current_member();
    if ($m === null) {
        return '';
    }
    if (($m['business_role'] ?? '') === 'super_admin' && ($m['member_kind'] ?? 'human') === 'human') {
        return 'Super-admin';
    }
    $held = pg_text_array((string) (one_value(db(), 'SELECT dl_member_roles(:id)::text', ['id' => (int) $m['id']]) ?: '{}'));
    foreach (['admin' => 'Lake admin', 'engineer' => 'Data engineer', 'analyst' => 'Analyst', 'user' => 'Member'] as $key => $label) {
        if (in_array($key, $held, true)) {
            return $label;
        }
    }
    return '';
}

/** A PostgreSQL text[] literal ({a,b}) as a PHP list; quoted elements are unquoted. */
function pg_text_array(string $literal): array
{
    $literal = trim($literal);
    if ($literal === '' || $literal === '{}') {
        return [];
    }
    $out = [];
    foreach (str_getcsv(substr($literal, 1, -1), ',', '"', '\\') as $v) {
        if ($v !== null && $v !== '') {
            $out[] = $v;
        }
    }
    return $out;
}

/** A link that navigates by HTMX into #page-content and still works as a plain link (progressive enhancement). $html is already escaped. */
function hx_link(string $url, string $html, string $class = '', string $extra = ''): string
{
    return '<a href="' . e($url) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ($extra !== '' ? ' ' . $extra : '')
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
}

/** "Back to …" from the ?back= a link carried (click-around rule): [url, label] or null. Later slices add their screens here. */
function back_link(): ?array
{
    $back = safe_local_path($_GET['back'] ?? null);
    if ($back === null) {
        return null;
    }
    $path = parse_url($back, PHP_URL_PATH) ?: '/';
    $labels = ['/' => 'Home', '/search' => 'Search', '/notifications' => 'Notifications', '/activity' => 'My activity', '/settings/' => 'My settings',
               '/settings/tokens/' => 'Tokens', '/tags' => 'Tags'];
    if (isset($labels[$path])) {
        return [$back, $labels[$path]];
    }
    foreach (NAV_ITEMS as $items) {
        foreach ($items as $i) {
            if (rtrim($i[1], '/') === rtrim($path, '/')) {
                return [$back, $i[3]];
            }
        }
    }
    if (preg_match('#^/datasets/([0-9]+|(raw|curated|product|sandbox)\.[a-z][a-z0-9_]*)(/|$)#', $path)) {
        return [$back, 'the dataset'];
    }
    foreach (['incidents' => 'the incident', 'sources' => 'the source', 'loads' => 'the load', 'queries' => 'the query', 'runs' => 'the query run',
              'transforms' => 'the transform', 'metrics' => 'the metric', 'glossary' => 'the term', 'groups' => 'the group'] as $seg => $label) {
        if (preg_match('#^/' . $seg . '/\d+$#', $path)) {
            return [$back, $label];
        }
    }
    return null;
}

/** The URL of this same page, for a ?back= (path and query as requested). */
function here_url(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

function with_back(string $url, string $here): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'back=' . rawurlencode($here);
}

/**
 * A screen of the manifest that its slice has not built yet (Phase 2): the shell, the page header, one card saying which slice builds
 * it — 200 to a person after the right has been checked, 501 to JSON and to a POST. bin/build_action_registry.php reads a controller
 * that calls this as a stub, so the voice surface never offers the screen. Removing the call is the slice's first step.
 */
function render_nav_stub(string $navId): void
{
    $item = nav_item($navId) ?? throw new InvalidArgumentException('render_nav_stub: no menu item ' . $navId);
    [, , $icon, $title, $right, $screen] = $item;
    $slice = NAV_SLICES[$navId] ?? 'a later slice';
    require_login();
    if (!nav_has_right($right)) {
        require_right(explode('|', $right)[0]);        // refuses in that right's sentence
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        emit_action_status(false, ['error' => 'This is built by ' . $slice . '.']);
        if (wants_json()) {
            json_error('not_built', 'This is built by ' . $slice . '.', 501);
        }
        http_response_code(501);
        echo view('shared/message.php', ['title' => 'Not built yet', 'message' => 'This is built by ' . $slice . '.']);
        exit;
    }
    log_screen_view(db(), $screen);
    if (wants_json()) {
        json_error('not_built', 'This screen is built by ' . $slice . '.', 501);
    }
    render_screen($title, view('shared/nav-stub.php', ['id' => $navId, 'title' => $title, 'icon' => $icon, 'slice' => $slice]),
        ['activeNav' => $navId, 'screen' => $screen]);
}
