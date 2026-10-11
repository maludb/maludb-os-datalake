<?php
/**
 * A source as a card (id source-card-{id}; RecordCard's look): the connector's icon and name, the state (a paused one says why), the raw dataset it feeds,
 * its schedule, the last pull (status, when, what landed) and the failures in a row. Data: r (a find_sources row), back? (where the link comes back to)
 */
[$stateKey, $stateClass, $stateWord, $why] = source_state($r);
$id = (int) $r['source_id'];
$tz = member_timezone();
$url = isset($back) ? with_back('/sources/' . $id, $back) : '/sources/' . $id;
$failures = (int) $r['consecutive_failures'];
?>
<div class="card source-card mb-2" id="source-card-<?= $id ?>"><div class="card-body py-3">
    <div class="d-flex align-items-start gap-2">
        <span class="avatar-text avatar-md rounded flex-shrink-0" aria-hidden="true"><i class="<?= e(connector_icon((string) $r['connector'])) ?>"></i></span>
        <div class="min-w-0">
            <div class="fw-semibold text-break"><?= hx_link($url, e((string) $r['name']), 'text-dark', 'id="source-card-' . $id . '-link"') ?></div>
            <div class="fs-11 text-muted" id="source-card-<?= $id ?>-connector"><?= e(connector_label((string) $r['connector'])) ?></div>
        </div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-1 mt-2">
        <span class="badge bg-soft-<?= e($stateClass) ?> text-<?= e($stateClass === 'light' ? 'dark' : $stateClass) ?>" id="source-card-<?= $id ?>-state"><?= e($stateWord) ?></span>
        <?php if ($why !== ''): ?><span class="fs-11 text-muted text-break" id="source-card-<?= $id ?>-why"><?= e($why) ?></span><?php endif; ?>
        <?php if ($failures > 0): ?><span class="badge bg-soft-danger text-danger" id="source-card-<?= $id ?>-failures"><?= $failures ?> failure<?= $failures === 1 ? '' : 's' ?> in a row</span><?php endif; ?>
    </div>
    <div class="fs-12 mt-2" id="source-card-<?= $id ?>-target">
        <?php if ($r['target_key'] !== null): ?><i class="feather-database me-1 text-muted"></i><?= hx_link('/datasets/' . $r['target_key'], e((string) $r['target_key']), 'dataset-key') ?>
        <?php else: ?><span class="text-muted"><i class="feather-database me-1"></i>No dataset yet</span><?php endif; ?>
    </div>
    <div class="fs-11 text-muted mt-1" id="source-card-<?= $id ?>-schedule"><i class="feather-clock me-1"></i><?= e(schedule_words((int) $r['schedule_minutes'])) ?></div>
    <div class="fs-11 mt-1" id="source-card-<?= $id ?>-last">
        <?php if ($r['last_pull_id'] === null): ?><span class="text-muted">Not pulled yet</span>
        <?php else: [$bc, $bw] = pull_badge((string) $r['last_status']); ?>
            <span class="badge <?= e($bc) ?>"><?= e($bw) ?></span>
            <span class="text-muted" title="<?= e($r['last_started_at'] === null ? '' : format_ts($r['last_started_at'], $tz)) ?>"><?= e(ago_words($r['last_finished_at'] ?? $r['last_started_at']) ?? '') ?>
                <?php if ($r['last_status'] !== 'running'): ?>· <?= (int) $r['last_items_landed'] ?> of <?= (int) $r['last_items_seen'] ?> landed<?php endif; ?></span>
        <?php endif; ?>
    </div>
</div></div>
