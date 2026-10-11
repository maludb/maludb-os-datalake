<?php
/** The rejected lines (#load-rejects): line, column, error type, message — the raw text only at the dataset's write level; paged 50. Data: l, rejects, total, page, raw */
$id = (int) $l['load_id'];
$more = $page * LOAD_PAGE < $total;
?>
<div class="card mb-3" id="load-rejects"><div class="card-body">
    <h6 class="fs-12 text-uppercase text-muted">Rejected lines and cells <span class="fw-normal">(<?= (int) $total ?>)</span></h6>
    <?php if ($total === 0): ?><div class="fs-12 text-muted" id="load-rejects-none">Nothing was refused.</div><?php else: ?>
        <?php if (!$raw): ?><div class="fs-11 text-muted mb-2">The refused text is shown to those who may change the dataset — it can hold personal data.</div><?php endif; ?>
        <div class="table-responsive"><table class="table table-sm fs-12 mb-0" id="load-rejects-table">
            <thead><tr><th>Line</th><th>Column</th><th>Error</th><th>Message</th><?php if ($raw): ?><th>The text</th><?php endif; ?></tr></thead>
            <tbody><?php foreach ($rejects as $r): ?>
                <tr id="load-reject-<?= (int) $r['reject_id'] ?>"><td><?= $r['line'] === null ? '—' : (int) $r['line'] ?></td><td class="font-monospace"><?= e((string) ($r['column_name'] ?? '—')) ?></td>
                    <td><span class="badge <?= $r['error_type'] === 'CAST' ? 'bg-soft-warning text-warning' : 'bg-soft-danger text-danger' ?>"><?= e((string) $r['error_type']) ?></span></td>
                    <td><?= e((string) ($r['message'] ?? '')) ?></td>
                    <?php if ($raw): ?><td class="font-monospace text-break" style="max-width: 24rem;"><?= e(mb_substr((string) ($r['raw_line'] ?? ''), 0, 400)) ?></td><?php endif; ?></tr>
            <?php endforeach; ?></tbody></table></div>
        <?php if ($page > 1 || $more): ?>
            <nav class="mt-2"><ul class="pagination pagination-sm mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link('/loads/' . $id . '?rejects_page=' . ($page - 1), 'Previous', 'page-link', 'id="load-rejects-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link('/loads/' . $id . '?rejects_page=' . ($page + 1), 'Next', 'page-link', 'id="load-rejects-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div></div>
