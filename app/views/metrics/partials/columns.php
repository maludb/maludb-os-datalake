<?php
/**
 * The product's columns as the form's choices (#metric-form-columns; Pattern A: reloaded when the product picker changes): the time column (a select of the columns) and the dimensions
 * (a checkbox chip per column). Data: v (the form's values), columns [{name, logical_type, personal}]
 */
?>
<div id="metric-form-columns" class="row g-3">
    <div class="col-md-5"><label class="form-label" for="metric-form-field-time_column">Time column</label>
        <select class="form-select btn-touch" id="metric-form-field-time_column" name="time_column">
            <option value=""><?= $columns === [] ? 'Choose the product first' : '— none: one number —' ?></option>
            <?php foreach ($columns as $c): ?><option value="<?= e($c['name']) ?>"<?= $v['time_column'] === $c['name'] ? ' selected' : '' ?>><?= e($c['name']) ?> (<?= e((string) $c['logical_type']) ?>)</option><?php endforeach; ?>
        </select>
        <div class="form-text">The date or time each row belongs to. Without one the metric is a single number.</div></div>
    <div class="col-md-7"><fieldset id="metric-form-field-dimensions"><legend class="form-label">Dimensions</legend>
        <?php if ($columns === []): ?><div class="fs-12 text-muted">Choose the product first.</div><?php endif; ?>
        <div class="d-flex flex-wrap gap-2">
        <?php foreach ($columns as $c): ?>
            <div class="form-check mb-0" style="min-height: 44px; display: flex; align-items: center; gap: .4rem;"><input class="form-check-input mt-0" type="checkbox" name="dimensions[]" value="<?= e($c['name']) ?>" id="metric-dim-<?= e($c['name']) ?>"<?= in_array($c['name'], $v['dimensions'], true) ? ' checked' : '' ?>>
                <label class="form-check-label" for="metric-dim-<?= e($c['name']) ?>"><?= e($c['name']) ?><?= $c['personal'] ? ' <span class="badge bg-soft-warning text-warning">personal</span>' : '' ?></label></div>
        <?php endforeach; ?>
        </div>
        <div class="form-text">The columns it may be cut by. Changing them removes the Certified badge.</div></fieldset></div>
</div>
