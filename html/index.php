<?php
declare(strict_types=1);
/**
 * / — Home (screen `home`; sso-shell.md; design §9): the search box with Search and Ask, then the tiles — certified products, my recent and
 * followed datasets, popular this month, open incidents on datasets I use, decisions waiting for me; an engineer's and the admin's tiles —
 * each from its own partial, filled by the slice whose data it shows. A person who may discover no dataset sees ONE button: Upload a
 * spreadsheet. JSON: present_home() — whitelisted.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$s = home_summary($pdo, (int) current_member_id());
log_screen_view($pdo, 'home');
if (wants_json()) {
    respond_screen(present_home($s));
}
render_screen('Home', view('home/index.php', ['s' => $s, 'tz' => member_timezone()]), ['activeNav' => 'home', 'screen' => 'home']);
