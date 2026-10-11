<?php
/** The Feeds tab (#source-feeds): the datasets this source feeds, from the lineage (and the target it declares, before a first pull makes its edge). Data: s, feeds */
$tz = member_timezone();
?>
<div id="source-feeds">
<?php if ($feeds === []): ?>
    <div class="card"><div class="card-body"><div class="empty-state" id="source-feeds-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-database"></i></span>
        <div><div class="fw-semibold">Feeds no dataset you may see</div><div class="fs-12 text-muted">Its first good pull makes the link in the lineage.</div></div></div></div></div>
<?php else: ?>
    <div class="row row-cols-1 row-cols-md-2 g-2">
    <?php foreach ($feeds as $d): ?>
        <div class="col"><div class="card dataset-card mb-2" id="source-feed-<?= (int) $d['dataset_id'] ?>"><div class="card-body py-3">
            <div class="fw-semibold text-break"><?= hx_link('/datasets/' . $d['key'], e((string) $d['title']), 'text-dark') ?></div>
            <div class="dataset-key"><?= e($d['key']) ?></div>
            <div class="d-flex flex-wrap gap-1 mt-1"><span class="badge text-bg-<?= e(zone_color((string) $d['zone'])) ?> zone-chip"><?= e($d['zone']) ?></span></div>
            <div class="fs-11 text-muted mt-1"><?= $d['row_count'] === null ? 'no rows yet' : e(count_words((int) $d['row_count'])) . ' rows' ?>
                · data <?= e(ago_words($d['data_updated_at']) ?? 'never loaded') ?><?= $d['has_edge'] ? '' : ' · declared, not pulled yet' ?></div>
        </div></div></div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
