<?php
/**
 * Proof: the first-run home (sso-shell.md "Proof": "the home for a new person (one button)"). Runs FIRST, on the empty lake — before the browser
 * proof seeds it: a Member, an engineer and the admin see ONE button, Upload a spreadsheet (to /upload — slice 2's screen, a plain link until it
 * ships), an engineer and the admin also a quiet link to connect a source; nothing else on the home; the JSON says first_run and what they may do.
 */
require __DIR__ . '/lib.php';
/** Whether a controller is a real screen (its slice shipped it) — the suite follows what is built. */
function nav_built_file(string $f): bool { $p = dirname(__DIR__, 2) . '/html' . $f; return is_file($p) && !str_contains((string) file_get_contents($p), 'render_nav_stub('); }
$json = ['headers' => ['Accept: application/json']];
ok((int) one('SELECT count(*) FROM datasets') === 0, 'the lake is empty (no dataset)');
foreach ([26 => ['a Member (Priya)', false], 27 => ['a Data engineer (Marco)', true], 1 => ['the admin', true], 29 => ['an external (Ann)', false]] as $m => [$who, $source]) {
    [$j, ] = sign_on($m);
    $h = page($j, '/')['body'];
    ok(str_contains($h, 'id="home-first-run"') && substr_count($h, 'id="home-first-run-btn"') === 1 && str_contains($h, 'Upload a spreadsheet') && str_contains($h, 'href="/upload"'), "$who sees one button, Upload a spreadsheet, pointing at /upload");
    ok(preg_match('/<a [^>]*id="home-first-run-btn"[^>]*>/', $h, $btn) === 1 && (str_contains($btn[0], 'hx-get="/upload"') === nav_built_file('/upload/index.php')), "$who: the button is a plain link while slice 2's upload screen is not built, an HTMX link once it is");
    ok(!str_contains($h, 'id="home-search"') && !str_contains($h, 'id="home-popular"') && !str_contains($h, 'id="home-decisions"'), "$who sees nothing else on the home — no search card, no tiles");
    ok(str_contains($h, 'id="home-first-run-source"') === $source, "$who " . ($source ? 'also gets the link to connect a source (sources.manage)' : 'gets no link to connect a source'));
    $d = body_json(page($j, '/', $json))['data'] ?? [];
    ok(($d['first_run'] ?? null) === true && ($d['may']['upload'] ?? null) === true && ($d['popular'] ?? null) === [], "$who's home JSON: first_run true, may.upload true, nothing popular");
}
finish();
