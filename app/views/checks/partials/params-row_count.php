<?php /** row_count's params: bounds, a change in percent against the last count, or both. Data: p, err */ ?>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-4" id="check-form-field-min"><label class="form-label" for="check-form-min">At least (rows)</label>
        <input type="number" min="0" step="1" class="form-control btn-touch" id="check-form-min" name="min" value="<?= e((string) ($p['min'] ?? '')) ?>"><?= $err('min') ?></div>
    <div class="col-6 col-md-4" id="check-form-field-max"><label class="form-label" for="check-form-max">At most (rows)</label>
        <input type="number" min="0" step="1" class="form-control btn-touch" id="check-form-max" name="max" value="<?= e((string) ($p['max'] ?? '')) ?>"><?= $err('max') ?></div>
    <div class="col-12 col-md-4" id="check-form-field-change_pct"><label class="form-label" for="check-form-change-pct">Within ± % of the last count</label>
        <input type="text" inputmode="decimal" class="form-control btn-touch" id="check-form-change-pct" name="change_pct" value="<?= e((string) ($p['change_pct'] ?? '')) ?>" placeholder="20"><?= $err('change_pct') ?></div>
</div>
