<?php
declare(strict_types=1);
/**
 * Action `dataset_tag` (log dataset.tag — the owner, the steward or an engineer, and discover): **dataset**, **tag** — a tag's id, its slug or
 * its words; a new slug makes a FREE tag labelled as typed. Tagging twice is a no-op. (A grant on the tag then reaches this dataset — the
 * manifest leaves this free inside the business; the Access tab of slice 4 shows why someone can read.)
 */
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
[$tagId, $slug, $label, $changed] = dl_guard($pdo, static function () use ($pdo, $id, $given): array {
    $pdo->beginTransaction();
    $r = dataset_set_tag($pdo, $id, $given, true, (int) current_member_id());
    if ($r[3]) {
        log_activity($pdo, 'dataset.tag', 'dataset', $id, ['after' => ['tag' => $r[1], 'label' => $r[2], 'tag_id' => $r[0]]]);
    }
    $pdo->commit();
    return $r;
});
dl_done($changed ? 'Tagged ' . $d['key'] . ' ' . $label : $d['key'] . ' already carries ' . $label, $id, dl_land(return_path('/datasets/' . $id . '?tab=about#dataset-tags'), 'tagged'),
    'datasetChanged', ['tag' => $slug]);
