<?php
/**
 * The first-run home: the person may discover no dataset — ONE button, Upload a spreadsheet (design §9; sso-shell.md), a plain link to
 * /upload until slice 2 ships it; an engineer also gets a quiet link to connect a source (slice 6). Someone who may not upload is told the
 * lake has nothing for them yet. Data: s
 */
$may = $s['may'];
?>
<div class="card" id="home-first-run"><div class="card-body">
    <div class="empty-state text-center py-4" id="home-empty">
        <span class="avatar-text avatar-lg rounded mb-3"><i class="feather-database"></i></span>
        <div class="fw-semibold mb-1">Your data, findable and trustworthy</div>
        <div class="fs-12 text-muted mb-3">Bring a spreadsheet and it becomes a dataset you can search, query and share — described, checked and kept safe. Nothing here is visible to you yet.</div>
        <?php if ($may['upload']): ?>
            <a href="/upload" class="btn btn-primary btn-touch" id="home-first-run-btn"<?= nav_built('/upload/index.php') ? ' hx-get="/upload" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="/upload"' : '' ?>><i class="feather-upload-cloud me-1"></i>Upload a spreadsheet</a>
            <?php if ($may['source']): ?><div class="fs-12 mt-2"><a href="/sources/new" id="home-first-run-source">or connect a source</a></div><?php endif; ?>
        <?php else: ?>
            <div class="fs-12 text-muted" id="home-first-run-none">When datasets are shared with you, they appear here.</div>
        <?php endif; ?>
    </div>
</div></div>
