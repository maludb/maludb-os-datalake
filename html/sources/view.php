<?php
declare(strict_types=1);
/**
 * /sources/{id}?tab= (screen `source-view`; sources-pulls.md): the header (name, connector, the dataset it feeds, the state and why) with Test · Pull now ·
 * Pause / Resume · ⋯ (Edit · Credential · Delete), and three tabs by anchor — Settings (the connector's settings as a definition list; the credential's kind,
 * label and last four — the form to seal a new one), Pulls (the last 50: started, status, items seen, items landed, bytes, requests, the error, the loads) and
 * Feeds (the datasets it feeds, from the lineage). `?partial=pulls` (HTMX) answers the pulls table alone — it polls every 3 s while a pull is queued or
 * running. sources.manage. Logs screen.view with the target dataset. JSON: present_source(), the pulls and the feeds.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_right('sources.manage');
$pdo = db();
$id = request_integer('id') ?? 0;
$s = $id > 0 ? find_source($pdo, $id) : null;
if ($s === null) {
    respond_not_found('No such source.');
}
$tab = request_string('tab');
$tab = isset(SOURCE_TABS[$tab]) ? $tab : 'settings';
$pulls = source_pulls($pdo, $id, 50);
if (request_string('partial') === 'pulls' && is_htmx_request()) {
    header('Vary: HX-Request');
    echo view('sources/partials/pulls-table.php', ['s' => $s, 'pulls' => $pulls]);
    exit;
}
$feeds = source_feeds($pdo, $id);
$target = isset($s['settings']['target_dataset_id']) ? (int) $s['settings']['target_dataset_id'] : null;
log_screen_view($pdo, 'source-view', ['entity_type' => 'source', 'entity_id' => $id] + ($target === null ? [] : ['dataset_id' => $target]));
$caps = dl_fetch_connector((string) $s['connector'])->capabilities();
$may = ['test' => true, 'pull' => $caps['pulls'] && $s['paused_at'] === null && $s['active'], 'pause' => $caps['pulls'] && $s['paused_at'] === null, 'resume' => $s['paused_at'] !== null,
        'edit' => true, 'delete' => true, 'credential' => $caps['credential_kinds'] !== [] && (current_member()['member_kind'] ?? '') === 'human'];
if (wants_json()) {
    respond_screen(['source' => present_source($s, $may), 'tab' => $tab, 'tabs' => array_map(static fn (string $k, string $l): array => ['name' => $k, 'label' => $l], array_keys(SOURCE_TABS), array_values(SOURCE_TABS)),
        'pulls' => array_map('present_pull', $pulls), 'feeds' => array_map('present_feed', $feeds),
        'connector' => ['key' => $s['connector'], 'label' => $caps['label'], 'pulls' => $caps['pulls'], 'credential_kinds' => $caps['credential_kinds']]]);
}
$notices = ['created' => ['success', 'Added. Test it, then pull it now — or let the schedule do it.'], 'updated' => ['success', 'Saved.'],
    'queued' => ['info', 'Queued — the worker takes the pull within the minute.'], 'paused' => ['warning', 'Paused — it is not pulled until you resume it.'],
    'resumed' => ['success', 'Resumed.'], 'credential' => ['success', 'The credential is sealed. Its last four characters are all that shows from now on.']];
$notice = dl_notice($_GET['notice'] ?? null, $notices);
if ($notice === null && str_starts_with((string) ($_GET['notice'] ?? ''), 'test_')) {
    $state = substr((string) $_GET['notice'], 5);
    $said = mb_substr(trim(request_string('said')), 0, 240);
    $notice = [$state === 'ok' ? 'success' : ($state === 'blocked' ? 'warning' : 'danger'), ($said !== '' ? $said : 'Tested.')];
}
render_screen($s['name'], view('sources/view.php', ['s' => $s, 'tab' => $tab, 'pulls' => $pulls, 'feeds' => $feeds, 'may' => $may, 'caps' => $caps, 'here' => here_url(), 'notice' => $notice]),
    ['activeNav' => 'sources', 'screen' => 'source-view', 'entity' => 'source', 'recordId' => (string) $id]);
