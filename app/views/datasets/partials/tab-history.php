<?php
/**
 * History (slice 1): the activity rows about the dataset (record_history — who did what, never a screen view) and its schema versions
 * (schema_history), merged newest first, paged 50 (`?tab=history&history_page=`). Data: d, history {rows, total}, history_page
 */
$id = (int) $d['dataset_id'];
$tz = member_timezone();
$more = $history_page * HISTORY_PAGE < $history['total'];
$page = static fn (int $p): string => '/datasets/' . $id . '?' . http_build_query(['tab' => 'history', 'history_page' => $p]);
?>
<div class="card" id="dataset-history"><div class="card-body p-0">
    <?php if ($history['rows'] === []): ?><div class="p-3 fs-12 text-muted" id="dataset-history-none">Nothing has happened to it yet.</div><?php endif; ?>
    <ul class="list-group list-group-flush" id="dataset-history-list">
        <?php foreach ($history['rows'] as $r): ?>
            <?php if ($r['kind'] === 'schema'): ?>
                <li class="list-group-item" id="history-schema-<?= (int) $r['schema_version_id'] ?>">
                    <div class="fs-13"><i class="feather-git-commit me-1 text-muted"></i><?= e(schema_version_sentence($r)) ?></div>
                    <div class="fs-11 text-muted"><?= e(format_ts($r['at'], $tz)) ?></div>
                </li>
            <?php else: $p = present_history_row($r); ?>
                <li class="list-group-item" id="history-activity-<?= (int) $r['log_id'] ?>">
                    <div class="fs-13 text-break"><?= e($r['sentence']) ?><?= $p['fields'] !== [] ? ' <span class="text-muted">— ' . e(implode(', ', array_map(static fn ($f) => str_replace('_', ' ', (string) $f), array_slice($p['fields'], 0, 8)))) . '</span>' : '' ?></div>
                    <div class="fs-11 text-muted"><?= e(format_ts($r['at'], $tz)) ?> · <?= e($r['source']) ?></div>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
    <?php if ($history_page > 1 || $more): ?>
        <nav class="p-3" id="dataset-history-pagination"><ul class="pagination mb-0">
            <?php if ($history_page > 1): ?><li class="page-item"><?= hx_link($page($history_page - 1), 'Newer', 'page-link', 'id="dataset-history-newer"') ?></li><?php endif; ?>
            <?php if ($more): ?><li class="page-item"><?= hx_link($page($history_page + 1), 'Older', 'page-link', 'id="dataset-history-older"') ?></li><?php endif; ?>
        </ul></nav>
    <?php endif; ?>
</div></div>
