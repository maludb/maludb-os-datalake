<?php
/** "Read it again" — the header row (1–50), and for a CSV the delimiter and the encoding found, corrected: POST /uploads/{id}/sniff queues a new sniff. Data: u, s */
$s = is_array($s) ? $s : [];
$csv = ($s['format'] ?? 'csv') === 'csv';
?>
<form method="post" action="/uploads/<?= (int) $u['id'] ?>/sniff" hx-post="/uploads/<?= (int) $u['id'] ?>/sniff" hx-target="#page-content" class="row g-2 align-items-end mt-1" id="map-reread-form">
    <?= csrf_field() ?>
    <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="map-header-row">Header on row</label>
        <input type="number" class="form-control btn-touch" id="map-header-row" name="header_row" min="1" max="50" value="<?= (int) ($s['header_row'] ?? 1) ?>"></div>
    <?php if ($csv): ?>
        <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="map-delimiter-input">Delimiter</label>
            <select class="form-select btn-touch" id="map-delimiter-input" name="delimiter">
                <?php foreach (['' => 'As found', ',' => 'comma', ';' => 'semicolon', "\t" => 'tab', '|' => 'bar'] as $v => $w): ?>
                    <option value="<?= e($v) ?>"><?= e($w) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="map-encoding-input">Encoding</label>
            <select class="form-select btn-touch" id="map-encoding-input" name="encoding">
                <?php foreach (['' => 'As found', 'utf-8' => 'UTF-8', 'cp1252' => 'Windows-1252', 'latin-1' => 'Latin-1', 'utf-16' => 'UTF-16'] as $v => $w): ?>
                    <option value="<?= e($v) ?>"><?= e($w) ?></option><?php endforeach; ?>
            </select></div>
    <?php endif; ?>
    <div class="col-6 col-md-3"><button type="submit" class="btn btn-light btn-touch" id="map-reread-btn"><i class="feather-refresh-cw me-1"></i>Read it again</button></div>
</form>
