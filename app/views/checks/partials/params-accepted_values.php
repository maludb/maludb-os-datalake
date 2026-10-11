<?php /** accepted_values' params: the values, one per line. Data: p, err */ ?>
<div class="mb-3" id="check-form-field-values"><label class="form-label" for="check-form-values">Accepted values (one per line)</label>
    <textarea class="form-control" id="check-form-values" name="values" rows="4"><?= e(implode("\n", array_map('strval', (array) ($p['values'] ?? [])))) ?></textarea><?= $err('values') ?></div>
