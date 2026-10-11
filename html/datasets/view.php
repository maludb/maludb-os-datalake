<?php
declare(strict_types=1);
/**
 * /datasets/{id|key}?tab= (screen `dataset-view`; catalog.md) — the dataset page: the header and the eight tabs by anchor (About, Columns,
 * Usage and History built here; Preview by slice 2, Access by slice 4; Quality and Lineage render "Built by slice N" until their slice replaces the partial),
 * the notes panel. THE ONE CALLER of require_level() at `discover` that renders a STUB: a dataset the person may know exists but not open
 * shows its title, key, owner, department and Request access — nothing else; below that the same 404 as a dataset that does not exist.
 * Logs screen.view with dataset_id. JSON: present_dataset() and the tabs' data (whitelisted), or present_dataset_stub().
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_once dirname(__DIR__, 2) . '/app/features/history/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/history/present.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/access/queries.php';      // slice 4: the Access tab
require_once dirname(__DIR__, 2) . '/app/features/access/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$ref = request_string('key') !== '' ? request_string('key') : request_string('id');
$gate = require_level($pdo, $ref, 'discover');
$id = (int) $gate['id'];
if ($gate['is_stub']) {
    $s = find_dataset_stub($pdo, $id) ?? respond_not_found('No such dataset.');
    log_screen_view($pdo, 'dataset-view', ['entity_type' => 'dataset', 'entity_id' => $id]);
    if (wants_json()) {
        respond_screen(['dataset' => present_dataset_stub($s)]);
    }
    render_screen($s['title'], view('catalog/dataset-stub.php', ['s' => $s]), ['activeNav' => 'browse', 'screen' => 'dataset-view', 'entity' => 'dataset',
        'recordId' => (string) $id, 'datasetKey' => $s['key']]);
    exit;
}
$d = find_dataset($pdo, $id) ?? respond_not_found('No such dataset.');
$tab = request_string('tab');
$tab = isset(DATASET_TABS[$tab]) ? $tab : 'about';
$historyPage = max(1, min(1000, request_integer('history_page') ?? 1));
$columns = dataset_columns($pdo, $id, false, false);
$usage = dataset_usage($pdo, $id, 30);
$history = dataset_history($pdo, $id, $historyPage);
$notes = find_notes($pdo, 'dataset', $id);
$follow = my_follow($pdo, $id);
$me = (int) current_member_id();
$may = [
    'describe'  => may_describe_dataset($d),
    'deprecate' => $d['my_level'] === 'own' || has_right('catalog.edit'),
    'retire'    => $d['zone'] === 'sandbox' ? $d['my_level'] === 'own' : has_right('catalog.edit'),
    'follow'    => has_right('follow.use'),
    'request'   => dl_level_rank((string) $d['my_level']) < DL_LEVELS['read'] && has_right('access.request'),
    'note'      => true,
];
log_screen_view($pdo, 'dataset-view', ['entity_type' => 'dataset', 'entity_id' => $id]);
if (wants_json()) {
    $tabs = [];
    foreach (DATASET_TABS as $name => $label) {
        $tabs[] = ['name' => $name, 'label' => $label, 'built' => dataset_tab_built($name)];
    }
    respond_screen(['dataset' => present_dataset($d), 'tab' => $tab, 'tabs' => $tabs, 'columns' => array_map('present_column', $columns), 'usage' => $usage,
        'history' => ['page' => $historyPage, 'total' => $history['total'], 'rows' => array_map('present_history_row', $history['rows'])],
        'notes' => array_map('present_note', $notes), 'follow' => present_follow($follow), 'may' => $may,
        'access' => present_access_tab(access_tab($pdo, $me, $id))]);                    // slice 4: the Access tab's data (who reads it, or my reasons)
}
$notices = ['created' => ['success', 'Registered. Describe it, and its columns appear when data first lands.'], 'updated' => ['success', 'Saved.'],
    'tagged' => ['success', 'Tagged.'], 'untagged' => ['success', 'Tag removed.'], 'deprecated' => ['warning', 'Deprecated — its followers have been told.'],
    'followed' => ['success', 'You follow it now: you will hear when it changes.'], 'unfollowed' => ['success', 'You no longer follow it.'],
    'note_added' => ['success', 'Note added.'], 'note_deleted' => ['success', 'Note deleted.']];
$suggestions = $may['describe'] ? array_map(static fn (array $t): array => ['slug' => $t['slug'], 'label' => $t['label']], find_tags($pdo, null, null, false, 60)) : [];
render_screen($d['title'], view('catalog/dataset.php', ['d' => $d, 'tab' => $tab, 'columns' => $columns, 'usage' => $usage, 'history' => $history,
    'history_page' => $historyPage, 'notes' => $notes, 'follow' => $follow, 'may' => $may, 'here' => here_url(), 'tag_suggestions' => $suggestions,
    'notice' => dl_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'browse', 'screen' => 'dataset-view', 'entity' => 'dataset', 'recordId' => (string) $id, 'datasetKey' => $d['key']]);
