<?php
/** Step 1 of Add source: the five connectors as cards, each a link to the form (/sources/new?connector=). Data: (none) */
?>
<div class="main-content" id="source-add-connectors">
    <p class="fs-12 text-muted">Where does the data come from? Each source reads ONE thing into ONE raw dataset.</p>
    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2" id="source-connector-cards">
        <?php foreach (dl_fetch_connectors() as $key => $c): ?>
            <div class="col"><div class="card h-100 source-connector-card" id="source-connector-<?= e($key) ?>"><div class="card-body">
                <div class="d-flex align-items-start gap-2">
                    <span class="avatar-text avatar-md rounded flex-shrink-0" aria-hidden="true"><i class="<?= e($c['icon']) ?>"></i></span>
                    <div>
                        <div class="fw-semibold"><?= hx_link('/sources/new?connector=' . rawurlencode($key), e($c['label']), 'text-dark stretched-link', 'id="source-connector-' . e($key) . '-link"') ?></div>
                        <div class="fs-12 text-muted"><?= e($c['description']) ?></div>
                    </div>
                </div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
</div>
