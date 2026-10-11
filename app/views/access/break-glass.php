<?php
/**
 * The admin's break-glass (screen `admin-read`): the dataset (the picker of every dataset — the admin discovers all), the reason (10–2,000),
 * "the owner is told; rows open for one hour; every query is audited"; my open windows with their end. People only. Data: v, open, notice, owner_name
 */
?>
<?= view('shared/page-header.php', ['id' => 'admin-read', 'title' => 'Break-glass', 'crumbs' => [['Home', '/'], ['Access', '/access'], ['Break-glass', null]]]) ?>
<div class="main-content" id="admin-read-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('access/partials/subnav.php', ['active' => 'admin-read']) ?>
    <?php if ($open !== []): ?>
        <div class="row g-2 mb-3" id="admin-read-open">
            <?php foreach ($open as $w): ?>
                <div class="col-12 col-md-6"><div class="card border-danger" id="admin-read-window-<?= (int) $w['admin_read_id'] ?>"><div class="card-body py-3">
                    <div class="d-flex justify-content-between gap-2"><div class="fw-semibold text-break"><?= hx_link('/datasets/' . (int) $w['dataset_id'] . '?tab=preview', e((string) $w['title']), 'text-dark') ?></div>
                        <span class="badge bg-danger align-self-start">Break-glass until <?= e(format_ts((string) $w['expires_at'], member_timezone(), 'g:i A')) ?></span></div>
                    <div class="fs-12 font-monospace text-muted"><?= e((string) $w['key']) ?></div>
                    <div class="fs-11 text-muted mt-1">Opened <?= e(format_ts((string) $w['started_at'], member_timezone())) ?> · nothing ends it early but its clock</div>
                </div></div></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post" action="/access/break-glass.php" hx-post="/access/break-glass.php" hx-target="#flash" id="breakglass-form" class="page-header-form">
        <?= csrf_field() ?>
        <div class="card mb-3 border-danger"><div class="card-body"><div class="row g-3">
            <div class="col-12"><div class="alert alert-danger mb-0" id="breakglass-warning"><i class="feather-alert-triangle me-1"></i>The dataset's owner<?= $owner_name !== null ? ' ' . e($owner_name) : '' ?> and every other Lake admin are told;
                its rows open to you for <strong>one hour</strong>, unmasked; every query is audited.</div></div>
            <div class="col-12"><label class="form-label" id="breakglass-dataset-label" for="breakglass-field-dataset">Dataset</label>
                <?= picker_field(['id' => 'breakglass-field-dataset', 'name' => 'dataset', 'source' => 'any_dataset', 'value' => $v['dataset'], 'labelledby' => 'breakglass-dataset-label', 'title' => 'The dataset', 'required' => true]) ?></div>
            <div class="col-12"><label class="form-label" for="breakglass-field-reason">Why</label>
                <textarea class="form-control" id="breakglass-field-reason" name="reason" rows="3" minlength="10" maxlength="2000" required placeholder="The payroll export failed and the auditors need the March rows today."><?= e((string) $v['reason']) ?></textarea>
                <div class="form-text">10 to 2,000 characters — the owner reads it.</div></div>
        </div></div></div>
        <div class="d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-danger btn-touch flex-grow-1" id="breakglass-open-btn" hx-confirm="Open this dataset's rows for one hour? The owner is told.">Open for one hour</button>
        </div>
    </form>
</div>
