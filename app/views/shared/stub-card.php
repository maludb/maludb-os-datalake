<?php
/**
 * A STUB — a dataset the person may not discover but may know exists (dl_can_see_stub: an insider holding a role, never an external):
 * the lock, its name, its owner and Request access. Never an excerpt, a key, a classification or a count. Data: r = [title, owner_name?, request_url, id]
 */
?>
<div class="card dataset-card stub-card mb-2" id="<?= e($r['id']) ?>"><div class="card-body py-3">
    <div class="d-flex justify-content-between align-items-center gap-2">
        <div class="min-w-0">
            <div class="fw-semibold text-truncate"><i class="feather-lock me-1 text-muted" aria-label="You may not open this yet"></i><?= e((string) $r['title']) ?></div>
            <?php if (($r['owner_name'] ?? null) !== null): ?><div class="fs-11 text-muted"><i class="feather-user me-1"></i><?= e($r['owner_name']) ?></div><?php endif; ?>
        </div>
        <a href="<?= e($r['request_url']) ?>" class="btn btn-light btn-sm btn-touch flex-shrink-0" id="<?= e($r['id']) ?>-request">Request access</a>
    </div>
</div></div>
