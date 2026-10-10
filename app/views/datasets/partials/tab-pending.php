<?php /** A tab whose data a later slice owns (catalog.md divergence 1): it says which slice builds it — never a link to a 404. Data: tab, slice, what */ ?>
<div class="card coming-card" id="dataset-tab-<?= e($tab) ?>-pending"><div class="card-body">
    <div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="feather-clock"></i></span>
        <div><div class="fw-semibold">Built by <?= e($slice) ?></div><div class="fs-12 text-muted"><?= e($what) ?></div></div></div>
</div></div>
