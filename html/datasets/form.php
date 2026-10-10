<?php
declare(strict_types=1);
/**
 * /datasets/new (screen `dataset-add`, prefill `zone`, `name` — catalog.edit) and /datasets/{id|key}/edit (screen `dataset-edit` — the
 * owner, the steward or an engineer, and discover). ?key_preview=1 answers the key a new dataset will have as it is typed (Pattern A, for
 * the name and zone fields — the form's own fragment, nothing logged). JSON: the values and the choices.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/write.php';
require_login();
$pdo = db();
$ref = request_string('key') !== '' ? request_string('key') : request_string('id');
if ($ref === '' && request_string('key_preview') === '1') {
    require_right('catalog.edit');
    $zone = in_array(request_string('zone'), ['curated', 'product'], true) ? request_string('zone') : 'curated';
    $name = mb_substr(trim(request_string('name')), 0, 80);
    $state = null;
    $message = null;
    if ($name !== '' && !preg_match(DATASET_NAME_RE, $name)) {
        $state = 'bad';
    } elseif ($name !== '') {
        $message = one_value($pdo, 'SELECT dl_dataset_key_taken(:k)', ['k' => $zone . '.' . $name]);      // the database's sentence, or null when free
        $state = $message === null ? 'ok' : 'taken';
    }
    header('Vary: HX-Request');
    echo view('catalog/key-preview.php', ['zone' => $zone, 'name' => $name, 'state' => $state, 'message' => $message]);
    exit;
}
if ($ref === '') {
    require_right('catalog.edit');
    $zone = request_string('zone') === 'product' ? 'product' : 'curated';
    $name = strtolower(mb_substr(trim(request_string('name')), 0, 63));
    $v = ['zone' => $zone, 'name' => preg_match(DATASET_NAME_RE, $name) ? $name : '', 'title' => '', 'description' => '', 'owner_member_id' => (int) current_member_id(),
          'steward_member_id' => null, 'department_id' => null, 'tier' => '2', 'status' => 'draft', 'evolution_mode' => 'rescue',
          'freshness_warn_after_parts' => [null, 'hours'], 'freshness_error_after_parts' => [null, 'hours']];
    log_screen_view($pdo, 'dataset-add');
    if (wants_json()) {
        respond_screen(['values' => $v, 'zones' => ['curated', 'product'], 'tiers' => DATASET_TIERS]);
    }
    render_screen('New dataset', view('catalog/dataset-form.php', ['d' => null, 'v' => $v, 'may' => ['deprecate' => false]]), ['activeNav' => 'browse', 'screen' => 'dataset-add', 'entity' => 'dataset']);
    exit;
}
$gate = require_dataset($pdo, $ref, 'discover');
require_describe($gate);
$d = find_dataset($pdo, (int) $gate['id']) ?? respond_not_found('No such dataset.');
$v = $d + ['freshness_warn_after_parts' => interval_parts($d['freshness_warn_seconds']), 'freshness_error_after_parts' => interval_parts($d['freshness_error_seconds'])];
$may = ['deprecate' => $d['my_level'] === 'own' || has_right('catalog.edit')];
log_screen_view($pdo, 'dataset-edit', ['entity_type' => 'dataset', 'entity_id' => (int) $d['dataset_id']]);
if (wants_json()) {
    $p = present_dataset($d);
    respond_screen(['dataset_id' => (int) $d['dataset_id'], 'values' => array_intersect_key($p, array_flip(['title', 'description', 'purpose', 'usage', 'limitations', 'tier', 'status',
        'freshness_warn_after', 'freshness_error_after', 'evolution_mode'])) + ['owner_member_id' => $p['owner']['member_id'], 'steward_member_id' => $p['steward']['member_id'],
        'department_id' => $p['department']['department_id'], 'deprecation_note' => $p['deprecation_note'], 'successor' => $p['successor']],
        'tiers' => DATASET_TIERS, 'evolution_modes' => EVOLUTION_MODES, 'may' => $may]);
}
render_screen('Edit ' . $d['title'], view('catalog/dataset-form.php', ['d' => $d, 'v' => $v, 'may' => $may]),
    ['activeNav' => 'browse', 'screen' => 'dataset-edit', 'entity' => 'dataset', 'recordId' => (string) $d['dataset_id'], 'datasetKey' => $d['key']]);
