<?php
/**
 * A run that did not answer rows (#sql-refusal): the service's sentence (query-service §4 — never re-worded here), the rule as a badge, the
 * dataset it names, Request access for the policy's rules; the engine away, a timeout, a cancel each in their words; the run's link (a
 * refusal is audited too). Data: r (run_sql()), sql, as_of
 */
$f = $r['refusal'] ?? ['rule' => 'engine', 'message' => 'The lake could not answer that.', 'dataset' => null, 'request_access' => null, 'retry' => false];
[$cls, $words] = run_badge((string) $r['status'], $f['rule']);
$icon = match ($r['status']) { 'timeout' => 'feather-clock', 'cancelled' => 'feather-x-octagon', 'error' => 'feather-alert-triangle', default => 'feather-lock' };
?>
<div class="card" id="sql-refusal"><div class="card-body">
    <div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="<?= e($icon) ?>"></i></span>
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2"><span class="badge <?= e($cls) ?>" id="sql-refusal-rule" data-rule="<?= e((string) $f['rule']) ?>"><?= e($words) ?></span>
                <?php if (($f['dataset'] ?? null) !== null && $f['rule'] !== 'no_such_dataset'): ?><span class="font-monospace fs-12" id="sql-refusal-dataset"><?= e((string) $f['dataset']) ?></span><?php endif; ?></div>
            <div class="fs-12 mt-1" id="sql-refusal-message"><?= e(ucfirst((string) $f['message'])) ?></div>
            <?php if (($f['request_access'] ?? null) !== null && has_right('access.request')): ?>
                <a class="btn btn-primary btn-touch btn-sm mt-2" href="<?= e((string) $f['request_access']) ?>" id="sql-request-access">Request access</a>
            <?php endif; ?>
            <?php if (!empty($f['retry'])): ?><div class="fs-12 text-muted mt-1">Try again in a moment.</div><?php endif; ?>
            <?php if (($r['query_run_id'] ?? null) !== null): ?><div class="fs-11 text-muted mt-1"><?= hx_link('/runs/' . (int) $r['query_run_id'], 'run #' . (int) $r['query_run_id'], '', 'id="sql-run-link"') ?></div><?php endif; ?>
        </div></div>
</div></div>
