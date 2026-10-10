<?php
/**
 * The dataset page's header (screen `dataset-view`; catalog.md): crumbs (zone › department › key), the title, the status and certification
 * badges (or "Certification lost: {reason}" for 30 days), the health dot with its word, owner and steward, "Data updated" and "Metadata
 * updated" as two lines, rows · size (the engine's when it has the table, else the dataset's own), and the buttons — Query this (slice 3's,
 * greyed until built) · Request access (below read) · Follow / Unfollow · ⋯ (Edit · Deprecate · Retire · Tag), a <details> menu that works
 * without JavaScript. Data: d (find_dataset), may, follow, here
 */
$id = (int) $d['dataset_id'];
$cert = certification_badge($d['certification']);
$status = dataset_status_badge((string) $d['status']);
[$dot, $dotWord] = health_dot((string) ($d['health']['health'] ?? 'green'));
$tz = member_timezone();
$rows = $d['engine']['record_count'] ?? ($d['row_count'] === null ? null : (int) $d['row_count']);
$size = $d['engine']['file_size_bytes'] ?? ($d['byte_size'] === null ? null : (int) $d['byte_size']);
$crumbs = [['Browse', '/browse'], [$d['zone'], '/browse?zone=' . rawurlencode((string) $d['zone'])]];
if ($d['department_id'] !== null) {
    $crumbs[] = [(string) ($d['department_name'] ?? 'Department'), '/browse?department=' . (int) $d['department_id']];
}
$crumbs[] = [$d['key'], null];
$back = back_link();
$request = '/access/requests/new?' . http_build_query(['dataset' => $id, 'level' => 'read']);
$queryBuilt = nav_built('/sql/index.php');
$more = $may['describe'] || $may['deprecate'] || $may['retire'];
?>
<?= view('shared/page-header.php', ['id' => 'dataset', 'title' => $d['title'], 'crumbs' => $crumbs, 'back' => $back]) ?>
<div class="main-content pt-0 pb-2" id="dataset-header" data-dataset-key="<?= e($d['key']) ?>">
    <div class="dataset-key mb-1" id="dataset-header-key"><?= e($d['key']) ?></div>
    <div class="d-flex flex-wrap align-items-center gap-1 mb-2" id="dataset-header-badges">
        <span class="badge text-bg-<?= e(zone_color((string) $d['zone'])) ?> zone-chip"><?= e($d['zone']) ?></span>
        <?php if ($status !== null): ?><span class="badge <?= e($status[0]) ?>" id="dataset-header-status"><?= e($status[1]) ?></span><?php endif; ?>
        <?php if ($cert !== null): ?><span class="badge <?= e($cert[0]) ?>" id="dataset-header-certification"><?= e($cert[1]) ?></span>
        <?php elseif (certification_lost_recent($d)): ?><span class="badge badge-outline-danger border border-danger text-danger" id="dataset-header-certification-lost" title="<?= e(format_ts($d['certification_lost_at'], $tz)) ?>">Certification lost: <?= e(mb_substr((string) $d['certification_lost_reason'], 0, 120)) ?></span><?php endif; ?>
        <span class="badge <?= e(classification_class((string) $d['classification'])) ?>" id="dataset-header-classification"><?= e($d['classification']) ?></span>
        <?php if ($d['has_personal_data']): ?><span class="badge text-bg-danger" id="dataset-header-personal">Personal data</span><?php endif; ?>
        <span class="ms-1 fs-12 text-nowrap" id="dataset-header-health"><span class="health-dot <?= e($dot) ?>" aria-hidden="true"></span> <?= e($dotWord) ?></span>
    </div>
    <div class="row g-2 fs-12 text-muted mb-2">
        <div class="col-12 col-md-6">
            <div id="dataset-header-owner"><i class="feather-user me-1"></i>Owner:
                <?= $d['owner_member_id'] === null ? '<span>nobody yet</span>' : hx_link(with_back('/browse?owner=' . (int) $d['owner_member_id'], $here), e((string) ($d['owner_name'] ?? 'someone who has left')), 'fw-semibold', 'id="dataset-header-owner-link"') ?></div>
            <div id="dataset-header-steward"><i class="feather-feather me-1"></i>Steward:
                <?= $d['steward_member_id'] === null ? '<span>none</span>' : hx_link(with_back('/browse?owner=' . (int) $d['steward_member_id'], $here), e((string) ($d['steward_name'] ?? 'someone who has left')), 'fw-semibold', 'id="dataset-header-steward-link"') ?></div>
        </div>
        <div class="col-12 col-md-6">
            <div id="dataset-header-data-updated" title="<?= e($d['data_updated_at'] === null ? '' : format_ts($d['data_updated_at'], $tz)) ?>"><i class="feather-database me-1"></i>Data updated <?= e(ago_words($d['data_updated_at']) ?? 'never — nothing loaded yet') ?></div>
            <div id="dataset-header-metadata-updated" title="<?= e(format_ts($d['metadata_updated_at'], $tz)) ?>"><i class="feather-edit-3 me-1"></i>Metadata updated <?= e(ago_words($d['metadata_updated_at']) ?? '—') ?></div>
            <div id="dataset-header-size"><i class="feather-layers me-1"></i><?= $rows === null ? 'No rows yet' : e(count_words($rows)) . ' rows' ?><?= $size === null ? '' : ' · ' . e(bytes_words($size)) ?><?= $d['engine'] === null ? '' : ' · ' . (int) $d['engine']['data_files'] . ' file' . ((int) $d['engine']['data_files'] === 1 ? '' : 's') ?></div>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2" id="dataset-header-actions">
        <?php if ($queryBuilt): ?>
            <?= hx_link('/sql?' . http_build_query(['dataset' => $d['key']]), '<i class="feather-terminal me-1"></i>Query this', 'btn btn-primary btn-touch', 'id="dataset-query-btn"') ?>
        <?php else: ?>
            <span class="btn btn-light btn-touch disabled" id="dataset-query-btn" aria-disabled="true" title="The SQL editor is built by slice 3"><i class="feather-terminal me-1"></i>Query this</span>
        <?php endif; ?>
        <?php if ($may['request']): ?>
            <a href="<?= e($request) ?>" class="btn btn-light-brand btn-touch" id="dataset-request-btn"><i class="feather-unlock me-1"></i>Request access</a>
        <?php endif; ?>
        <?php if ($may['follow']): ?>
            <form method="post" action="/datasets/<?= $follow === null ? 'follow' : 'unfollow' ?>.php" hx-post="/datasets/<?= $follow === null ? 'follow' : 'unfollow' ?>.php" hx-target="#flash" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <button type="submit" class="btn btn-light btn-touch" id="follow-btn"><i class="feather-<?= $follow === null ? 'bell' : 'bell-off' ?> me-1"></i><?= $follow === null ? 'Follow' : 'Unfollow' ?></button>
            </form>
        <?php endif; ?>
        <?php if ($more): ?>
            <details class="dataset-more" id="dataset-more">
                <summary class="btn btn-light btn-touch" id="dataset-more-btn" aria-label="More actions"><i class="feather-more-horizontal"></i></summary>
                <div class="dataset-more-menu" id="dataset-more-menu">
                    <?php if ($may['describe']): ?>
                        <?= hx_link('/datasets/' . $id . '/edit', '<i class="feather-edit-2 me-2"></i>Edit', 'dropdown-item', 'id="dataset-edit-link"') ?>
                        <a class="dropdown-item" href="/datasets/<?= $id ?>?tab=about#dataset-tags" id="dataset-tag-link"><i class="feather-tag me-2"></i>Tag</a>
                    <?php endif; ?>
                    <?php if ($may['deprecate'] && $d['status'] !== 'deprecated'): ?>
                        <?= hx_link('/datasets/' . $id . '/edit#dataset-deprecate', '<i class="feather-archive me-2"></i>Deprecate', 'dropdown-item', 'id="dataset-deprecate-link"') ?>
                    <?php endif; ?>
                    <?php if ($may['retire']): ?>
                        <form method="post" action="/datasets/retire.php" hx-post="/datasets/retire.php" hx-target="#flash"
                              hx-confirm="Retire <?= e($d['key']) ?>? It leaves the catalog for everyone and its key is never used again.">
                            <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>">
                            <button type="submit" class="dropdown-item text-danger" id="dataset-retire-btn"><i class="feather-trash-2 me-2"></i>Retire</button>
                        </form>
                    <?php endif; ?>
                </div>
            </details>
        <?php endif; ?>
    </div>
</div>
