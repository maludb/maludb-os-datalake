<?php /** freshness's params: the age in hours (empty: the dataset's own freshness expectation). Data: p, err */ ?>
<div class="mb-3" id="check-form-field-max_age_hours"><label class="form-label" for="check-form-max-age">No older than (hours)</label>
    <input type="text" inputmode="decimal" class="form-control btn-touch" id="check-form-max-age" name="max_age_hours" value="<?= e((string) ($p['max_age_hours'] ?? '')) ?>" placeholder="the dataset's own expectation">
    <?= $err('max_age_hours') ?></div>
