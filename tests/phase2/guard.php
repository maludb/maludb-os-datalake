<?php
/**
 * Proof: the write guard reads the schema's refusals as sentences (the kit's DB_SENTENCE_CODES, carried from Sales CRM's Phase 2 fix):
 * db_message() against the lake's real schema on the scratch database, each case rolled back — a trigger's rule (check_violation), a
 * function's no_data_found, a key rotated twice; PostgreSQL's own constraint text is never shown to a person (a plain CHECK, a duplicate).
 */
require __DIR__ . '/lib.php';
require dirname(__DIR__, 2) . '/app/db.php';
ini_set('error_log', '/dev/null');                                       // db_message() logs every error it reads: these are expected
seed_lake();
$orders = (int) one("SELECT id FROM datasets WHERE key = 'curated.sales_orders'");
$cases = [
    ['23514', "A dataset's key never changes", "UPDATE datasets SET key = 'curated.renamed', name = 'renamed' WHERE id = $orders"],
    ['23514', 'Only a live key is rotated', "SELECT dl_key_rotate(999999, 'x')"],
    ['P0002', 'No such request', 'SELECT dl_access_decide(999999, true)'],
    ['23514', '(fallback)', "INSERT INTO tags (slug, label) VALUES ('Not A Slug', 'x')"],
    ['23505', '(fallback)', "INSERT INTO tags (slug, label) VALUES ('smoke-dup', 'x'); INSERT INTO tags (slug, label) VALUES ('smoke-dup', 'y')"],
];
foreach ($cases as [$code, $want, $sql]) {
    as_member(1);
    $p = pdo();
    $p->beginTransaction();
    try { $p->exec($sql); $got = null; $msg = 'no error'; } catch (PDOException $e) { $got = (string) $e->getCode(); $msg = db_message($e, '(fallback)'); }
    $p->rollBack();
    ok($got === $code && str_starts_with($msg, $want), "$code answers \"" . mb_substr($msg, 0, 70) . "\"" . ($want === '(fallback)' ? ' — a constraint\'s own text falls back to the handler\'s sentence' : ' — the schema\'s sentence'));
}
$src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/handler.php');
ok(str_contains($src, "['P0001', '23514', '23503']") && str_contains($src, "=== 'P0002'") && str_contains($src, "db_message(\$e, 'That is already taken.')"), 'dl_guard() answers 422 for a rule refused, a missing referent and a taken value, 404 for no_data_found');
finish();
