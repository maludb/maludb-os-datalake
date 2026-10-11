<?php
/** The schema the load saw and its drift (#load-drift): added / rescued into _dl_rescued / missing; a pending schema version links the dataset's History. Data: l, pending */
$seen = $l['schema_seen'];
$words = drift_words($seen['drift'] ?? null);
?>
<div class="card mb-3" id="load-drift"><div class="card-body">
    <h6 class="fs-12 text-uppercase text-muted">The schema it saw</h6>
    <?php if ($seen === null): ?><div class="fs-12 text-muted">Not loaded yet.</div><?php else: ?>
        <div class="d-flex flex-wrap gap-1 mb-2" id="load-drift-columns">
            <?php foreach ((array) ($seen['columns'] ?? []) as $c): if (str_starts_with((string) $c['name'], '_dl_')) { continue; } ?>
                <span class="badge bg-light text-dark border fw-normal"><span class="font-monospace"><?= e((string) $c['name']) ?></span> <span class="text-muted"><?= e(strtolower((string) $c['type'])) ?></span></span>
            <?php endforeach; ?>
        </div>
        <?php if ($words === []): ?><div class="fs-12 text-success" id="load-drift-none">No drift: the file had the columns the dataset has.</div>
        <?php else: foreach ($words as $i => $w): ?><div class="fs-12 text-warning" id="load-drift-<?= $i ?>"><?= e($w) ?></div><?php endforeach; endif; ?>
        <?php if ($pending !== []): ?>
            <div class="mt-2 fs-12" id="load-drift-pending">A schema version waits for a decision — <?= hx_link('/datasets/' . $l['dataset_key'] . '?tab=history', 'see the dataset\'s History', 'fw-semibold') ?>. Nothing is applied silently.</div>
        <?php endif; ?>
    <?php endif; ?>
</div></div>
