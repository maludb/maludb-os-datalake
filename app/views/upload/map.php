<?php
/**
 * The mapping (screen `upload-map`; uploads-loads.md). While the writer reads the file: upload/partials/sniffing.php (polls). A file it could
 * not read: the reason and Discard. Then: what was found (format, encoding, delimiter — corrected with the header row by "Read it again"),
 * the sheet (a workbook), the columns (upload/partials/mapping-table.php: a table at 1280, a card per column at 375 — one set of fields), the
 * first 50 rows, the target (upload/partials/target.php), a saved mapping to apply or this one to save; Confirm in the page header.
 * Data: u, sheet, tpl, me, may_raw, templates
 */
$id = (int) $u['id'];
$s = $u['sniff'];
$ready = $u['status'] === 'done' && $u['sniff_status'] === 'done' && is_array($s) && !isset($s['error']);
$failed = $u['sniff_status'] === 'failed';
$sh = $ready ? sniff_sheet($s, $sheet) : null;
$tm = is_array($tpl) ? ($tpl['mapping'] ?? []) : [];
$base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', (string) preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', (string) $u['filename'])), '_'));
$base = preg_match('/^[a-z]/', $base) ? substr($base, 0, 40) : substr('upload_' . $base, 0, 40);
$crumbs = [['Home', '/'], ['Upload', '/upload'], ['Map ' . $u['filename'], null]];
$confirm = $ready
    ? '<button type="submit" form="map-form" class="btn btn-primary btn-touch" id="map-confirm-btn"><i class="feather-check me-1"></i>Confirm and load</button>' : '';
