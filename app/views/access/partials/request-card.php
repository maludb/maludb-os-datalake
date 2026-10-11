<?php
/**
 * One access request as a card (request-card-{id}): who, the dataset, the level asked, the reason, wanted until, "drafted by {agent}", the
 * requester's current level, the status; Withdraw on my own waiting request; on To decide the inline decision (decide-inline.php). Data: r, box, may_decide
 */
$id = (int) $r['request_id'];
$sb = request_status_badge((string) $r['status']);
$lb = level_badge((string) $r['level']);
$cur = level_badge((string) ($r['requester_level'] ?? 'none'));
$me = (int) current_member_id();
$link = $r['is_stub'] ? '/datasets/' . (int) $r['dataset_id'] : '/datasets/' . (int) $r['dataset_id'] . '?tab=access';
?>
<div class="card h-100 request-card" id="request-card-<?= $id ?>"><div class="card-body py-3">
    <div class="d-flex justify-content-between gap-2">
        <div class="fw-semibold text-break"><?= hx_link($link, e((string) $r['title']), 'text-dark') ?></div>
        <span class="badge <?= e($sb[0]) ?> align-self-start" id="request-card-<?= $id ?>-status"><?= e($sb[1]) ?></span>
    </div>
    <div class="fs-12 font-monospace text-muted text-break"><?= e((string) $r['key']) ?></div>
    <div class="fs-12 mt-1"><i class="feather-user me-1"></i><?= (int) $r['requester_id'] === $me ? 'You' : e((string) ($r['requester_name'] ?? 'Someone')) ?> ask<?= (int) $r['requester_id'] === $me ? '' : 's' ?> to
        <span class="badge <?= e($lb[0]) ?>"><?= e($lb[1]) ?></span>
        <?php if ($r['wanted_until'] !== null): ?> until <?= e(format_date((string) $r['wanted_until'])) ?><?php endif; ?>
    </div>
    <div class="fs-12 text-muted mt-1 text-break" id="request-card-<?= $id ?>-reason">“<?= e(mb_substr((string) $r['reason'], 0, 400)) ?>”</div>
    <div class="d-flex flex-wrap gap-1 mt-2">
        <?php if ($r['drafted_by_agent'] !== null): ?><span class="badge bg-soft-info text-info" id="request-card-<?= $id ?>-drafted"><i class="feather-cpu me-1"></i>Drafted by <?= e((string) ($r['drafted_by_name'] ?? 'an agent')) ?></span><?php endif; ?>
        <span class="badge border text-muted bg-transparent">Has now: <?= e($cur[1]) ?></span>
    </div>
    <?php if ($r['status'] !== 'pending' && $r['decided_at'] !== null): ?>
        <div class="fs-11 text-muted mt-2"><?= e($sb[1]) ?> by <?= e((string) ($r['decided_by_name'] ?? '—')) ?> · <?= e(format_ts((string) $r['decided_at'], member_timezone())) ?>
            <?php if (($r['decision_note'] ?? '') !== ''): ?> — <?= e(mb_substr((string) $r['decision_note'], 0, 300)) ?><?php endif; ?></div>
    <?php else: ?>
        <div class="fs-11 text-muted mt-2">Asked <?= e(format_ts((string) $r['created_at'], member_timezone())) ?></div>
    <?php endif; ?>
    <?php if ($r['status'] === 'pending' && ((int) $r['requester_id'] === $me || (int) ($r['drafted_by_agent'] ?? 0) === $me)): ?>
        <form method="post" action="/access/requests/withdraw.php" hx-post="/access/requests/withdraw.php" hx-target="#flash" hx-confirm="Withdraw this request?" class="mt-2" id="request-<?= $id ?>-withdraw-form">
            <?= csrf_field() ?><input type="hidden" name="access_request" value="<?= $id ?>"><input type="hidden" name="return_to" value="/access/requests?box=mine">
            <button type="submit" class="btn btn-light btn-sm btn-touch" id="request-<?= $id ?>-withdraw">Withdraw</button>
        </form>
    <?php endif; ?>
    <?php if (!empty($may_decide)): ?><?= view('access/partials/decide-inline.php', ['r' => $r]) ?><?php endif; ?>
</div></div>
