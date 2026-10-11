<?php
/** My sandbox (screen `sandbox`): the quota bar (#quota-bar) and my datasets as cards (sandbox-card-{id}). Data: s, me, notice */
$pct = $s['quota_bytes'] > 0 ? min(100, (int) round($s['used_bytes'] * 100 / $s['quota_bytes'])) : 0;
$here = '/sandbox';
?>
<?= view('shared/page-header.php', ['id' => 'sandbox', 'title' => 'My sandbox', 'crumbs' => [['Home', '/'], ['My sandbox', null]],
    'action' => hx_link('/upload', '<i class="feather-upload-cloud me-1"></i>Upload', 'btn btn-primary btn-touch', 'id="sandbox-upload-btn"')]) ?>
<div class="main-content" id="sandbox-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between fs-12 mb-1"><span class="fw-semibold">Space used</span>
            <span id="quota-words"><?= e(bytes_words($s['used_bytes']) ?? '0 B') ?> of <?= e(bytes_words($s['quota_bytes']) ?? '0 B') ?></span></div>
        <div class="progress quota-bar" id="quota-bar" role="progressbar" aria-label="Sandbox space used" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar<?= $pct >= 90 ? ' bg-danger' : ($pct >= 70 ? ' bg-warning' : '') ?>" style="width: <?= $pct ?>%"></div></div>
        <div class="form-text">Yours alone — nobody else sees these datasets unless you share one.</div>
    </div></div>
    <?php if ($s['datasets'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="sandbox-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-box"></i></span>
            <div><div class="fw-semibold">Your sandbox is empty</div><div class="fs-12 text-muted">Upload a spreadsheet: it becomes a dataset here that you can preview and query.</div>
                <?= hx_link('/upload', 'Upload a spreadsheet', 'btn btn-primary btn-touch mt-2', 'id="sandbox-empty-upload"') ?></div></div></div></div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2" id="sandbox-cards">
            <?php foreach ($s['datasets'] as $d): $id = (int) $d['dataset_id'];
                $extra = '<div class="d-flex flex-wrap gap-2 mt-1 fs-11 text-muted">'
                    . '<span>' . ($d['row_count'] === null ? 'no rows yet' : e(count_words((int) $d['row_count'])) . ' rows') . '</span>'
                    . ($d['byte_size'] !== null ? '<span>' . e(bytes_words((int) $d['byte_size'])) . '</span>' : '')
                    . '<span>data ' . e(ago_words($d['data_updated_at']) ?? 'never loaded') . '</span>'
                    . '<span>' . ($d['from_upload'] ? 'made from an upload' : 'saved from a query') . '</span>'
                    . ($d['has_personal_data'] ? '<span class="badge bg-soft-warning text-warning">personal data</span>' : '')
                    . '</div><div class="d-flex gap-2 mt-2">'
                    . hx_link('/datasets/' . $d['key'] . '?tab=preview', 'Preview', 'btn btn-sm btn-light btn-touch', 'id="sandbox-card-' . $id . '-preview"')
                    . ($d['last_load_id'] !== null ? hx_link('/loads/' . (int) $d['last_load_id'], 'Last load', 'btn btn-sm btn-light btn-touch', 'id="sandbox-card-' . $id . '-load"') : '')
                    . '<form method="post" action="/datasets/retire.php" hx-post="/datasets/retire.php" hx-target="#flash" hx-confirm="Retire ' . e($d['key']) . '? Its name is never used again." class="d-inline">'
                    . csrf_field() . '<input type="hidden" name="dataset" value="' . $id . '"><input type="hidden" name="return_to" value="/sandbox">'
                    . '<button type="submit" class="btn btn-sm btn-outline-danger btn-touch" id="sandbox-card-' . $id . '-retire">Retire</button></form></div>'; ?>
                <div class="col">
                    <?= view('shared/record-card.php', ['r' => ['id' => 'sandbox-card-' . $id, 'title' => $d['title'], 'key' => $d['key'], 'zone' => 'sandbox',
                        'certification' => 'none', 'owner_name' => null, 'url' => '/datasets/' . $d['key'], 'back' => $here, 'extra' => $extra, 'usage_30d' => null]]) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
