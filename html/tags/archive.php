<?php
declare(strict_types=1);
/**
 * Action `tag_archive` (log tag.archive — catalog.edit; confirm): **tag**. It leaves the vocabulary and the pickers; the datasets that carry
 * it keep it; GRANTS ON THE TAG STAY UNTIL REVOKED (they reach every dataset that carries it — the answer says how many). Undo: tag_update.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/write.php';
dl_handler_begin();
require_right('catalog.edit');
$pdo = db();
$t = find_tag($pdo, (string) (req_val('tag') ?? '')) ?? respond_not_found('No such tag.');
if ($t['archived_at'] !== null) {
    refuse(422, 'The tag ' . $t['label'] . ' is already archived.');
}
$id = (int) $t['tag_id'];
$grants = tag_grant_count($pdo, $id);
dl_guard($pdo, static function () use ($pdo, $id, $t, $grants): void {
    $pdo->beginTransaction();
    tag_archive($pdo, $id);
    log_activity($pdo, 'tag.archive', 'tag', $id, ['after' => ['slug' => $t['slug'], 'label' => $t['label'], 'usage_count' => (int) $t['usage_count'], 'grants_kept' => $grants]]);
    $pdo->commit();
});
dl_done('Archived ' . $t['label'] . ($grants > 0 ? ' — grants on this tag stay until revoked (' . $grants . ')' : ''), $id, dl_land('/tags', $grants > 0 ? 'archived_grants' : 'archived'),
    'tagChanged', ['grants_kept' => $grants, 'warning' => $grants > 0 ? 'Grants on this tag stay until revoked.' : null]);
