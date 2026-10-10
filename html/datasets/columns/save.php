<?php
declare(strict_types=1);
/**
 * Action `column_update` (log column.update — the owner, the steward or an engineer, and discover): **dataset**, **column** (its id or
 * name), business_name, description (4000), term (a glossary term — or none), critical (yes or no), references_dataset (a dataset the
 * caller may discover — or none), references_column (a live column of it). NEVER the name, the type, the classification or the personal
 * kind (divergence 3): a forged field is ignored, and dl_dataset_columns_guard refuses a name or type from anyone but the writer. The
 * reference is a pair (db/017's CHECK): both or neither.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/catalog/write.php';
dl_handler_begin();
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
require_describe($d);
$id = (int) $d['id'];
$c = find_column($pdo, $id, (string) (req_val('column') ?? '')) ?? respond_not_found('No such column.');
if ($c['removed_snapshot_id'] !== null) {
    refuse(422, 'That column was removed from the dataset; its description stays as it was.');
}
$cid = (int) $c['column_id'];
$cur = column_current($pdo, $cid);
$errors = [];
$f = [];
$f['business_name'] = dl_text('business_name', $cur['business_name'], 200, 'The business name', $errors);
$f['description'] = dl_text('description', $cur['description'], 4000, 'The description', $errors);
$f['glossary_term_id'] = $cur['glossary_term_id'];
if (req_has('term')) {
    $t = trim((string) req_val('term'));
    if ($t === '' || $t === '0' || strtolower($t) === 'none') {
        $f['glossary_term_id'] = null;
    } else {
        $tid = ctype_digit($t) ? one_value($pdo, "SELECT term_id FROM mcp_glossary_terms WHERE term_id = :v AND status <> 'deprecated'", ['v' => (int) $t])
                               : one_value($pdo, "SELECT term_id FROM mcp_glossary_terms WHERE lower(name) = lower(:v) AND status <> 'deprecated'", ['v' => $t]);
        if ($tid === null) {
            $errors['term'] = 'Choose the term from the glossary (a deprecated term is not offered).';
        } else {
            $f['glossary_term_id'] = (int) $tid;
        }
    }
}
$f['critical'] = dl_yes('critical', $cur['critical']);
$f['references_dataset_id'] = $cur['references_dataset_id'];
$f['references_column'] = $cur['references_column'];
if (req_has('references_dataset') || req_has('references_column')) {
    $rd = trim((string) (req_val('references_dataset') ?? (string) $cur['references_dataset_id']));
    $rc = trim((string) (req_val('references_column') ?? (string) $cur['references_column']));
    if ($rd === '' || $rd === '0' || strtolower($rd) === 'none') {
        [$f['references_dataset_id'], $f['references_column']] = [null, null];
    } else {
        $r = dataset_by_ref($pdo, $rd);
        if ($r === null || !db_bool($pdo, 'SELECT dl_can_discover(:id)', ['id' => (int) $r['id']])) {
            $errors['references_dataset'] = 'Choose the referenced dataset from the datasets you may see.';
        } elseif ($rc === '') {
            $errors['references_column'] = 'Choose the column it references.';
        } else {
            $live = column_names_for_pick($pdo, (int) $r['id']);
            $match = array_values(array_filter($live, static fn (string $n): bool => strtolower($n) === strtolower($rc)));
            if ($match === []) {
                $errors['references_column'] = 'That is not a live column of ' . $r['key'] . '.';
            } else {
                [$f['references_dataset_id'], $f['references_column']] = [(int) $r['id'], $match[0]];
            }
        }
    }
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$diff = dl_diff($cur, $f);
if ($diff['after'] !== []) {
    dl_guard($pdo, static function () use ($pdo, $cid, $id, $c, $diff): void {
        $pdo->beginTransaction();
        column_save($pdo, $cid, $diff['after']);
        $b = $diff['before'];
        $a = $diff['after'];
        foreach (['description'] as $k) {
            if (array_key_exists($k, $a)) {
                [$b[$k], $a[$k]] = [$b[$k] === null ? null : ['length' => mb_strlen((string) $b[$k])], $a[$k] === null ? null : ['length' => mb_strlen((string) $a[$k])]];
            }
        }
        log_activity($pdo, 'column.update', 'column', $cid, ['dataset_id' => $id, 'before' => $b, 'after' => $a + ['name' => $c['name']]]);
        $pdo->commit();
    });
}
dl_done($diff['after'] === [] ? 'Nothing to change' : 'Described ' . $d['key'] . '.' . $c['name'], $cid,
    dl_land(return_path('/datasets/' . $id . '/columns/' . rawurlencode((string) $c['name'])), 'updated'), 'columnChanged', ['changed' => array_keys($diff['after'])]);
