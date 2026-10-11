<?php
/**
 * To grant (screen `grant-add`): the target (a dataset — the picker; a zone; a tag — the admin's only, ★14), who (the kind, then the picker
 * for it; everyone has none), the level (what the database admits for the kind), the row filter (a dataset's grant only; Check runs the dry
 * run), the expiry, the reason. Data: v, admin, columns, dataset_key, errors
 */
$f = static fn (string $n): string => 'grant-form-field-' . $n;
$err = static fn (string $n): string => isset($errors[$n]) ? '<div class="invalid-feedback d-block" id="grant-form-error-' . e($n) . '">' . e($errors[$n]) . '</div>' : '';
?>
<?= view('shared/page-header.php', ['id' => 'grant-form', 'title' => 'Add a grant',
    'crumbs' => [['Home', '/'], ['Access', '/access'], ['Grants', '/access/grants'], ['Add a grant', null]],
    'back' => $v['dataset'] !== null ? ['/datasets/' . (int) $v['dataset'] . '?tab=access', (string) ($dataset_key ?? 'the dataset')] : ['/access/grants', 'Grants']]) ?>
<div class="main-content" id="grant-form-content">
    <form method="post" action="/access/grants/save.php" hx-post="/access/grants/save.php" hx-target="#flash" id="grant-form" class="page-header-form">
        <?= csrf_field() ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="<?= $f('target') ?>">On</label>
                <select class="form-select btn-touch" id="<?= $f('target') ?>" name="target"<?= $admin ? '' : ' disabled' ?>>
                    <option value="dataset"<?= $v['target'] === 'dataset' ? ' selected' : '' ?>>A dataset</option>
                    <?php if ($admin): ?><option value="zone"<?= $v['target'] === 'zone' ? ' selected' : '' ?>>A zone (every dataset in it)</option>
                    <option value="tag"<?= $v['target'] === 'tag' ? ' selected' : '' ?>>A tag (every dataset carrying it)</option><?php endif; ?>
                </select><?php if (!$admin): ?><input type="hidden" name="target" value="dataset"><div class="form-text">You grant on your own datasets; a zone or a tag is the admin's.</div><?php endif; ?></div>
            <div class="col-md-8" id="grant-form-target-dataset"><label class="form-label" id="grant-form-dataset-label" for="<?= $f('dataset') ?>">Dataset</label>
                <?= picker_field(['id' => $f('dataset'), 'name' => 'dataset', 'source' => 'dataset', 'value' => $v['dataset'], 'labelledby' => 'grant-form-dataset-label', 'title' => 'The dataset',
                    'placeholder' => 'Choose a dataset (for a dataset grant)', 'extra' => ' hx-get="/access/grants/new" hx-trigger="change" hx-target="#grant-form-row-filter-field" hx-select="#grant-form-row-filter-field" hx-swap="outerHTML" hx-include="this"']) ?>
                <?= $err('dataset') ?></div>
            <?php if ($admin): ?>
                <div class="col-md-6"><label class="form-label" for="<?= $f('zone') ?>">Zone (for a zone grant)</label>
                    <select class="form-select btn-touch" id="<?= $f('zone') ?>" name="zone"><option value="">—</option>
                        <?php foreach (GRANT_ZONES as $z): ?><option value="<?= e($z) ?>"<?= $v['zone'] === $z ? ' selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select><?= $err('zone') ?></div>
                <div class="col-md-6"><label class="form-label" id="grant-form-tag-label" for="<?= $f('tag') ?>">Tag (for a tag grant)</label>
                    <?= picker_field(['id' => $f('tag'), 'name' => 'tag', 'source' => 'tag', 'value' => $v['tag'], 'labelledby' => 'grant-form-tag-label', 'title' => 'The tag']) ?><?= $err('tag') ?></div>
            <?php endif; ?>
            <div class="col-md-4"><label class="form-label" for="<?= $f('principal_kind') ?>">To</label>
                <select class="form-select btn-touch" id="<?= $f('principal_kind') ?>" name="principal_kind" hx-get="/access/grants/new?fragment=who" hx-trigger="change" hx-target="#grant-form-who" hx-swap="outerHTML" hx-include="this">
                    <?php foreach (PRINCIPAL_KINDS as $k => $label): ?><option value="<?= e($k) ?>"<?= $v['principal_kind'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-8"><?= view('access/partials/grant-who.php', ['v' => $v]) ?><?= $err('principal') ?><?= $err('level') ?></div>
            <div class="col-12"><?= view('access/partials/row-filter-input.php', ['value' => $v['row_filter'], 'columns' => $columns, 'dataset_key' => $dataset_key, 'error' => $errors['row_filter'] ?? null, 'check' => null]) ?></div>
            <div class="col-md-4"><label class="form-label" for="<?= $f('until') ?>">Until (optional)</label>
                <input type="date" class="form-control btn-touch" id="<?= $f('until') ?>" name="until" value="<?= e((string) $v['until']) ?>" min="<?= e(date('Y-m-d', time() + 86400)) ?>"><?= $err('until') ?></div>
            <div class="col-md-8"><label class="form-label" for="<?= $f('reason') ?>">Reason</label>
                <input type="text" class="form-control btn-touch" id="<?= $f('reason') ?>" name="reason" value="<?= e((string) $v['reason']) ?>" maxlength="2000" placeholder="Sales reviews its own orders">
                <div class="form-text">Kept on the grant and its trail.</div></div>
        </div></div></div>
        <div class="d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="grant-form-save-btn">Grant</button>
            <?= hx_link($v['dataset'] !== null ? '/datasets/' . (int) $v['dataset'] . '?tab=access' : '/access/grants', 'Cancel', 'btn btn-light btn-touch', 'id="grant-form-cancel-btn"') ?>
        </div>
    </form>
</div>
