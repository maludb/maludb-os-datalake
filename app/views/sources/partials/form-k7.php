<?php /** The k7 connector's fields — a sibling's share through the kernel. Data: v, field */ ?>
<div class="col-md-6"><label class="form-label" for="<?= $field('provider') ?>">Application</label>
    <input type="text" class="form-control btn-touch" id="<?= $field('provider') ?>" name="provider" value="<?= e((string) $v['provider']) ?>" maxlength="64" required placeholder="gl" autocomplete="off">
    <div class="form-text">Its catalog key: gl, inventory, sales_crm…</div></div>
<div class="col-md-6"><label class="form-label" for="<?= $field('tool') ?>">Shared tool</label>
    <input type="text" class="form-control btn-touch" id="<?= $field('tool') ?>" name="tool" value="<?= e((string) $v['tool']) ?>" maxlength="64" required placeholder="account_balances" autocomplete="off"></div>
<div class="col-12"><label class="form-label" for="<?= $field('arguments') ?>">Arguments</label>
    <textarea class="form-control font-monospace" id="<?= $field('arguments') ?>" name="arguments" rows="3" spellcheck="false"><?= e((string) $v['arguments']) ?></textarea>
    <div class="form-text">A JSON object. &lt;yesterday&gt; and &lt;today&gt; become the date at each pull. A date window supplies from and to; paging supplies offset and limit.</div></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('rows_path') ?>">The rows are in</label>
    <input type="text" class="form-control btn-touch" id="<?= $field('rows_path') ?>" name="rows_path" value="<?= e((string) $v['rows_path']) ?>" maxlength="200" required placeholder="accounts" autocomplete="off">
    <div class="form-text">The list in the answer; the answer's other keys are kept with the pull.</div></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('paging') ?>">Paging</label>
    <select class="form-select btn-touch" id="<?= $field('paging') ?>" name="paging">
        <option value="none"<?= $v['paging'] === 'none' ? ' selected' : '' ?>>One answer</option><option value="offset"<?= $v['paging'] === 'offset' ? ' selected' : '' ?>>Page by page (offset)</option></select></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('page_size') ?>">Rows a page</label>
    <input type="number" class="form-control btn-touch" id="<?= $field('page_size') ?>" name="page_size" min="1" max="500" value="<?= e((string) $v['page_size']) ?>"></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('window') ?>">Window</label>
    <select class="form-select btn-touch" id="<?= $field('window') ?>" name="window">
        <option value="none"<?= $v['window'] === 'none' ? ' selected' : '' ?>>None</option><option value="date"<?= $v['window'] === 'date' ? ' selected' : '' ?>>By date — from the last pull to yesterday</option></select></div>
<div class="col-md-8"><label class="form-label" for="<?= $field('inject') ?>">Added to every row</label>
    <textarea class="form-control font-monospace" id="<?= $field('inject') ?>" name="inject" rows="2" spellcheck="false"><?= e((string) $v['inject']) ?></textarea>
    <div class="form-text">A JSON object: a field name, and where it comes from — "document" (the answer's own key of that name), "document:key", "pull_date" or "literal:text".</div></div>
