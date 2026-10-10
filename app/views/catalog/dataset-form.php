<?php
/**
 * A dataset's form (screens `dataset-add` and `dataset-edit`; catalog.md). Adding: the zone (curated or product — divergence 2), the name
 * with a live preview of the key (the database's refusal is named on the field), the title, description, owner (the caller by default),
 * steward, department, tier. Editing: what it is (title, description, purpose, usage, limitations), who keeps it (owner, steward,
 * department), the expectations (tier, freshness warn and fail after, schema evolution), the status (draft / active), and — a separate form
 * and action — the deprecation (a note and a successor). Technical metadata has no field: the writer records it. Data: d (null on add), v, may
 */
$editing = $d !== null;
$id = $editing ? (int) $d['dataset_id'] : null;
$field = static fn (string $n): string => 'dataset-form-field-' . $n;
$ta = static fn (string $n, string $label, int $max, int $rows, string $help = '') => '<div class="col-12"><label class="form-label" for="' . $field($n) . '">' . e($label) . '</label>'
    . '<textarea class="form-control" id="' . $field($n) . '" name="' . $n . '" rows="' . $rows . '" maxlength="' . $max . '">' . e((string) ($v[$n] ?? '')) . '</textarea>'
    . ($help !== '' ? '<div class="form-text">' . e($help) . '</div>' : '') . '</div>';
$interval = static function (string $n, string $label) use ($v, $field): string {
    [$num, $unit] = $v[$n . '_parts'];
    return '<div class="col-md-6"><label class="form-label" for="' . $field($n) . '-n">' . e($label) . '</label><div class="input-group">'
        . '<input type="number" class="form-control btn-touch" id="' . $field($n) . '-n" name="' . $n . '_n" min="1" max="8760" value="' . e((string) ($num ?? '')) . '" placeholder="none">'
        . '<select class="form-select btn-touch" id="' . $field($n) . '-unit" name="' . $n . '_unit" style="max-width: 9rem;">'
        . '<option value="hours"' . ($unit === 'hours' ? ' selected' : '') . '>hours</option><option value="days"' . ($unit === 'days' ? ' selected' : '') . '>days</option></select></div></div>';
};
?>
<?= view('shared/page-header.php', ['id' => 'dataset-form', 'title' => $editing ? 'Edit ' . $d['title'] : 'New dataset',
    'crumbs' => $editing ? [['Browse', '/browse'], [$d['key'], '/datasets/' . $id], ['Edit', null]] : [['Browse', '/browse'], ['New dataset', null]],
    'back' => $editing ? ['/datasets/' . $id, $d['title']] : ['/browse', 'Browse']]) ?>
