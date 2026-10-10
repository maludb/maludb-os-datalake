<?php
/**
 * A dataset as a card (RecordCard's look — the owner's preference for named things): the title as a link carrying ?back=, the key in mono,
 * the zone chip, the certification badge, the owner, the usage, the excerpt with the words matched. Data: r = [title, key, zone,
 * certification, owner_name?, usage_30d?, excerpt_html?, url, id (the element's id), back?, extra? (html under the title)]
 */
$cert = certification_badge($r['certification'] ?? null);
$url = isset($r['back']) ? with_back($r['url'], $r['back']) : $r['url'];
?>
<div class="card dataset-card mb-2" id="<?= e($r['id']) ?>"><div class="card-body py-3">
    <div class="fw-semibold text-break"><?= hx_link($url, e((string) $r['title']), 'text-dark') ?></div>
    <?php if (($r['key'] ?? null) !== null): ?><div class="dataset-key"><?= e($r['key']) ?></div><?php endif; ?>
    <?php if (($r['zone'] ?? null) !== null || $cert !== null): ?>
    <div class="d-flex flex-wrap gap-1 mt-1">
        <?php if (($r['zone'] ?? null) !== null): ?><span class="badge text-bg-<?= e(zone_color((string) $r['zone'])) ?> zone-chip"><?= e($r['zone']) ?></span><?php endif; ?>
        <?php if ($cert !== null): ?><span class="badge <?= e($cert[0]) ?>"><?= e($cert[1]) ?></span><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (($r['excerpt_html'] ?? '') !== ''): ?><div class="fs-12 text-muted text-break mt-1"><?= $r['excerpt_html'] ?></div><?php endif; ?>
    <?= $r['extra'] ?? '' ?>
    <div class="fs-11 text-muted mt-1">
        <?php if (($r['owner_name'] ?? null) !== null): ?><span><i class="feather-user me-1"></i><?= e($r['owner_name']) ?></span><?php endif; ?>
        <?php if (isset($r['usage_30d'])): ?><span class="ms-2"><i class="feather-activity me-1"></i><?= e(count_words($r['usage_30d'])) ?> queries in 30 days</span><?php endif; ?>
    </div>
</div></div>
