<?php
/** My readable datasets with their columns (#sql-catalog): a click on a key or a column inserts it at the caret (sql-editor.js). Data: catalog */
?>
<div class="card" id="sql-catalog"><div class="card-header"><h5 class="card-title mb-0">Datasets I may read</h5></div><div class="card-body p-2">
<?php if ($catalog === []): ?>
    <div class="fs-12 text-muted p-2" id="sql-catalog-empty">There is no dataset you may read yet — <?= hx_link('/browse', 'browse the catalog') ?> and request access.</div>
<?php else: ?>
    <input type="search" class="form-control form-control-sm mb-2" id="sql-catalog-filter" placeholder="Filter" aria-label="Filter the datasets">
    <div class="sql-catalog-list">
    <?php foreach ($catalog as $d): ?>
        <details class="sql-catalog-item mb-1" id="sql-catalog-<?= (int) $d['dataset_id'] ?>" data-key="<?= e($d['key']) ?>">
            <summary class="fs-12"><button type="button" class="btn btn-link btn-sm p-0 font-monospace sql-insert" data-insert="<?= e($d['key']) ?>" title="Insert <?= e($d['key']) ?>"><?= e($d['key']) ?></button>
                <span class="text-muted d-block text-truncate"><?= e((string) $d['title']) ?></span></summary>
            <ul class="list-unstyled ms-3 mb-1 fs-11">
                <?php foreach ($d['columns'] as $c): ?>
                    <li><button type="button" class="btn btn-link btn-sm p-0 font-monospace sql-insert" data-insert="<?= e($c['name']) ?>"><?= e($c['name']) ?></button>
                        <span class="text-muted"><?= e((string) $c['logical_type']) ?></span><?= $c['masked'] ? ' <span class="badge bg-soft-warning text-warning">masked for you</span>' : '' ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
</div></div>
