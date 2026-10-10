<?php
/**
 * A STUB (catalog.md; access-and-policies §1): a dataset the person may not discover but may know exists — its title, key, owner,
 * department and Request access. No tabs, no description, no columns, no counts. Data: s (find_dataset_stub)
 */
$request = '/access/requests/new?' . http_build_query(['dataset' => (int) $s['dataset_id'], 'level' => 'read']);
?>
<?= view('shared/page-header.php', ['id' => 'dataset', 'title' => $s['title'], 'crumbs' => [['Browse', '/browse'], [$s['key'], null]], 'back' => back_link()]) ?>
<div class="main-content" id="dataset-stub">
    <div class="card stub-card"><div class="card-body">
        <div class="d-flex align-items-start gap-3">
            <span class="avatar-text avatar-lg rounded flex-shrink-0"><i class="feather-lock"></i></span>
            <div class="min-w-0">
                <div class="fw-semibold text-break" id="dataset-stub-title"><?= e($s['title']) ?></div>
                <div class="dataset-key" id="dataset-stub-key"><?= e($s['key']) ?></div>
                <div class="fs-12 text-muted mt-1" id="dataset-stub-owner"><i class="feather-user me-1"></i>Owner: <?= e((string) ($s['owner_name'] ?? 'nobody yet')) ?></div>
                <?php if ($s['department_name'] !== null): ?><div class="fs-12 text-muted" id="dataset-stub-department"><i class="feather-users me-1"></i><?= e($s['department_name']) ?></div><?php endif; ?>
                <p class="fs-12 text-muted mt-2 mb-3">You may know this dataset exists, not what is in it. Ask its owner for access — say what you need it for.</p>
                <a href="<?= e($request) ?>" class="btn btn-primary btn-touch" id="dataset-stub-request-btn"><i class="feather-unlock me-1"></i>Request access</a>
            </div>
        </div>
    </div></div>
</div>
