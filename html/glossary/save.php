<?php
declare(strict_types=1);
/**
 * Actions `term_create` (no `term`; log term.create) and `term_update` (`term` + any field; log term.update) — catalog.edit: **name** (up to
 * 120), **definition** (up to 4,000), synonyms[] (or comma-separated), parent (a term — the category; not itself, not below itself), steward
 * (a member). A new term is a draft. A live name is unique, case aside (the database's index; said on the field).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/glossary/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/glossary/write.php';
dl_handler_begin();
require_right('catalog.edit');
$pdo = db();
$me = (int) current_member_id();
$given = (string) (req_val('term') ?? '');
$t = null;
if ($given !== '') {
    $t = ctype_digit($given) && strlen($given) <= 18 ? find_term($pdo, (int) $given) : null;
    if ($t === null) {
        respond_not_found('No such term.');
    }
}
$id = $t === null ? null : (int) $t['term_id'];
$cur = $t === null ? ['name' => null, 'definition' => null, 'synonyms' => [], 'parent_id' => null, 'steward_member_id' => null]
    : ['name' => $t['name'], 'definition' => $t['definition'], 'synonyms' => $t['synonyms'], 'parent_id' => $t['parent_id'] === null ? null : (int) $t['parent_id'],
       'steward_member_id' => $t['steward_member_id'] === null ? null : (int) $t['steward_member_id']];
$errors = [];
$f = [];
$f['name'] = dl_text('name', $cur['name'], 120, 'The name', $errors, true);
$f['definition'] = dl_text('definition', $cur['definition'], 4000, 'The definition', $errors, true);
foreach (['name' => 'The name is required.', 'definition' => 'The definition is required.'] as $k => $sentence) {
    if ($f[$k] === null && !isset($errors[$k])) {
        $errors[$k] = $sentence;
    }
}
$syn = request_list('synonyms');
if ($syn !== null) {
    $syn = array_values(array_unique(array_map(static fn (string $s): string => mb_substr($s, 0, 120), $syn)));
    if (count($syn) > 30) {
        $errors['synonyms'] = 'At most 30 synonyms.';
    }
}
$f['synonyms'] = $syn ?? $cur['synonyms'];
$f['parent_id'] = dl_ref($pdo, 'parent', $cur['parent_id'], "SELECT 1 FROM mcp_glossary_terms WHERE term_id = :id AND status <> 'deprecated'", 'category', $errors);
if ($id !== null && $f['parent_id'] !== null && $f['parent_id'] !== $cur['parent_id']) {
    $cycle = (bool) one_value($pdo, 'WITH RECURSIVE up(id) AS (SELECT CAST(:p AS bigint) UNION SELECT g.parent_id FROM glossary_terms g JOIN up ON g.id = up.id WHERE g.parent_id IS NOT NULL)
                                     SELECT EXISTS (SELECT 1 FROM up WHERE id = :me)', ['p' => $f['parent_id'], 'me' => $id]);
    if ($cycle) {
        $errors['parent'] = 'A term is not its own category, nor a category of one of its own terms.';
    }
}
$f['steward_member_id'] = dl_ref($pdo, 'steward', $cur['steward_member_id'], 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'steward', $errors);
if ($f['name'] !== null && one_value($pdo, "SELECT 1 FROM mcp_glossary_terms WHERE lower(name) = lower(:n) AND status <> 'deprecated' AND term_id <> COALESCE(CAST(:id AS bigint), 0)",
        ['n' => $f['name'], 'id' => $id]) !== null) {
    $errors['name'] = 'There is already a term named ' . $f['name'] . '.';
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
if ($id === null) {
    $newId = dl_guard($pdo, static function () use ($pdo, $f, $me): int {
        $pdo->beginTransaction();
        $newId = term_save($pdo, null, $f, $me);
        log_activity($pdo, 'term.create', 'glossary_term', $newId, ['after' => ['name' => $f['name'], 'parent_id' => $f['parent_id'], 'steward_member_id' => $f['steward_member_id'],
            'synonyms' => count($f['synonyms']), 'definition' => ['length' => mb_strlen((string) $f['definition'])]]]);
        $pdo->commit();
        return $newId;
    });
    dl_done('Added the term ' . $f['name'] . ' as a draft', $newId, dl_land('/glossary/' . $newId, 'created'), 'termChanged');
}
$diff = dl_diff($cur, $f);
if ($diff['after'] !== []) {
    dl_guard($pdo, static function () use ($pdo, $id, $diff, $me, $f): void {
        $pdo->beginTransaction();
        term_save($pdo, $id, $diff['after'], $me);
        $b = $diff['before'];
        $a = $diff['after'];
        if (array_key_exists('definition', $a)) {
            [$b['definition'], $a['definition']] = [['length' => mb_strlen((string) $b['definition'])], ['length' => mb_strlen((string) $a['definition'])]];
        }
        if (array_key_exists('synonyms', $a)) {
            [$b['synonyms'], $a['synonyms']] = [count((array) $b['synonyms']), count((array) $a['synonyms'])];
        }
        log_activity($pdo, 'term.update', 'glossary_term', $id, ['before' => $b, 'after' => $a + ['name' => $f['name']]]);
        $pdo->commit();
    });
}
dl_done($diff['after'] === [] ? 'Nothing to change' : 'Saved the term ' . $f['name'], $id, dl_land('/glossary/' . $id, 'updated'), 'termChanged');
