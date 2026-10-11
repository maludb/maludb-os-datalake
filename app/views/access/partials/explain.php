<?php /** Why I have what I have (the Access tab for everyone but the owner and grants.manage): dl_access_explain as sentences. Data: s (access_explain_sentences), level, request (url|null) */ ?>
<div class="card" id="access-explain"><div class="card-body">
    <div class="fw-semibold mb-1" id="access-explain-headline"><?= e((string) $s['headline']) ?></div>
    <?php if ($s['reasons'] !== []): ?><ul class="mb-2 fs-13" id="access-explain-reasons"><?php foreach ($s['reasons'] as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($s['masks'] !== null): ?><div class="fs-12" id="access-explain-masks"><span class="badge border border-secondary text-secondary bg-transparent me-1">Masked for you</span><?= e($s['masks']) ?></div><?php endif; ?>
    <?php if ($s['filter'] !== null): ?><div class="fs-12 mt-1" id="access-explain-filter"><span class="badge border border-info text-info bg-transparent me-1">Rows filtered</span><?= e($s['filter']) ?></div><?php endif; ?>
    <?php if ($request !== null): ?><div class="mt-3"><?= hx_link($request, '<i class="feather-unlock me-1"></i>Request access', 'btn btn-primary btn-touch btn-sm', 'id="access-explain-request-btn"') ?></div><?php endif; ?>
</div></div>