<div class="main-content" id="dataset-form-content">
    <form method="post" action="/datasets/save.php" hx-post="/datasets/save.php" hx-target="#flash" id="dataset-form" class="page-header-form">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="return_to" value="/datasets/<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <?php if (!$editing): ?>
                <div class="col-md-4"><label class="form-label" for="<?= $field('zone') ?>">Zone</label>
                    <select class="form-select btn-touch" id="<?= $field('zone') ?>" name="zone" hx-get="/datasets/new?key_preview=1" hx-trigger="change" hx-target="#dataset-form-key-preview" hx-swap="outerHTML" hx-include="#dataset-form-field-zone, #dataset-form-field-name">
                        <option value="curated"<?= $v['zone'] === 'curated' ? ' selected' : '' ?>>curated — typed and checked</option>
                        <option value="product"<?= $v['zone'] === 'product' ? ' selected' : '' ?>>product — owned, certified, for everyone</option>
                    </select>
                    <div class="form-text">A raw dataset is made by a load; a sandbox one by its owner.</div></div>
                <div class="col-md-8"><label class="form-label" for="<?= $field('name') ?>">Name</label>
                    <input type="text" class="form-control btn-touch" id="<?= $field('name') ?>" name="name" value="<?= e((string) $v['name']) ?>" maxlength="63" required autofocus
                           pattern="[a-z][a-z0-9_]{0,62}" autocomplete="off" placeholder="sales_orders"
                           hx-get="/datasets/new?key_preview=1" hx-trigger="keyup changed delay:300ms, change" hx-target="#dataset-form-key-preview" hx-swap="outerHTML" hx-include="#dataset-form-field-zone, #dataset-form-field-name">
                    <?= view('catalog/key-preview.php', ['zone' => $v['zone'], 'name' => (string) $v['name'], 'state' => null]) ?></div>
            <?php else: ?>
                <div class="col-12"><div class="fs-12 text-muted">Key</div><div class="dataset-key" id="dataset-form-key"><?= e($d['key']) ?></div><div class="form-text">A key never changes — make a new dataset and deprecate this one.</div></div>
            <?php endif; ?>
            <div class="col-12"><label class="form-label" for="<?= $field('title') ?>">Title</label>
                <input type="text" class="form-control btn-touch" id="<?= $field('title') ?>" name="title" value="<?= e((string) $v['title']) ?>" maxlength="200" required></div>
            <?= $ta('description', 'Description', 8000, 4, 'What one row is, where it comes from, what it is for. Search reads it.') ?>
            <?php if ($editing): ?>
                <?= $ta('purpose', 'Purpose', 4000, 2) ?>
                <?= $ta('usage', 'How to use it', 4000, 2) ?>
                <?= $ta('limitations', 'Limitations', 4000, 2) ?>
            <?php endif; ?>
        </div></div></div>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <h6 class="fs-12 text-uppercase text-muted mb-0">Who keeps it</h6>
            <?php if (!$editing || $d['zone'] !== 'sandbox'): ?>
                <div class="col-md-4"><label class="form-label" id="dataset-form-owner-label" for="<?= $field('owner') ?>">Owner</label>
                    <?= picker_field(['id' => $field('owner'), 'name' => 'owner', 'source' => 'member', 'value' => $v['owner_member_id'], 'labelledby' => 'dataset-form-owner-label', 'title' => 'The owner', 'placeholder' => 'Nobody yet']) ?>
                    <div class="form-text">Decides who may read it.</div></div>
            <?php endif; ?>
            <div class="col-md-4"><label class="form-label" id="dataset-form-steward-label" for="<?= $field('steward') ?>">Steward</label>
                <?= picker_field(['id' => $field('steward'), 'name' => 'steward', 'source' => 'steward', 'value' => $v['steward_member_id'], 'labelledby' => 'dataset-form-steward-label', 'title' => 'The steward', 'placeholder' => 'None']) ?>
                <div class="form-text">Keeps its description and quality.</div></div>
            <div class="col-md-4"><label class="form-label" id="dataset-form-department-label" for="<?= $field('department') ?>">Department</label>
                <?= picker_field(['id' => $field('department'), 'name' => 'department', 'source' => 'department', 'value' => $v['department_id'], 'labelledby' => 'dataset-form-department-label', 'title' => 'The department', 'placeholder' => 'None']) ?></div>
        </div></div></div>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <h6 class="fs-12 text-uppercase text-muted mb-0">Expectations</h6>
            <div class="col-md-6"><label class="form-label" for="<?= $field('tier') ?>">Tier</label>
                <select class="form-select btn-touch" id="<?= $field('tier') ?>" name="tier">
                    <?php foreach (DATASET_TIERS as $k => $label): if ($k === 'unused' && $v['tier'] !== 'unused') { continue; } ?>
                        <option value="<?= e($k) ?>"<?= (string) $v['tier'] === (string) $k ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <?php if ($editing): ?>
                <div class="col-md-6"><label class="form-label" for="<?= $field('status') ?>">Status</label>
                    <select class="form-select btn-touch" id="<?= $field('status') ?>" name="status">
                        <?php if ($v['status'] === 'deprecated'): ?><option value="" selected>Deprecated — choose Active to bring it back</option><?php endif; ?>
                        <option value="draft"<?= $v['status'] === 'draft' ? ' selected' : '' ?>>Draft — being set up</option>
                        <option value="active"<?= $v['status'] === 'active' ? ' selected' : '' ?>>Active</option>
                    </select></div>
                <?= $interval('freshness_warn_after', 'Warn when no new data for') ?>
                <?= $interval('freshness_error_after', 'Fail when no new data for') ?>
                <div class="col-12"><span class="form-label d-block" id="dataset-form-evolution-label">When a load brings new columns</span>
                    <?php foreach (EVOLUTION_MODES as $k => $line): ?>
                        <div class="form-check"><input class="form-check-input" type="radio" name="evolution_mode" value="<?= e($k) ?>" id="<?= $field('evolution_mode') ?>-<?= e($k) ?>"<?= $v['evolution_mode'] === $k ? ' checked' : '' ?>>
                            <label class="form-check-label" for="<?= $field('evolution_mode') ?>-<?= e($k) ?>"><?= e($line) ?></label></div>
                    <?php endforeach; ?></div>
            <?php endif; ?>
        </div></div></div>
        <div class="d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="dataset-form-save-btn"><?= $editing ? 'Save' : 'Register the dataset' ?></button>
            <?= hx_link($editing ? '/datasets/' . $id : '/browse', 'Cancel', 'btn btn-light btn-touch', 'id="dataset-form-cancel-btn"') ?>
        </div>
    </form>
    <?php if ($editing && $may['deprecate']): ?>
        <div class="card border-warning mb-3" id="dataset-deprecate"><div class="card-body">
            <h6 class="mb-1">Deprecate</h6>
            <?php if ($d['status'] === 'deprecated'): ?>
                <p class="fs-12 text-muted mb-2">It is deprecated: <?= e(mb_substr((string) $d['deprecation_note'], 0, 300)) ?>. Change the note or the successor here; set the status to Active above to bring it back.</p>
            <?php else: ?>
                <p class="fs-12 text-muted mb-2">Its followers are told; its card is greyed with the dataset to use instead. Nothing is deleted.</p>
            <?php endif; ?>
            <form method="post" action="/datasets/deprecate.php" hx-post="/datasets/deprecate.php" hx-target="#flash" id="dataset-deprecate-form"
                  hx-confirm="Deprecate <?= e($d['key']) ?>? Its followers are told.">
                <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>">
                <div class="mb-2"><label class="form-label" for="dataset-deprecate-field-note">Why, and what to use instead</label>
                    <textarea class="form-control" id="dataset-deprecate-field-note" name="note" rows="2" maxlength="2000" required><?= e((string) ($d['deprecation_note'] ?? '')) ?></textarea></div>
                <div class="mb-2"><label class="form-label" id="dataset-deprecate-successor-label" for="dataset-deprecate-field-successor">Successor</label>
                    <?= picker_field(['id' => 'dataset-deprecate-field-successor', 'name' => 'successor', 'source' => 'dataset', 'value' => $d['successor']['id'] ?? null, 'params' => ['except' => $id],
                        'labelledby' => 'dataset-deprecate-successor-label', 'title' => 'The dataset to use instead', 'placeholder' => 'None']) ?></div>
                <button type="submit" class="btn btn-warning btn-touch" id="dataset-deprecate-btn"><?= $d['status'] === 'deprecated' ? 'Save the deprecation' : 'Deprecate' ?></button>
            </form>
        </div></div>
    <?php endif; ?>
</div>
