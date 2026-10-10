<?php /** A placeholder screen (render_nav_stub, Phase 2): the page header and one card saying which slice builds it. Data: id, title, icon, slice */ ?>
<?= view('shared/page-header.php', ['id' => $id, 'title' => $title, 'crumbs' => [['Home', '/'], [$title, null]]]) ?>
<div class="main-content" id="<?= e($id) ?>-content">
    <div class="card coming-card" id="<?= e($id) ?>-coming"><div class="card-body">
        <div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="<?= e($icon) ?>"></i></span>
            <div><div class="fw-semibold"><?= e($title) ?> is not built yet</div>
                <div class="fs-12 text-muted"><?= e(ucfirst($slice)) ?> builds this screen. Nothing is lost: what belongs here will appear when it ships.</div></div></div>
    </div></div>
</div>
