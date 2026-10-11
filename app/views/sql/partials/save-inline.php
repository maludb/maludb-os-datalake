<?php
/**
 * Above a result: Save (a saved query — queries.save), Download (an export — export.own, pauses for an agent), Save as dataset (sandbox.save):
 * each a small form posting the same SQL. Data: sql, as_of, r
 */
$asOfVal = $as_of === null ? '' : (string) $as_of;
?>
<div class="d-flex flex-wrap gap-2 mb-2" id="sql-result-actions">
    <?php if (has_right('queries.save')): ?>
        <details class="sql-action" id="sql-save-inline"><summary class="btn btn-light btn-sm btn-touch"><i class="feather-bookmark me-1"></i>Save</summary>
            <form method="post" action="/queries/save.php" class="card card-body mt-2 p-2" id="sql-save-form">
                <?= csrf_field() ?><input type="hidden" name="sql" value="<?= e($sql) ?>">
                <label class="form-label fs-12 mb-1" for="sql-save-title">Title</label><input class="form-control form-control-sm mb-2" id="sql-save-title" name="title" maxlength="200" required>
                <label class="form-label fs-12 mb-1" for="sql-save-question">The question it answers</label><input class="form-control form-control-sm mb-2" id="sql-save-question" name="question" maxlength="500">
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="shared" value="yes" id="sql-save-shared"><label class="form-check-label fs-12" for="sql-save-shared">Share it with whoever may read its datasets</label></div>
                <button type="submit" class="btn btn-primary btn-sm" id="sql-save-submit">Save the query</button>
            </form></details>
    <?php endif; ?>
    <?php if (has_right('export.own')): ?>
        <details class="sql-action" id="sql-download-inline"><summary class="btn btn-light btn-sm btn-touch"><i class="feather-download me-1"></i>Download</summary>
            <form method="post" action="/exports/make.php" class="card card-body mt-2 p-2" id="sql-export-form">
                <?= csrf_field() ?><input type="hidden" name="sql" value="<?= e($sql) ?>"><input type="hidden" name="as_of" value="<?= e($asOfVal) ?>">
                <label class="form-label fs-12 mb-1" for="sql-export-format">Format</label>
                <select class="form-select form-select-sm mb-2" id="sql-export-format" name="format"><option value="csv">CSV</option><option value="xlsx">Excel (XLSX)</option><option value="parquet">Parquet</option></select>
                <div class="fs-11 text-muted mb-2">Made in the background, within the download limit; masked columns stay masked.</div>
                <button type="submit" class="btn btn-primary btn-sm" id="sql-export-submit">Make the file</button>
            </form></details>
    <?php endif; ?>
    <?php if (has_right('sandbox.save')): ?>
        <details class="sql-action" id="sql-sandbox-inline"><summary class="btn btn-light btn-sm btn-touch"><i class="feather-box me-1"></i>Save as dataset</summary>
            <form method="post" action="/sql/save-dataset.php" class="card card-body mt-2 p-2" id="sql-sandbox-form">
                <?= csrf_field() ?><input type="hidden" name="sql" value="<?= e($sql) ?>"><input type="hidden" name="as_of" value="<?= e($asOfVal) ?>">
                <label class="form-label fs-12 mb-1" for="sql-sandbox-name">Name (sandbox.m<?= (int) current_member_id() ?>_…)</label>
                <input class="form-control form-control-sm mb-2 font-monospace" id="sql-sandbox-name" name="name" pattern="[a-z][a-z0-9_]{0,40}" maxlength="41" required>
                <label class="form-label fs-12 mb-1" for="sql-sandbox-title">Title</label><input class="form-control form-control-sm mb-2" id="sql-sandbox-title" name="title" maxlength="200">
                <div class="fs-11 text-muted mb-2">Kept in your sandbox, masked as you see it, as confidential as what it reads.</div>
                <button type="submit" class="btn btn-primary btn-sm" id="sql-sandbox-submit">Save as dataset</button>
            </form></details>
    <?php endif; ?>
</div>
