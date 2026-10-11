<?php
/** My last runs (#sql-runs): each a link to its page. Data: runs */
$tz = member_timezone();
?>
<div class="card mt-3" id="sql-runs"><div class="card-header"><h5 class="card-title mb-0">My last runs</h5></div>
<?php if ($runs === []): ?>
    <div class="card-body fs-12 text-muted" id="sql-runs-empty">Nothing run yet.</div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0 fs-12"><thead><tr><th>#</th><th>Status</th><th>Reads</th><th class="text-end">Rows</th><th class="text-end">ms</th><th>When</th><th class="d-none d-md-table-cell">SQL</th></tr></thead><tbody>
    <?php foreach ($runs as $r): [$cls, $w] = run_badge((string) $r['status'], $r['refusal_rule']); ?>
        <tr id="sql-run-<?= (int) $r['query_run_id'] ?>"><td><?= hx_link('/runs/' . (int) $r['query_run_id'], (string) (int) $r['query_run_id']) ?></td>
            <td><span class="badge <?= e($cls) ?>"><?= e($w) ?></span></td><td class="font-monospace"><?= e(implode(', ', pg_text_array((string) $r['datasets']))) ?></td>
            <td class="text-end"><?= (int) $r['rows_returned'] ?><?= $r['truncated'] ? '+' : '' ?></td><td class="text-end"><?= (int) $r['ms'] ?></td>
            <td class="text-nowrap"><?= e(format_ts($r['created_at'], $tz, 'M j, g:i A')) ?></td>
            <td class="d-none d-md-table-cell font-monospace text-truncate" style="max-width: 24rem"><?= e(mb_substr((string) $r['first_line'], 0, 120)) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</div>
