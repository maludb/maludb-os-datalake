<?php
declare(strict_types=1);
/** Action `dataset_untag` (log dataset.untag — the owner, the steward or an engineer, and discover): **dataset**, **tag** (its id or slug). Untagging what it does not carry is a no-op. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/write.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/write.php';
dl_handler_begin();
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
require_describe($d);
$given = trim((string) (req_val('tag') ?? ''));
if ($given === '') {
    dl_refuse_fields(['tag' => 'Name the tag.']);
}
$id = (int) $d['id'];
[, $slug, $label, $changed] = dl_guard($pdo, static function () use ($pdo, $id, $given): array {
    $pdo->beginTransaction();
    $r = dataset_set_tag($pdo, $id, $given, false, (int) current_member_id());
    if ($r[3]) {
        log_activity($pdo, 'dataset.untag', 'dataset', $id, ['before' => ['tag' => $r[1], 'label' => $r[2], 'tag_id' => $r[0]]]);
    }
    $pdo->commit();
    return $r;
});
dl_done($changed ? 'Took ' . $label . ' off ' . $d['key'] : $d['key'] . ' does not carry ' . $label, $id, dl_land(return_path('/datasets/' . $id . '?tab=about#dataset-tags'), 'untagged'),
    'datasetChanged', ['tag' => $slug]);
