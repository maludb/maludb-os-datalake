<?php
/**
 * The columns (uploads-loads.md): a table at 1280, a card per column at 375 — ONE set of fields (each column is mapping-card.php, a row of
 * the table from md up and a card below). The header row shows from md. Data: sh (the sheet: columns, rows), tm (a template's mapping), dedupe
 */
?>
<div class="card mb-3"><div class="card-body">
    <h6 class="fs-12 text-uppercase text-muted">Columns <span class="text-lowercase fw-normal">— name each one, choose its type, and say what is personal</span></h6>
    <div class="map-columns" id="map-columns">
        <div class="row g-2 d-none d-md-flex fs-11 text-uppercase text-muted fw-semibold border-bottom pb-1 mb-1" id="map-columns-head">
            <div class="col-md-3">In the file</div><div class="col-md-3">Name</div><div class="col-md-2">Type</div><div class="col-md-3">Personal data</div><div class="col-md-1">Key · skip</div>
        </div>
        <?php foreach ($sh['columns'] as $i => $c): ?>
            <?= view('upload/partials/mapping-card.php', ['i' => $i, 'c' => $c, 't' => $tm['columns'][$c['source_name']] ?? null, 'samples' => array_slice(array_column($sh['rows'], $i), 0, 3),
                'key' => in_array($c['name'], (array) $dedupe, true) || in_array(($tm['columns'][$c['source_name']]['name'] ?? ''), (array) $dedupe, true)]) ?>
        <?php endforeach; ?>
    </div>
    <div class="form-text">A raw dataset keeps every column as text (the types are kept as hints for its contract); your sandbox stores each column as its type — a cell that is not one lands empty and is listed on the load.</div>
</div></div>
