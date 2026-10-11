<?php
/**
 * Where it lands (#map-target; uploads-loads.md): my sandbox (a new dataset's name → sandbox.m{me}_{name}, or my dataset of that name for next
 * month's file) or, for an engineer, a raw dataset — one I may write (the picker) or a new name; append or replace. Data: me, base, may_raw
 */
?>
<div class="card mb-3" id="map-target"><div class="card-body row g-3">
    <h6 class="fs-12 text-uppercase text-muted mb-0">Where it lands</h6>
    <div class="col-12">
        <div class="form-check"><input class="form-check-input" type="radio" name="target" id="map-target-sandbox" value="sandbox" checked>
            <label class="form-check-label fw-semibold" for="map-target-sandbox">My sandbox</label></div>
        <div class="input-group mt-1 ms-md-4" style="max-width: 32rem;"><span class="input-group-text fs-12" id="map-name-prefix">sandbox.m<?= (int) $me ?>_</span>
            <input type="text" class="form-control btn-touch" id="map-name" name="name" value="<?= e($base) ?>" maxlength="40" pattern="[a-z][a-z0-9_]{0,39}" aria-describedby="map-name-prefix map-name-help" autocomplete="off"></div>
        <div class="form-text ms-md-4" id="map-name-help">Yours alone, typed as you chose. The same name again adds next month's file to it.</div>
    </div>
    <?php if ($may_raw): ?>
        <div class="col-12">
            <div class="form-check"><input class="form-check-input" type="radio" name="target" id="map-target-raw" value="raw">
                <label class="form-check-label fw-semibold" for="map-target-raw">A raw dataset</label></div>
            <div class="row g-2 mt-1 ms-md-3">
                <div class="col-md-6"><label class="form-label fs-12 mb-1" id="map-target-dataset-label" for="map-target-dataset">One that exists</label>
                    <?= picker_field(['id' => 'map-target-dataset', 'name' => 'target_dataset', 'source' => 'raw_dataset', 'value' => null, 'labelledby' => 'map-target-dataset-label', 'title' => 'A raw dataset', 'placeholder' => 'Choose one']) ?></div>
                <div class="col-md-6"><label class="form-label fs-12 mb-1" for="map-raw-name">or a new one: raw.</label>
                    <input type="text" class="form-control btn-touch" id="map-raw-name" name="raw_name" maxlength="63" pattern="[a-z][a-z0-9_]{0,62}" placeholder="crm_deals" autocomplete="off"></div>
            </div>
            <div class="form-text ms-md-3">Every column lands as text with its provenance; a contract types it into curated.</div>
        </div>
    <?php endif; ?>
    <div class="col-md-4"><label class="form-label" for="map-mode">When it has data already</label>
        <select class="form-select btn-touch" id="map-mode" name="mode"><option value="append" selected>Add these rows</option><option value="replace">Replace its rows</option></select></div>
</div></div>
