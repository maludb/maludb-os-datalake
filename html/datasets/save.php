<?php
declare(strict_types=1);
/**
 * Actions `dataset_create` (no `dataset`; log dataset.create — catalog.edit) and `dataset_update` (`dataset` + any field; log dataset.update —
 * the owner, the steward or an engineer, and discover). THE CRUD EXEMPLAR'S HANDLER ORDER: dl_handler_begin() → require_right() /
 * require_dataset() + the edit rule → the fields (a field left out stays as it was; an emptied one is cleared) → dl_guard() around the
 * database's verb → log_activity() (field NAMES and short values — a text over 200 characters by its length) → dl_done().
 * create: zone (curated or product only — divergence 2), name, title, description, owner (the caller by default), steward, department,
 * tier. The key's form and its uniqueness for ever are the database's (db/007 CHECK and the unique index; db/020's dl_dataset_key_taken() says a taken key in words, named on `name`).
 * update: title, description, purpose, usage, limitations, owner, steward, department, tier, freshness_warn_after / freshness_error_after
 * (an interval such as "6 hours" — or the form's number + unit), evolution_mode, status (draft or active — bringing a deprecated one back
 * clears its note and successor). Technical metadata has no field here: a forged row_count is ignored (and dl_datasets_guard refuses it).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/write.php';
dl_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$ref = (string) (req_val('dataset') ?? '');
$errors = [];
$memberSql = 'SELECT 1 FROM mcp_members WHERE member_id = :id';
$deptSql = 'SELECT 1 FROM mcp_departments WHERE department_id = :id AND archived_at IS NULL';
/** A long text for the log: itself up to 200 characters, else its length. */
$short = static fn (?string $t) => $t === null ? null : (mb_strlen($t) <= 200 ? $t : ['length' => mb_strlen($t)]);

if ($ref === '') {
    require_right('catalog.edit');
    $zone = (string) (req_val('zone') ?? '');
    if (in_array($zone, ['raw', 'sandbox'], true)) {
        dl_refuse_fields(['zone' => 'A raw dataset is made by a load; a sandbox one by its owner']);
    }
    if (!in_array($zone, ['curated', 'product'], true)) {
        $errors['zone'] = 'The zone is curated or product.';
    }
    $name = strtolower(trim((string) (req_val('name') ?? '')));
    if ($name === '') {
        $errors['name'] = 'The name is required.';
    } elseif (!preg_match(DATASET_NAME_RE, $name)) {
        $errors['name'] = 'The name is lowercase letters, digits and underscores, starting with a letter (at most 63).';
    }
    $title = dl_text('title', null, 200, 'The title', $errors, true);
    if ($title === null && !isset($errors['title'])) {
        $errors['title'] = 'The title is required.';
    }
    $f = ['zone' => $zone, 'name' => $name, 'title' => $title,
          'description' => dl_text('description', null, 8000, 'The description', $errors),
          'owner_member_id' => dl_ref($pdo, 'owner', $me, $memberSql, 'owner', $errors),
          'steward_member_id' => dl_ref($pdo, 'steward', null, $memberSql, 'steward', $errors),
          'department_id' => dl_ref($pdo, 'department', null, $deptSql, 'department', $errors),
          'tier' => dl_choice('tier', '2', ['1', '2', '3'], 'The tier', $errors, false) ?? '2'];
    if ($errors !== []) {
        dl_refuse_fields($errors);
    }
    $newId = dl_guard($pdo, static function () use ($pdo, $f, $me, $short): int {
        $pdo->beginTransaction();
        try {
            $newId = dataset_save($pdo, null, $f, $me);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23505') {                 // the unique index refused: the database's sentence (db/020), on the field
                $pdo->rollBack();
                $key = $f['zone'] . '.' . $f['name'];
                dl_refuse_fields(['name' => (string) (one_value($pdo, 'SELECT dl_dataset_key_taken(:k)', ['k' => $key]) ?? $key . ' is taken — choose another name')]);
            }
            throw $e;
        }
        log_activity($pdo, 'dataset.create', 'dataset', $newId, ['after' => ['key' => $f['zone'] . '.' . $f['name'], 'title' => $f['title'], 'zone' => $f['zone'],
            'owner_member_id' => $f['owner_member_id'], 'steward_member_id' => $f['steward_member_id'], 'department_id' => $f['department_id'], 'tier' => $f['tier'],
            'description' => $short($f['description'])]]);
        $pdo->commit();
        return $newId;
    });
    dl_done('Registered ' . $f['zone'] . '.' . $f['name'], $newId, dl_land('/datasets/' . $newId, 'created'), 'datasetChanged');
}

