<?php
/**
 * A transform's form (screens `transform-add` and `transform-edit`; transforms-products-lineage.md): the name; the dataset it makes (the record picker over the curated, product and
 * my-sandbox datasets I may write); the SQL with the keys I may read listed beside it (a button inserts a key at the caret); the mode; when it runs (on request | after its inputs
 * change | a cron); a description. "Saving runs the SQL through the lake as you: an input you may not read is refused; new SQL is a new version, refrozen as you." Data: t (null on add), v, keys
 */
$editing = $t !== null;
$id = $editing ? (int) $t['transform_id'] : null;
$field = static fn (string $n): string => 'transform-form-field-' . $n;
?>
<?= view('shared/page-header.php', ['id' => 'transform-form', 'title' => $editing ? 'Edit ' . $t['name'] : 'New transform',
    'crumbs' => $editing ? [['Transforms', '/transforms'], [$t['name'], '/transforms/' . $id], ['Edit', null]] : [['Transforms', '/transforms'], ['New', null]],
    'back' => $editing ? ['/transforms/' . $id, $t['name']] : ['/transforms', 'Transforms']]) ?>
<div class="main-content" id="transform-form-content">
    <form method="post" action="/transforms/save.php" hx-post="/transforms/save.php" hx-target="#flash" id="transform-form" class="page-header-form">
        <?= csrf_field() ?><?php if ($editing): ?><input type="hidden" name="transform" value="<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-md-5"><label class="form-label" for="<?= $field('name') ?>">Name</label>
                <input type="text" class="form-control btn-touch font-monospace" id="<?= $field('name') ?>" name="name" value="<?= e($v['name']) ?>" maxlength="63" pattern="[a-z][a-z0-9_]{0,62}" required autocomplete="off" placeholder="monthly_revenue">
                <div class="form-text">Lower case, digits and underscores.</div></div>
            <div class="col-md-7"><label class="form-label" id="transform-form-output-label" for="<?= $field('output_dataset') ?>">Makes the dataset</label>
                <?= picker_field(['id' => $field('output_dataset'), 'name' => 'output_dataset', 'source' => 'transform_output', 'value' => $v['output_dataset'], 'labelledby' => 'transform-form-output-label',
                    'title' => 'The dataset this transform makes', 'placeholder' => 'Choose the dataset', 'required' => true, 'disabled' => $editing, 'params' => $editing ? ['except' => $v['output_dataset']] : []]) ?>
                <div class="form-text"><?= $editing ? 'A transform\'s output never changes — make another transform.' : 'A curated or product dataset you may write, or your own sandbox. Register it first (New dataset) if it is not there.' ?></div></div>
            <div class="col-12"><label class="form-label" for="<?= $field('description') ?>">Description <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control btn-touch" id="<?= $field('description') ?>" name="description" value="<?= e($v['description']) ?>" maxlength="4000" placeholder="What the dataset is, in a sentence."></div>
        </div></div></div>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <h6 class="fs-12 text-uppercase text-muted mb-0">The SQL</h6>
            <div class="col-12 col-xl-8"><label class="form-label" for="<?= $field('sql') ?>">One SELECT over dataset keys</label>
                <?php if ($editing && !$t['may_read_sql']): ?>
                    <div class="alert alert-warning fs-12 py-2" id="transform-form-sql-hidden">You may not read every input, so the SQL is not shown. Type new SQL to replace it — or leave this empty to keep it.</div>
                <?php endif; ?>
                <textarea class="form-control font-monospace" id="<?= $field('sql') ?>" name="sql" rows="12" spellcheck="false"<?= $editing ? '' : ' required' ?>
                          placeholder="SELECT region, sum(amount) AS revenue FROM curated.orders GROUP BY region"><?= e($v['sql']) ?></textarea>
                <div class="form-text">Saving runs it through the lake as you: an input you may not read is refused by name. <?= $editing ? 'New SQL is a new version, refrozen as you — what is masked for you stays masked in the dataset.' : 'It is frozen at your read level: what is masked for you arrives masked in the dataset, and stays so.' ?></div></div>
            <div class="col-12 col-xl-4"><div class="card h-100"><div class="card-header py-2"><h6 class="card-title mb-0 fs-13">Datasets you may read</h6></div>
                <div class="card-body p-2" id="transform-form-keys" style="max-height: 18rem; overflow-y: auto;">
                    <?php if ($keys === []): ?><div class="fs-12 text-muted">There is no dataset you may read yet.</div><?php endif; ?>
                    <?php foreach ($keys as $d): ?>
                        <div class="d-flex align-items-baseline gap-1 mb-1"><button type="button" class="btn btn-link btn-sm p-0 font-monospace text-start text-break" data-insert="<?= e((string) $d['key']) ?>" data-insert-into="#<?= e($field('sql')) ?>"
                                title="Insert <?= e((string) $d['key']) ?>"><?= e((string) $d['key']) ?></button></div>
                    <?php endforeach; ?>
                </div></div></div>
        </div></div></div>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <h6 class="fs-12 text-uppercase text-muted mb-0">How and when</h6>
            <div class="col-md-6"><label class="form-label" for="<?= $field('mode') ?>">Mode</label>
                <select class="form-select btn-touch" id="<?= $field('mode') ?>" name="mode">
                    <?php foreach (TRANSFORM_MODES as $k => $w): ?><option value="<?= e($k) ?>"<?= $v['mode'] === $k ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?>
                </select>
                <div class="form-text">An incremental merge needs row-wise SQL — no aggregate, GROUP BY, DISTINCT or window; the writer refuses the run otherwise (<code>incremental_needs_rowwise</code>).</div></div>
            <div class="col-md-6"><label class="form-label" for="<?= $field('schedule_kind') ?>">Runs</label>
                <select class="form-select btn-touch" id="<?= $field('schedule_kind') ?>" name="schedule_kind">
                    <option value="manual"<?= $v['schedule_kind'] === 'manual' ? ' selected' : '' ?>>On request — when someone runs it</option>
                    <option value="after_inputs"<?= $v['schedule_kind'] === 'after_inputs' ? ' selected' : '' ?>>After its inputs change</option>
                    <option value="cron"<?= $v['schedule_kind'] === 'cron' ? ' selected' : '' ?>>On a schedule (cron)</option>
                </select>
                <input type="text" class="form-control btn-touch font-monospace mt-2" id="<?= $field('cron') ?>" name="cron" value="<?= e($v['cron']) ?>" maxlength="60" autocomplete="off" aria-label="The cron schedule"
                       placeholder="0 6 * * *">
                <div class="form-text">Five fields — minute hour day-of-month month day-of-week — in the business's time zone: <code>0 6 * * *</code> is every day at 06:00. Used only when "On a schedule" is chosen.</div></div>
        </div></div></div>
        <div class="d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="transform-form-save-btn"><?= $editing ? 'Save' : 'Save and freeze' ?></button>
            <?= hx_link($editing ? '/transforms/' . $id : '/transforms', 'Cancel', 'btn btn-light btn-touch', 'id="transform-form-cancel-btn"') ?>
        </div>
    </form>
</div>
