<?php
declare(strict_types=1);
/**
 * Action `template_save` (log template.save — sandbox.upload; changing one: its maker or loads.manage): **name**, **kind** (csv, xlsx, json,
 * parquet), **mapping** (an object as upload_map_confirm's columns — or the form's rows[i][source|name|type|personal_kind|skip]), template (to
 * update one). A name is unique. The log names the template, its kind and its columns' count — never a value.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/write.php';
require_once dirname(__DIR__, 2) . '/app/features/uploads/queries.php';
dl_handler_begin();
require_right('sandbox.upload');
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$cur = null;
if (($ref = req_val('template')) !== null && $ref !== '') {
    $cur = find_template($pdo, $ref) ?? respond_not_found('No such saved mapping.');
    if (!may_manage_template($cur)) {
        refuse(403, 'Only its maker or a data engineer may change the mapping ' . $cur['name'] . '.');
    }
}
$name = dl_text('name', $cur['name'] ?? null, 120, 'The name', $errors, true);
$kind = dl_choice('kind', $cur['kind'] ?? null, array_keys(TEMPLATE_KINDS), 'The kind', $errors, false);
$mapping = $cur['mapping'] ?? null;
if (req_has('mapping')) {
    $raw = $_POST['mapping'];
    $m = is_array($raw) ? $raw : json_decode((string) $raw, true);
    $mapping = is_array($m) ? (isset($m['columns']) ? $m : ['columns' => $m]) : null;
    if ($mapping === null) {
        $errors['mapping'] = 'The mapping is an object of source column to name, type and personal kind.';
    }
} elseif (isset($_POST['rows']) && is_array($_POST['rows'])) {
    $cols = [];
    foreach ($_POST['rows'] as $r) {
        if (is_array($r) && ($r['source'] ?? '') !== '') {
            $cols[(string) $r['source']] = ['name' => strtolower(trim((string) ($r['name'] ?? ''))), 'type' => strtoupper((string) ($r['type'] ?? 'VARCHAR')),
                'personal_kind' => ($r['personal_kind'] ?? '') === '' ? null : (string) $r['personal_kind'], 'skip' => ($r['skip'] ?? '0') === '1'];
        }
    }
    $mapping = ['columns' => $cols] + (array) ($cur['mapping'] ?? []);
    $mapping['columns'] = $cols;
}
if ($mapping === null && !isset($errors['mapping'])) {
    $errors['mapping'] = 'The mapping is required.';
}
foreach ((array) ($mapping['columns'] ?? []) as $src => $c) {
    $c = (array) $c;
    if (empty($c['skip']) && !preg_match('/^[a-z][a-z0-9_]{0,62}$/', (string) ($c['name'] ?? ''))) {
        $errors['mapping'] = 'Column "' . $src . '": a name is lower-case letters, digits and underscores, starting with a letter.';
    }
    if (isset($c['type']) && !isset(MAP_TYPES[strtoupper((string) $c['type'])])) {
        $errors['mapping'] = 'Column "' . $src . '": the type is one of ' . implode(', ', array_keys(MAP_TYPES)) . '.';
    }
    if (($c['personal_kind'] ?? null) !== null && $c['personal_kind'] !== '' && !isset(PERSONAL_KINDS[$c['personal_kind']])) {
        $errors['mapping'] = 'Column "' . $src . '": personal data is one of ' . implode(', ', array_keys(PERSONAL_KINDS)) . '.';
    }
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$id = dl_guard($pdo, static function () use ($pdo, $cur, $name, $kind, $mapping, $me): int {
    $pdo->beginTransaction();
    try {
        $id = template_save($pdo, $cur === null ? null : (int) $cur['template_id'], ['name' => $name, 'kind' => $kind, 'mapping' => $mapping], $me);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23505') {
            $pdo->rollBack();
            dl_refuse_fields(['name' => 'A saved mapping named ' . $name . ' exists — choose another name.']);
        }
        throw $e;
    }
    $after = ['name' => $name, 'kind' => $kind, 'columns' => count((array) ($mapping['columns'] ?? []))];
    log_activity($pdo, 'template.save', 'template', $id, $cur === null ? ['after' => $after]
        : ['before' => ['name' => $cur['name'], 'kind' => $cur['kind'], 'columns' => count((array) ($cur['mapping']['columns'] ?? []))], 'after' => $after]);
    $pdo->commit();
    return $id;
});
dl_done('Saved the mapping ' . $name, $id, dl_land('/templates', 'saved'), 'templateChanged');
