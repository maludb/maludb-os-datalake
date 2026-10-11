<?php
/**
 * The check form (screens `check-add`, `check-edit`): no kind yet → the template cards; a kind → its fields. A plain form (it works without
 * JavaScript); field errors under each field (check-form-field-{name}). Data: d, check, kind, columns, v, errors, agent
 */
$key = (string) $d['key'];
$isEdit = $check !== null;
$t = $kind === null ? null : CHECK_KINDS[$kind];
$err = static fn (string $n): string => isset($errors[$n]) ? '<div class="invalid-feedback d-block" id="check-form-field-' . e($n) . '-error">' . e($errors[$n]) . '</div>' : '';
$back = '/datasets/' . $key . '/checks';
?>
<?= view('shared/page-header.php', ['id' => 'check-form', 'title' => ($isEdit ? 'Edit a check' : 'Add a check') . ' — ' . $key,
    'crumbs' => [['Home', '/'], ['Browse', '/browse'], [$key, '/datasets/' . $key . '?tab=quality'], ['Checks', $back], [$isEdit ? 'Edit' : 'Add', null]]]) ?>
<div class="main-content" id="check-form-content">
<?php if ($t === null): ?>
    <p class="fs-13 text-muted">Choose what the check asks of <span class="font-monospace"><?= e($key) ?></span>:</p>
    <?= view('checks/partials/template-cards.php', ['key' => $key, 'column' => $v['column']]) ?>
<?php else: ?>
    <form method="post" action="/quality/checks/save.php" hx-post="/quality/checks/save.php" hx-target="#flash" class="card" id="check-form-form" novalidate><div class="card-body">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="check" value="<?= (int) $check['check_id'] ?>"><?php else: ?><input type="hidden" name="dataset" value="<?= (int) $d['id'] ?>"><?php endif; ?>
        <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
        <div class="d-flex align-items-center gap-2 mb-3"><i class="<?= e($t['icon']) ?> fs-4" aria-hidden="true"></i><div><div class="fw-semibold"><?= e($t['label']) ?></div>
            <div class="fs-12 text-muted"><?= e($t['what']) ?></div></div>
            <?php if (!$isEdit): ?><?= hx_link('/datasets/' . $key . '/checks/new', 'Another kind', 'btn btn-light btn-sm btn-touch ms-auto', 'id="check-form-other-kind"') ?><?php endif; ?></div>
        <?php if ($t['column']): ?>
            <div class="mb-3" id="check-form-field-column"><label class="form-label" for="check-form-column">Column</label>
                <select class="form-select btn-touch<?= isset($errors['column']) ? ' is-invalid' : '' ?>" id="check-form-column" name="column"<?= $isEdit ? '' : '' ?>>
                    <option value="">Choose a column</option>
                    <?php foreach ($columns as $c => $type): ?><option value="<?= e($c) ?>"<?= $v['column'] === $c ? ' selected' : '' ?>><?= e($c) ?> (<?= e($type) ?>)</option><?php endforeach; ?>
                </select><?= $err('column') ?></div>
        <?php endif; ?>
        <?php if (is_file(dirname(__DIR__) . '/checks/partials/params-' . $kind . '.php')): ?>
            <?= view('checks/partials/params-' . $kind . '.php', ['p' => (array) $v['params'], 'err' => $err, 'errors' => $errors, 'd' => $d]) ?>
        <?php endif; ?>
        <div class="row g-2">
            <div class="col-6 col-md-3" id="check-form-field-dimension"><label class="form-label" for="check-form-dimension">Dimension</label>
                <select class="form-select btn-touch" id="check-form-dimension" name="dimension">
                    <?php foreach (CHECK_DIMENSIONS as $dim): ?><option value="<?= e($dim) ?>"<?= $v['dimension'] === $dim ? ' selected' : '' ?>><?= e(ucfirst($dim)) ?></option><?php endforeach; ?></select><?= $err('dimension') ?></div>
            <div class="col-6 col-md-3" id="check-form-field-severity"><label class="form-label" for="check-form-severity">Severity</label>
                <select class="form-select btn-touch" id="check-form-severity" name="severity">
                    <option value="error"<?= $v['severity'] === 'error' ? ' selected' : '' ?>>Error</option><option value="warn"<?= $v['severity'] === 'warn' ? ' selected' : '' ?>>Warn</option></select><?= $err('severity') ?></div>
            <div class="col-6 col-md-3" id="check-form-field-run_when"><label class="form-label" for="check-form-run-when">When</label>
                <select class="form-select btn-touch" id="check-form-run-when" name="run_when">
                    <?php foreach (CHECK_WHEN as $w => $label): ?><option value="<?= e($w) ?>"<?= $v['run_when'] === $w ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><?= $err('run_when') ?></div>
            <?php if (!$agent): ?>
                <div class="col-6 col-md-3 d-flex align-items-end" id="check-form-field-blocking"><div class="form-check mb-2">
                    <input type="hidden" name="blocking" value="0"><input class="form-check-input" type="checkbox" id="check-form-blocking" name="blocking" value="1"<?= $v['blocking'] ? ' checked' : '' ?>>
                    <label class="form-check-label" for="check-form-blocking">Blocking<span class="d-block fs-11 text-muted"><?= in_array($kind, CHECK_ROW_KINDS, true) ? 'failing rows are quarantined' : 'counts toward certification' ?></span></label></div><?= $err('blocking') ?></div>
            <?php endif; ?>
        </div>
        <div class="mt-3" id="check-form-field-description"><label class="form-label" for="check-form-description">Why (optional)</label>
            <input type="text" class="form-control" id="check-form-description" name="description" maxlength="2000" value="<?= e((string) $v['description']) ?>"><?= $err('description') ?></div>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <button type="submit" class="btn btn-primary btn-touch" id="check-form-save"><?= $isEdit ? 'Save' : 'Add the check' ?></button>
            <?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="check-form-cancel"') ?>
        </div>
    </div></form>
    <?php if ($isEdit): ?>
        <div class="d-flex flex-wrap gap-2 mt-3" id="check-form-more">
            <form method="post" action="/quality/checks/disable.php" hx-post="/quality/checks/disable.php" hx-target="#flash" id="check-form-toggle-form">
                <?= csrf_field() ?><input type="hidden" name="check" value="<?= (int) $check['check_id'] ?>"><input type="hidden" name="enable" value="<?= $check['enabled'] ? '0' : '1' ?>">
                <input type="hidden" name="return_to" value="<?= e($back) ?>">
                <button type="submit" class="btn btn-light btn-touch" id="check-form-toggle"><?= $check['enabled'] ? 'Turn off' : 'Turn back on' ?></button></form>
            <form method="post" action="/quality/checks/delete.php" hx-post="/quality/checks/delete.php" hx-target="#flash" hx-confirm="Delete this check and its results?" id="check-form-delete-form">
                <?= csrf_field() ?><input type="hidden" name="check" value="<?= (int) $check['check_id'] ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                <button type="submit" class="btn btn-outline-danger btn-touch" id="check-form-delete">Delete</button></form>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div>
