<?php
/** My exports (screen `exports`): a ledger table — format, what, rows, truncated, masked, status, made, expires; Download and Delete (export-row-{id}). Data: rows, notice */
$tz = member_timezone();
?>
<?= view('shared/page-header.php', ['id' => 'exports', 'title' => 'My exports', 'crumbs' => [['Home', '/'], ['My exports', null]]]) ?>
<div class="main-content" id="exports-content">
    <?php if ($notice !== null): ?><?= view('shared/notice.php', ['notice' => $notice]) ?><?php endif; ?>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="exports-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-download"></i></span>
            <div><div class="fw-semibold">No exports</div><div class="fs-12 text-muted">Download a result from the SQL editor or a saved query — the file is made here.</div></div></div></div></div>
    <?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="exports-table">
            <thead><tr><th>#</th><th>Format</th><th>What</th><th class="text-end">Rows</th><th>Status</th><th>Made</th><th>Expires</th><th></th></tr></thead><tbody>
            <?php foreach ($rows as $x): [$cls, $w] = export_badge($x); $id = (int) $x['export_id']; $ready = $x['status'] === 'done' && empty($x['expired']); ?>
                <tr id="export-row-<?= $id ?>">
                    <td><?= $id ?></td><td><?= e(EXPORT_FORMATS[$x['format']][1] ?? strtoupper((string) $x['format'])) ?></td>
                    <td><?= $x['dataset_key'] === null ? 'a result' : ($x['kind'] === 'dataset' ? '' : 'a result of ') . hx_link('/datasets/' . $x['dataset_key'], e((string) $x['dataset_key']), 'font-monospace') ?></td>
                    <td class="text-end"><?= $x['row_count'] === null ? '—' : number_format((int) $x['row_count']) ?><?= $x['truncated'] ? ' <span class="badge bg-soft-warning text-warning">cut at the limit</span>' : '' ?><?= $x['masked'] ? ' <span class="badge bg-soft-warning text-warning">masked</span>' : '' ?></td>
                    <td><span class="badge <?= e($cls) ?>"><?= e($w) ?></span></td>
                    <td class="text-nowrap"><?= e(format_ts($x['created_at'], $tz, 'M j, g:i A')) ?></td><td class="text-nowrap"><?= e(format_ts($x['expires_at'], $tz, 'M j')) ?></td>
                    <td class="text-nowrap text-end">
                        <?php if ($ready): ?><a class="btn btn-primary btn-sm" href="/exports/<?= $id ?>/download" id="export-download-<?= $id ?>"><i class="feather-download"></i> <?= e(bytes_words($x['byte_size'] === null ? null : (int) $x['byte_size']) ?? '') ?></a><?php endif; ?>
                        <form method="post" action="/exports/delete.php" class="d-inline" hx-confirm="Delete this export?"><?= csrf_field() ?><input type="hidden" name="export" value="<?= $id ?>">
                            <button type="submit" class="btn btn-light btn-sm text-danger" id="export-delete-<?= $id ?>" aria-label="Delete"><i class="feather-trash-2"></i></button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div></div>
    <?php endif; ?>
</div>
