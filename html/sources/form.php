<?php
declare(strict_types=1);
/**
 * /sources/new?connector= (screen `source-add`; step 1 — no connector yet — shows the five as cards) and /sources/{id}/edit (screen `source-edit`; the
 * connector is fixed) — sources.manage. The same form for both (slice 1's dataset form): the name, the raw dataset it lands in (the record picker, or the
 * name of a new one), the connector's own fields, the schedule (minutes between pulls; 0 = manual) and the rate. The credential is NOT here: it is sealed on
 * the source's page. JSON: the values and the choices.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_right('sources.manage');
$pdo = db();
$id = request_integer('id') ?? 0;
if ($id > 0) {
    $s = find_source($pdo, $id) ?? respond_not_found('No such source.');
    $connector = (string) $s['connector'];
    $v = source_form_values($connector, $s);
    $target = isset($s['settings']['target_dataset_id']) ? (int) $s['settings']['target_dataset_id'] : null;
    log_screen_view($pdo, 'source-edit', ['entity_type' => 'source', 'entity_id' => $id] + ($target === null ? [] : ['dataset_id' => $target]));
    if (wants_json()) {
        $vals = $v;
        if (isset($vals['url'])) {
            $vals['url'] = url_display($vals['url']);
        }
        respond_screen(['source_id' => $id, 'connector' => $connector, 'values' => $vals, 'formats' => array_keys(DL_FILE_FORMATS)]);
    }
    render_screen('Edit ' . $s['name'], view('sources/form.php', ['s' => $s, 'connector' => $connector, 'v' => $v]),
        ['activeNav' => 'sources', 'screen' => 'source-edit', 'entity' => 'source', 'recordId' => (string) $id]);
    exit;
}
$connector = strtolower(request_string('connector'));
if (!in_array($connector, DL_CONNECTORS_V1, true)) {
    log_screen_view($pdo, 'source-add');
    if (wants_json()) {
        respond_screen(['connectors' => array_values(array_map(static fn (array $c): array => ['key' => $c['key'], 'label' => $c['label'], 'description' => $c['description'], 'pulls' => $c['capabilities']['pulls'],
            'credential_kinds' => $c['capabilities']['credential_kinds']], dl_fetch_connectors()))]);
    }
    render_screen('Add a source', view('sources/form.php', ['s' => null, 'connector' => null, 'v' => []]), ['activeNav' => 'sources', 'screen' => 'source-add', 'entity' => 'source']);
    exit;
}
$v = source_form_values($connector, null);
$v['target_dataset'] = ($t = request_integer('target_dataset')) !== null && $t > 0 ? $t : null;
log_screen_view($pdo, 'source-add');
if (wants_json()) {
    respond_screen(['connector' => $connector, 'values' => $v, 'formats' => array_keys(DL_FILE_FORMATS)]);
}
render_screen('Add a source', view('sources/form.php', ['s' => null, 'connector' => $connector, 'v' => $v]), ['activeNav' => 'sources', 'screen' => 'source-add', 'entity' => 'source']);
