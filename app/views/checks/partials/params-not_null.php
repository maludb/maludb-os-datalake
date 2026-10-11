<?php /** not_null's params: an allowance of missing values (0 by default). Data: p, err */ ?>
<div class="mb-3" id="check-form-field-max_failures"><label class="form-label" for="check-form-max-failures">Missing values allowed</label>
    <input type="number" min="0" step="1" class="form-control btn-touch" id="check-form-max-failures" name="max_failures" value="<?= e((string) ($p['max_failures'] ?? 0)) ?>"><?= $err('max_failures') ?></div>
