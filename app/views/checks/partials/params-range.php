<?php /** range's params: a minimum, a maximum, or both. Data: p, err */ ?>
<div class="row g-2 mb-3">
    <div class="col-6" id="check-form-field-min"><label class="form-label" for="check-form-min">Minimum</label>
        <input type="text" inputmode="decimal" class="form-control btn-touch" id="check-form-min" name="min" value="<?= e((string) ($p['min'] ?? '')) ?>"><?= $err('min') ?></div>
    <div class="col-6" id="check-form-field-max"><label class="form-label" for="check-form-max">Maximum</label>
        <input type="text" inputmode="decimal" class="form-control btn-touch" id="check-form-max" name="max" value="<?= e((string) ($p['max'] ?? '')) ?>"><?= $err('max') ?></div>
</div>