?>
<?= view('shared/page-header.php', ['id' => 'upload-map', 'title' => 'Map ' . $u['filename'], 'crumbs' => $crumbs, 'back' => ['/upload', 'Upload'], 'action' => $confirm]) ?>
<div class="main-content" id="upload-map-content">
    <?php if (!$ready && !$failed): ?>
        <?= view('upload/partials/sniffing.php', ['u' => $u]) ?>
    <?php elseif ($failed): ?>
        <div class="card"><div class="card-body" id="map-failed">
            <div class="alert alert-danger mb-3" role="alert"><div class="fw-semibold">Could not read it</div><div class="fs-12"><?= e((string) ($s['error'] ?? $u['sniff_error'] ?? 'The file is not a spreadsheet the lake can read.')) ?></div></div>
            <?= view('upload/partials/reread.php', ['u' => $u, 's' => $s]) ?>
            <form method="post" action="/uploads/discard.php" hx-post="/uploads/discard.php" hx-target="#flash" hx-confirm="Discard this upload? Nothing of it was loaded." class="mt-3">
                <?= csrf_field() ?><input type="hidden" name="upload" value="<?= $id ?>">
                <button type="submit" class="btn btn-light btn-touch" id="map-discard-btn"><i class="feather-trash-2 me-1"></i>Discard</button></form>
        </div></div>
    <?php else: ?>
        <?php if ($u['load_id'] !== null): ?>
            <div class="alert alert-info" id="map-already-loaded">This file was confirmed already — <?= hx_link('/loads/' . (int) $u['load_id'], 'open its load', 'alert-link') ?>.
                Mapping it again into another dataset makes a new load.</div>
        <?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <div class="d-flex flex-wrap gap-3 fs-12" id="map-found">
                <div><span class="text-muted">Format</span> <span class="fw-semibold"><?= e(['csv' => 'CSV', 'workbook' => 'Workbook', 'json' => 'JSON', 'parquet' => 'Parquet'][$s['format']] ?? $s['format']) ?></span></div>
                <?php if ($s['encoding'] !== null): ?><div><span class="text-muted">Encoding</span> <span class="fw-semibold" id="map-encoding"><?= e((string) $s['encoding']) ?></span></div><?php endif; ?>
                <?php if ($s['delimiter'] !== null): ?><div><span class="text-muted">Delimiter</span> <span class="fw-semibold" id="map-delimiter"><?= e($s['delimiter'] === "\t" ? 'tab' : (string) $s['delimiter']) ?></span></div><?php endif; ?>
                <div><span class="text-muted">Header on row</span> <span class="fw-semibold"><?= (int) ($s['header_row'] ?? 1) ?></span></div>
                <div><span class="text-muted">Rows</span> <span class="fw-semibold"><?= $sh['rows_estimate'] === null ? '—' : e(count_words((int) $sh['rows_estimate'])) ?></span></div>
            </div>
            <?php foreach (array_values(array_filter(array_merge((array) ($s['warnings'] ?? []), [$sh['warning']]))) as $i => $w): ?>
                <div class="alert alert-warning fs-12 mt-2 mb-0 py-2" id="map-warning-<?= $i ?>"><?= e((string) $w) ?></div>
            <?php endforeach; ?>
            <details class="mt-2" id="map-reread"><summary class="fs-12 fw-semibold">Not right? Read it again</summary>
                <?= view('upload/partials/reread.php', ['u' => $u, 's' => $s]) ?></details>
            <?php if (!empty($s['sheets'])): ?>
                <form method="get" action="/uploads/<?= $id ?>/map" class="d-flex flex-wrap align-items-end gap-2 mt-2" id="map-sheet-form"
                      hx-get="/uploads/<?= $id ?>/map" hx-target="#page-content" hx-push-url="true" hx-trigger="change from:#map-sheet, submit">
                    <div><label class="form-label fs-12 mb-1" for="map-sheet">Sheet</label>
                        <select class="form-select btn-touch" id="map-sheet" name="sheet">
                            <?php foreach ($s['sheets'] as $x): ?><option value="<?= e($x['name']) ?>"<?= $x['name'] === $sh['name'] ? ' selected' : '' ?>><?= e($x['name']) ?> (<?= (int) $x['rows_estimate'] ?> rows)</option><?php endforeach; ?>
                        </select></div>
                    <button type="submit" class="btn btn-light btn-touch" id="map-sheet-btn">Show this sheet</button>
                </form>
            <?php endif; ?>
        </div></div>
        <form method="get" action="/uploads/<?= $id ?>/map" class="card mb-3" id="map-template-form"><div class="card-body d-flex flex-wrap align-items-end gap-2">
            <div class="flex-grow-1"><label class="form-label fs-12 mb-1" id="map-template-label" for="map-template">Apply a saved mapping</label>
                <?= picker_field(['id' => 'map-template', 'name' => 'template', 'source' => 'template', 'value' => $tpl === null ? null : (int) $tpl['template_id'], 'labelledby' => 'map-template-label', 'title' => 'A saved mapping', 'placeholder' => 'None']) ?></div>
            <?php if ($sheet !== null): ?><input type="hidden" name="sheet" value="<?= e($sheet) ?>"><?php endif; ?>
            <button type="submit" class="btn btn-light btn-touch" id="map-template-apply">Apply</button>
            <?php if ($tpl !== null): ?><div class="w-100 fs-12 text-success" id="map-template-applied">The mapping "<?= e($tpl['name']) ?>" is applied below — change anything before you confirm.</div><?php endif; ?>
        </div></form>
        <form method="post" action="/uploads/<?= $id ?>/map" hx-post="/uploads/<?= $id ?>/map" hx-target="#flash" id="map-form" class="page-header-form">
            <?= csrf_field() ?>
            <input type="hidden" name="upload" value="<?= $id ?>">
            <input type="hidden" name="header_row" value="<?= (int) ($s['header_row'] ?? 1) ?>">
            <?php if ($sh['name'] !== null): ?><input type="hidden" name="sheet" value="<?= e($sh['name']) ?>"><?php endif; ?>
            <?php if ($tpl !== null): ?><input type="hidden" name="template" value="<?= (int) $tpl['template_id'] ?>"><?php endif; ?>
            <?= view('upload/partials/mapping-table.php', ['sh' => $sh, 'tm' => $tm, 'dedupe' => (array) ($tm['dedupe_key'] ?? [])]) ?>
            <?= view('upload/partials/target.php', ['me' => $me, 'base' => $base, 'may_raw' => $may_raw]) ?>
            <div class="card mb-3"><div class="card-body row g-2 align-items-end">
                <div class="col-md-6"><label class="form-label" for="map-save-template">Save this mapping as</label>
                    <input type="text" class="form-control btn-touch" id="map-save-template" name="save_template" maxlength="120" placeholder="e.g. Monthly sales export">
                    <div class="form-text">Next month's file then maps in one step.</div></div>
                <div class="col-md-6 text-md-end"><button type="submit" class="btn btn-primary btn-touch" id="map-confirm-bottom"><i class="feather-check me-1"></i>Confirm and load</button></div>
            </div></div>
        </form>
        <div class="card mb-3"><div class="card-body">
            <h6 class="fs-12 text-uppercase text-muted">The first rows<?= $sh['rows'] === [] ? '' : ' (' . count($sh['rows']) . ')' ?></h6>
            <?php if (!isset($s['sample'])): ?>
                <div class="fs-12 text-muted" id="map-sample-cleared">The sample rows were cleared once this file was mapped — they are never kept.</div>
            <?php elseif ($sh['rows'] === []): ?>
                <div class="fs-12 text-muted">No rows under the header.</div>
            <?php else: ?>
                <div class="table-responsive" id="map-sample"><table class="table table-sm table-striped fs-12 mb-0">
                    <thead><tr><th class="text-muted">#</th><?php foreach ($sh['columns'] as $c): ?><th class="text-nowrap"><?= e($c['source_name']) ?></th><?php endforeach; ?></tr></thead>
                    <tbody><?php foreach ($sh['rows'] as $i => $r): ?><tr><td class="text-muted"><?= $i + 1 ?></td><?php foreach ($sh['columns'] as $j => $c): ?><td class="text-nowrap"><?= e((string) ($r[$j] ?? '')) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div></div>
    <?php endif; ?>
</div>
