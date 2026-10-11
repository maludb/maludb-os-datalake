<?php
/**
 * A metric's form (screens `metric-add` and `metric-edit`; transforms-products-lineage.md): name, title, the product (the record picker over the product zone — choosing one loads its columns), the
 * expression (ONE aggregate over the product's columns), the time column and the dimensions (the product's columns), the grains, a fixed filter, the format and unit, a description. "Saving checks it
 * through the lake as you." Data: m (null on add), v, columns
 */
$editing = $m !== null;
$id = $editing ? (int) $m['metric_id'] : null;
$field = static fn (string $n): string => 'metric-form-field-' . $n;
?>
<?= view('shared/page-header.php', ['id' => 'metric-form', 'title' => $editing ? 'Edit ' . $m['title'] : 'New metric',
    'crumbs' => $editing ? [['Metrics', '/metrics'], [$m['title'], '/metrics/' . $id], ['Edit', null]] : [['Metrics', '/metrics'], ['New', null]], 'back' => $editing ? ['/metrics/' . $id, $m['title']] : ['/metrics', 'Metrics']]) ?>
<div class="main-content" id="metric-form-content">
    <form method="post" action="/metrics/save.php" hx-post="/metrics/save.php" hx-target="#flash" id="metric-form" class="page-header-form">
        <?= csrf_field() ?><?php if ($editing): ?><input type="hidden" name="metric" value="<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="<?= $field('title') ?>">Title</label>
                <input type="text" class="form-control btn-touch" id="<?= $field('title') ?>" name="title" value="<?= e($v['title']) ?>" maxlength="200" required placeholder="Revenue"></div>
            <div class="col-md-6"><label class="form-label" for="<?= $field('name') ?>">Name</label>
                <input type="text" class="form-control btn-touch font-monospace" id="<?= $field('name') ?>" name="name" value="<?= e($v['name']) ?>" maxlength="63" pattern="[a-z][a-z0-9_]{0,62}" required autocomplete="off" placeholder="revenue">
                <div class="form-text">Lower case, digits and underscores — what an agent and the API call it.</div></div>
            <div class="col-12"><label class="form-label" id="metric-form-dataset-label" for="<?= $field('dataset') ?>">The product it is over</label>
                <?= picker_field(['id' => $field('dataset'), 'name' => 'dataset', 'source' => 'product', 'value' => $v['dataset'], 'labelledby' => 'metric-form-dataset-label', 'title' => 'The product', 'placeholder' => 'Choose a product',
                    'required' => true, 'extra' => ' hx-get="/metrics/new?fragment=columns" hx-trigger="change" hx-target="#metric-form-columns" hx-swap="outerHTML" hx-include="this"']) ?>
                <div class="form-text">A metric is defined over a product dataset. Make one with a transform first if there is none.</div></div>
            <div class="col-12"><label class="form-label" for="<?= $field('description') ?>">Description <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control btn-touch" id="<?= $field('description') ?>" name="description" value="<?= e($v['description']) ?>" maxlength="4000" placeholder="What it counts, and what it leaves out."></div>
        </div></div></div>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <h6 class="fs-12 text-uppercase text-muted mb-0">The number</h6>
            <div class="col-md-7"><label class="form-label" for="<?= $field('expression') ?>">Expression</label>
                <input type="text" class="form-control btn-touch font-monospace" id="<?= $field('expression') ?>" name="expression" value="<?= e($v['expression']) ?>" maxlength="1000" required autocomplete="off" placeholder="sum(revenue)">
                <div class="form-text">One aggregate over the product's columns: <code>sum(amount)</code>, <code>count(DISTINCT customer_id)</code>, <code>sum(a) / nullif(sum(b), 0)</code>.</div></div>
            <div class="col-md-5"><label class="form-label" for="<?= $field('filters') ?>">Fixed filter <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control btn-touch font-monospace" id="<?= $field('filters') ?>" name="filters" value="<?= e($v['filters']) ?>" maxlength="1000" autocomplete="off" placeholder="status <> 'cancelled'">
                <div class="form-text">A condition on each row, applied to every cut.</div></div>
            <div class="col-md-4"><label class="form-label" for="<?= $field('format') ?>">Shown as</label>
                <select class="form-select btn-touch" id="<?= $field('format') ?>" name="format"><?php foreach (METRIC_FORMATS as $k => $w): ?><option value="<?= e($k) ?>"<?= $v['format'] === $k ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="<?= $field('unit') ?>">Unit <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control btn-touch" id="<?= $field('unit') ?>" name="unit" value="<?= e($v['unit']) ?>" maxlength="40" placeholder="$ or EUR or orders"></div>
            <div class="col-md-4"><fieldset id="<?= $field('grains') ?>"><legend class="form-label">Grains</legend>
                <div class="d-flex flex-wrap gap-2"><?php foreach (METRIC_GRAINS as $g => $w): ?>
                    <div class="form-check mb-0" style="min-height: 44px; display: flex; align-items: center; gap: .4rem;"><input class="form-check-input mt-0" type="checkbox" name="grains[]" value="<?= e($g) ?>" id="metric-grain-<?= e($g) ?>"<?= in_array($g, $v['grains'], true) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="metric-grain-<?= e($g) ?>"><?= e($w) ?></label></div>
                <?php endforeach; ?></div></fieldset></div>
            <div class="col-12"><?= view('metrics/partials/columns.php', ['v' => $v, 'columns' => $columns]) ?></div>
        </div></div></div>
        <div class="d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="metric-form-save-btn">Save</button>
            <?= hx_link($editing ? '/metrics/' . $id : '/metrics', 'Cancel', 'btn btn-light btn-touch', 'id="metric-form-cancel-btn"') ?>
        </div>
    </form>
</div>
