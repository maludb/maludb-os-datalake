<?php
declare(strict_types=1);
/**
 * Action `grant_revoke` (log grant.revoke — the owner on their own dataset's grants, or grants.manage; confirm; pauses for an agent:
 * external_send): **grant**. revoked_at and revoked_by — never DELETE; the trail by trigger; it stops at once (the next query). Undo: grant_set.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/access/write.php';
dl_handler_begin();
$pdo = db();
$id = (int) (req_val('grant') ?? 0);
$g = $id > 0 ? find_grant($pdo, $id) : null;
if ($g === null) {
    respond_not_found('No such grant.');
}
if (!may_grant_on($pdo, (string) $g['target_kind'], $g['dataset_id'] === null ? null : (int) $g['dataset_id'])) {
    refuse(403, $g['target_kind'] === 'dataset' ? 'Only the dataset\'s owner or a Lake admin revokes its grants.' : 'A grant on a zone or a tag is revoked by a Lake admin.');
}
if ($g['revoked_at'] !== null) {
    refuse(422, 'That grant is already revoked.');
}
$me = (int) current_member_id();
dl_guard($pdo, static function () use ($pdo, $id, $me, $g): void {
    $pdo->beginTransaction();
    grant_revoke($pdo, $id, $me);
    log_activity($pdo, 'grant.revoke', 'grant', $id, ($g['dataset_id'] === null ? [] : ['dataset_id' => (int) $g['dataset_id']]) + ['before' => [
        'target_kind' => $g['target_kind'], 'dataset_id' => $g['dataset_id'] === null ? null : (int) $g['dataset_id'], 'zone' => $g['zone'], 'tag_id' => $g['tag_id'] === null ? null : (int) $g['tag_id'],
        'principal_kind' => $g['principal_kind'], 'principal_id' => $g['principal_id'] === null ? null : (int) $g['principal_id'], 'level' => $g['level'],
        'row_filtered' => $g['row_filter'] !== null], 'after' => ['revoked' => true]]);
    $pdo->commit();
});
$land = $g['dataset_id'] !== null ? '/datasets/' . (int) $g['dataset_id'] . '?tab=access' : '/access/grants';
dl_done('Revoked — it stops at once', $id, dl_land(return_path($land), 'revoked'), 'accessChanged');
