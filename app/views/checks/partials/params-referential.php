<?php /** referential's params: the dataset (a key you may read) and its column every value must exist in — prefilled from the column's declared reference. Data: p, err */ ?>
<div class="row g-2 mb-3">
    <div class="col-12 col-md-7" id="check-form-field-dataset_key"><label class="form-label" for="check-form-dataset-key">Values must exist in (dataset)</label>
        <input type="text" class="form-control btn-touch font-monospace" id="check-form-dataset-key" name="dataset_key" value="<?= e((string) ($p['dataset_key'] ?? '')) ?>" placeholder="curated.customers"><?= $err('dataset_key') ?></div>
    <div class="col-12 col-md-5" id="check-form-field-ref_column"><label class="form-label" for="check-form-ref-column">Its column</label>
        <input type="text" class="form-control btn-touch font-monospace" id="check-form-ref-column" name="ref_column" value="<?= e((string) ($p['column'] ?? '')) ?>" placeholder="customer_id"><?= $err('ref_column') ?></div>
</div>
