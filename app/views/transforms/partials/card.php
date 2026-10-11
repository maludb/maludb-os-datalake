<?php
/**
 * A transform as a card (id transform-card-{id}; RecordCard's look): the name, the state, the dataset it makes (a link), the datasets it reads (keys; "n hidden" for the ones
 * the viewer may not discover), the mode and the schedule, the last run (status, when, rows). Data: r (a find_transforms row), back? (where the link comes back to)
 */
[$stateKey, $stateClass, $stateWord] = transform_state($r);
$id = (int) $r['transform_id'];
$tz = member_timezone();
$url = isset($back) ? with_back('/transforms/' . $id, $back) : '/transforms/' . $id;
?>
<div class="card transform-card mb-2" id="transform-card-<?= $id ?>"><div class="card-body py-3">
    <div class="d-flex align-items-start gap-2">
        <span class="avatar-text avatar-md rounded flex-shrink-0" aria-hidden="true"><i class="feather-shuffle"></i></span>
        <div class="min-w-0">
            <div class="fw-semibold text-break"><?= hx_link($url, e((string) $r['name']), 'text-dark', 'id="transform-card-' . $id . '-link"') ?></div>
            <div class="fs-11 text-muted">version <?= (int) $r['version'] ?><?= $r['owner_name'] !== null ? ' · ' . e((string) $r['owner_name']) : '' ?></div>
        </div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-1 mt-2">
        <span class="badge bg-soft-<?= e($stateClass) ?> text-<?= e($stateClass) ?>" id="transform-card-<?= $id ?>-state"><?= e($stateWord) ?></span>
        <span class="badge text-bg-<?= e(zone_color((string) $r['output_zone'])) ?> zone-chip"><?= e((string) $r['output_zone']) ?></span>
        <span class="badge bg-soft-secondary text-secondary"><?= $r['mode'] === 'incremental_merge' ? 'Incremental' : 'Replace' ?></span>
    </div>
    <div class="fs-12 mt-2" id="transform-card-<?= $id ?>-output"><i class="feather-arrow-right-circle me-1 text-muted"></i>Makes
        <?= hx_link('/datasets/' . $r['output_key'], e((string) $r['output_key']), 'dataset-key') ?></div>
    <div class="fs-11 text-muted mt-1 text-break" id="transform-card-<?= $id ?>-inputs"><i class="feather-corner-down-right me-1"></i>From
        <?php if ($r['inputs'] === [] && (int) $r['input_hidden'] === 0): ?>nothing yet<?php else: ?>
            <?= implode(', ', array_map(static fn (array $i): string => '<span class="dataset-key">' . e((string) $i['key']) . '</span>', $r['inputs'])) ?>
            <?= (int) $r['input_hidden'] > 0 ? ($r['inputs'] === [] ? '' : ' and ') . '<span class="badge bg-soft-secondary text-secondary">' . (int) $r['input_hidden'] . ' hidden</span>' : '' ?>
        <?php endif; ?></div>
    <div class="fs-11 text-muted mt-1" id="transform-card-<?= $id ?>-schedule"><i class="feather-clock me-1"></i><?= e(cron_words($r['schedule'])) ?></div>
    <div class="fs-11 mt-1" id="transform-card-<?= $id ?>-last">
        <?php if ($r['last_run_id'] === null): ?><span class="text-muted">Not run yet</span>
        <?php else: [$bc, $bw] = transform_run_badge((string) $r['last_status']); ?>
            <span class="badge <?= e($bc) ?>"><?= e($bw) ?></span>
            <span class="text-muted" title="<?= e($r['last_started_at'] === null ? '' : format_ts($r['last_started_at'], $tz)) ?>"><?= e(ago_words($r['last_finished_at'] ?? $r['last_started_at']) ?? '') ?>
                <?php if ($r['last_status'] === 'ok'): ?>· <?= e(count_words((int) $r['last_rows'])) ?> rows<?php endif; ?></span>
        <?php endif; ?>
    </div>
</div></div>
