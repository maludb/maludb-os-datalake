<?php
/**
 * The editor: a <textarea> (#sql-editor) — completion of my datasets' keys and columns, Ctrl/⌘+Enter runs (assets/js/sql-editor.js; without
 * it the form posts and the page comes back with the result); as of; Run, Explain (the dry run's plan), Cancel while running (its own form —
 * the page's request id names the run). Data: pre, as_of, request_id
 */
$prefix = 'sql-' . (int) current_member_id() . '-';
?>
<div class="card" id="sql-editor-card"><div class="card-body">
    <form method="post" action="/sql/run" hx-post="/sql/run" hx-target="#sql-result" hx-swap="innerHTML" id="sql-form" data-request-prefix="<?= e($prefix) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="request_id" id="sql-request-id" value="<?= e($request_id) ?>">
        <?php if (($pre['saved_query'] ?? null) !== null): ?><div class="fs-12 text-muted mb-1" id="sql-from-saved">From the saved query <?= hx_link('/queries/' . (int) $pre['saved_query'], e((string) $pre['title'])) ?></div><?php endif; ?>
        <label class="form-label fs-12 mb-1" for="sql-editor">One SELECT over dataset keys (<code>zone.name</code>)</label>
        <div class="sql-editor-wrap position-relative">
            <textarea class="form-control font-monospace sql-editor" id="sql-editor" name="sql" rows="8" spellcheck="false" autocapitalize="off" autocomplete="off"
                      placeholder="SELECT region, sum(amount) FROM curated.sales_orders GROUP BY region"><?= e((string) $pre['sql']) ?></textarea>
            <div class="sql-suggest list-group d-none" id="sql-suggest" role="listbox" aria-label="Datasets and columns"></div>
        </div>
        <div class="d-flex flex-wrap align-items-end gap-2 mt-2">
            <div><label class="form-label fs-12 mb-1" for="sql-as-of">As of</label>
                <input type="text" class="form-control form-control-sm" id="sql-as-of" name="as_of" value="<?= e((string) $as_of) ?>" placeholder="latest — or a snapshot / 2026-10-01" style="max-width: 14rem"></div>
            <button type="submit" class="btn btn-primary btn-touch" id="sql-run"><i class="feather-play me-1"></i>Run</button>
            <button type="submit" class="btn btn-light btn-touch" name="dry_run" value="1" id="sql-explain"><i class="feather-list me-1"></i>Explain</button>
            <span class="fs-11 text-muted d-none d-md-inline">Ctrl/⌘ + Enter runs</span>
            <span class="htmx-indicator fs-12 text-muted" id="sql-running"><span class="spinner-border spinner-border-sm me-1"></span>Running…</span>
        </div>
    </form>
    <form method="post" action="/sql/cancel" hx-post="/sql/cancel" hx-target="#sql-cancel-slot" hx-swap="innerHTML" id="sql-cancel-form" class="d-none mt-2">
        <?= csrf_field() ?><input type="hidden" name="request_id" id="sql-cancel-request-id" value="<?= e($request_id) ?>">
        <button type="submit" class="btn btn-outline-danger btn-touch btn-sm" id="sql-cancel"><i class="feather-x-octagon me-1"></i>Cancel</button>
    </form>
    <div id="sql-cancel-slot"></div>
</div></div>