$d = require_dataset($pdo, $ref, 'discover');
require_describe($d);
$id = (int) $d['id'];
$cur = dataset_current($pdo, $id);
/** An interval field: "6 hours" / "2 days" (an agent), or the form's {name}_n + {name}_unit; '' clears; left out keeps. Canonical text or null. */
$interval = static function (string $name, ?string $keep, string $label) use (&$errors): ?string {
    if (req_has($name)) {
        $raw = strtolower((string) req_val($name));
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^(\d{1,4})\s*(hour|hours|h|day|days|d)$/', $raw, $m)) {
            $errors[$name] = $label . ' is a number of hours or days, such as 6 hours or 2 days.';
            return $keep;
        }
        [$n, $unit] = [(int) $m[1], str_starts_with($m[2], 'h') ? 'hours' : 'days'];
    } elseif (req_has($name . '_n')) {
        $raw = (string) req_val($name . '_n');
        if ($raw === '') {
            return null;
        }
        if (!ctype_digit($raw)) {
            $errors[$name] = $label . ' is a whole number of hours or days.';
            return $keep;
        }
        [$n, $unit] = [(int) $raw, req_val($name . '_unit') === 'days' ? 'days' : 'hours'];
    } else {
        return $keep;
    }
    if ($n < 1 || ($unit === 'hours' && $n > 8760) || ($unit === 'days' && $n > 365)) {
        $errors[$name] = $label . ' is from 1 hour to 365 days.';
        return $keep;
    }
    if ($unit === 'hours' && $n % 24 === 0) {
        [$n, $unit] = [intdiv($n, 24), 'days'];
    }
    return $n . ' ' . ($n === 1 ? rtrim($unit, 's') : $unit);
};
$f = [];
$f['title'] = dl_text('title', $cur['title'], 200, 'The title', $errors, true);
$f['description'] = dl_text('description', $cur['description'], 8000, 'The description', $errors);
foreach (['purpose' => 'The purpose', 'usage' => 'How to use it', 'limitations' => 'The limitations'] as $k => $label) {
    $f[$k] = dl_text($k, $cur[$k], 4000, $label, $errors);
}
if ($cur['zone'] === 'sandbox') {
    if (req_has('owner') && (string) req_val('owner') !== (string) $cur['owner_member_id']) {
        $errors['owner'] = 'A sandbox dataset is its owner\'s — the person it is named for.';
    }
    $f['owner_member_id'] = $cur['owner_member_id'];
} else {
    $f['owner_member_id'] = dl_ref($pdo, 'owner', $cur['owner_member_id'], $memberSql, 'owner', $errors);
}
$f['steward_member_id'] = dl_ref($pdo, 'steward', $cur['steward_member_id'], $memberSql, 'steward', $errors);
$f['department_id'] = dl_ref($pdo, 'department', $cur['department_id'], $deptSql, 'department', $errors);
$f['tier'] = dl_choice('tier', $cur['tier'], array_values(array_unique(['1', '2', '3', (string) $cur['tier']])), 'The tier', $errors, false) ?? $cur['tier'];
$f['evolution_mode'] = dl_choice('evolution_mode', $cur['evolution_mode'], array_keys(EVOLUTION_MODES), 'The schema evolution', $errors, false) ?? $cur['evolution_mode'];
$status = dl_choice('status', $cur['status'], ['draft', 'active'], 'The status', $errors);
$f['status'] = $status ?? $cur['status'];                                // '' (the form's "still deprecated") keeps it
$f['freshness_warn_after'] = $interval('freshness_warn_after', $cur['freshness_warn_after'], 'Warn after');
$f['freshness_error_after'] = $interval('freshness_error_after', $cur['freshness_error_after'], 'Fail after');
$secs = static fn (?string $i): ?int => $i === null ? null : (int) $i * (str_contains($i, 'day') ? 86400 : 3600);
if ($f['freshness_warn_after'] !== null && $f['freshness_error_after'] !== null && $secs($f['freshness_error_after']) < $secs($f['freshness_warn_after'])) {
    $errors['freshness_error_after'] = 'Fail after is at least as long as warn after.';
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$diff = dl_diff($cur, array_intersect_key($f, $cur));
if ($diff['after'] !== []) {
    dl_guard($pdo, static function () use ($pdo, $id, $diff, $me, $short): void {
        $pdo->beginTransaction();
        dataset_save($pdo, $id, $diff['after'], $me);
        $b = $diff['before'];
        $a = $diff['after'];
        foreach (['description', 'purpose', 'usage', 'limitations'] as $k) {
            if (array_key_exists($k, $a)) {
                $b[$k] = $short($b[$k]);
                $a[$k] = $short($a[$k]);
            }
        }
        log_activity($pdo, 'dataset.update', 'dataset', $id, ['before' => $b, 'after' => $a]);
        $pdo->commit();
    });
}
dl_done($diff['after'] === [] ? 'Nothing to change' : 'Saved ' . $f['title'], $id, dl_land(return_path('/datasets/' . $id), 'updated'), 'datasetChanged',
    ['changed' => array_keys($diff['after'])]);
