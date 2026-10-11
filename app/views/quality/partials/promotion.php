<?php
/** The latest promotion of a curated dataset (the Quality tab; the polling door /quality/promote.php?dataset=): every 2 s while queued or running. Data: p, dataset */
$live = $p !== null && in_array($p['status'], ['queued', 'running'], true);
$cls = $p === null ? 'text-muted' : ($p['status'] === 'failed' ? 'text-danger' : ($live ? 'text-info' : 'text-body'));
?>
<div id="quality-promotion" class="fs-12 <?= e($cls) ?>"<?= $live ? ' hx-get="/quality/promote.php?dataset=' . (int) $dataset . '&was_running=1" hx-trigger="every 2s" hx-target="this" hx-swap="outerHTML"' : '' ?>>
    <?= $p === null ? 'Never promoted.' : e((string) promotion_words($p)) ?>
    <?php if ($p !== null && $p['finished_at'] !== null): ?><span class="text-muted"> · <?= e(format_ts($p['finished_at'], member_timezone(), 'M j, g:i A')) ?></span><?php endif; ?>
</div>
