<?php
/**
 * A column's form (screen `column-edit`; catalog.md): business name, description (4000), the glossary term (picker), critical, and the
 * reference — a dataset (picker) and one of its live columns (a select loaded when the dataset changes). The name, the type, the
 * classification and the personal kind are not here (divergence 3): the engine's and slice 4's. Data: d, c, v, ref_columns
 */
$id = (int) $d['dataset_id'];
$f = static fn (string $n): string => 'column-form-field-' . $n;
$back = '/datasets/' . $id . '/columns/' . rawurlencode((string) $c['name']);
?>
<?= view('shared/page-header.php', ['id' => 'column-form', 'title' => 'Describe ' . $c['name'],
    'crumbs' => [['Browse', '/browse'], [$d['key'], '/datasets/' . $id . '?tab=columns'], [$c['name'], $back], ['Describe', null]], 'back' => [$back, $c['name']]]) ?>
<div class="main-content" id="column-form-content">
    <form method="post" action="/datasets/columns/save.php" hx-post="/datasets/columns/save.php" hx-target="#flash" id="column-form" class="page-header-form">
        <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="column" value="<?= (int) $c['column_id'] ?>">
        <input type="hidden" name="return_to" value="<?= e($back) ?>">
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-12"><div class="fs-12 text-muted">Column</div><div class="dataset-key" id="column-form-name"><?= e($c['name']) ?></div>
                <div class="form-text">Its name and type are the engine's; its classification and personal kind are set on the Access screens.</div></div>
            <div class="col-md-6"><label class="form-label" for="<?= $f('business_name') ?>">Business name</label>
                <input type="text" class="form-control btn-touch" id="<?= $f('business_name') ?>" name="business_name" value="<?= e((string) ($v['business_name'] ?? '')) ?>" maxlength="200" placeholder="Order amount"></div>
            <div class="col-md-6"><label class="form-label" id="column-form-term-label" for="<?= $f('term') ?>">Glossary term</label>
                <?= picker_field(['id' => $f('term'), 'name' => 'term', 'source' => 'term', 'value' => $v['glossary_term_id'], 'labelledby' => 'column-form-term-label', 'title' => 'The glossary term', 'placeholder' => 'None']) ?></div>
            <div class="col-12"><label class="form-label" for="<?= $f('description') ?>">Description</label>
                <textarea class="form-control" id="<?= $f('description') ?>" name="description" rows="3" maxlength="4000"><?= e((string) ($v['description'] ?? '')) ?></textarea>
                <div class="form-text">What a value means, its unit, how it is filled.</div></div>
            <div class="col-12"><input type="hidden" name="critical" value="no">
                <div class="form-check"><input class="form-check-input" type="checkbox" name="critical" value="yes" id="<?= $f('critical') ?>"<?= !empty($v['critical']) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="<?= $f('critical') ?>">A critical data element — reports and checks depend on it</label></div></div>
            <div class="col-md-6"><label class="form-label" id="column-form-references-label" for="<?= $f('references_dataset') ?>">References the dataset</label>
                <?= picker_field(['id' => $f('references_dataset'), 'name' => 'references_dataset', 'source' => 'dataset', 'value' => $v['references_dataset_id'], 'labelledby' => 'column-form-references-label',
                    'title' => 'The dataset it references', 'placeholder' => 'None',
                    'extra' => ' hx-get="/datasets/' . $id . '/columns/' . rawurlencode((string) $c['name']) . '/edit?ref_columns=1" hx-trigger="change" hx-target="#column-form-field-references_column" hx-swap="outerHTML" hx-include="this"']) ?>
                <div class="form-text">An erasure follows a declared reference (a subject's orders by their customer id).</div></div>
            <div class="col-md-6"><label class="form-label" for="<?= $f('references_column') ?>">…its column</label>
                <?= view('catalog/ref-columns.php', ['names' => $ref_columns, 'selected' => $v['references_column']]) ?></div>
        </div></div></div>
        <div class="d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="column-form-save-btn">Save</button>
            <?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="column-form-cancel-btn"') ?>
        </div>
    </form>
</div>
