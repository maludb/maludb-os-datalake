<?php /** regex's params: the pattern every value must match whole. Data: p, err */ ?>
<div class="mb-3" id="check-form-field-pattern"><label class="form-label" for="check-form-pattern">Pattern (a regular expression the whole value matches)</label>
    <input type="text" class="form-control btn-touch font-monospace" id="check-form-pattern" name="pattern" maxlength="500" value="<?= e((string) ($p['pattern'] ?? '')) ?>" placeholder="^[A-Z]{2}-\d{4}$"><?= $err('pattern') ?></div>
