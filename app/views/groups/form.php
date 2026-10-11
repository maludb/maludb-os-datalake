<?php
/** A group's form (screens `group-add` and `group-edit`): name, key (made from the name), description; on add the kind — custom, or mirroring a department (the department picker; one live mirror per department). Data: g (null on add), v, errors */
$editing = $g !== null;
$id = $editing ? (int) $g['group_id'] : null;
$f = static fn (string $n): string => 'group-form-field-' . $n;
$err = static fn (string $n): string => isset($errors[$n]) ? '<div class="invalid-feedback d-block" id="group-form-error-' . e($n) . '">' . e($errors[$n]) . '</div>' : '';
?>
<?= view('shared/page-header.php', ['id' => 'group-form', 'title' => $editing ? 'Edit ' . $g['name'] : 'New group',
    'crumbs' => $editing ? [['Groups', '/groups'], [(string) $g['name'], '/groups/' . $id], ['Edit', null]] : [['Groups', '/groups'], ['New group', null]],
    'back' => $editing ? ['/groups/' . $id, (string) $g['name']] : ['/groups', 'Groups']]) ?>
<div class="main-content" id="group-form-content">
    <form method="post" action="/groups/save.php" hx-post="/groups/save.php" hx-target="#flash" id="group-form" class="page-header-form">
        <?= csrf_field() ?><?php if ($editing): ?><input type="hidden" name="group" value="<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <?php if (!$editing): ?>
                <div class="col-md-6"><label class="form-label" for="<?= $f('kind') ?>">Kind</label>
                    <select class="form-select btn-touch" id="<?= $f('kind') ?>" name="kind">
                        <?php foreach (GROUP_KINDS as $k => $label): ?><option value="<?= e($k) ?>"<?= $v['kind'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                    <div class="form-text">A department's group follows the directory: who joins or leaves the department joins or leaves the group.</div></div>
                <div class="col-md-6"><label class="form-label" id="group-form-department-label" for="<?= $f('department') ?>">Department (for a mirror)</label>
                    <?= picker_field(['id' => $f('department'), 'name' => 'department', 'source' => 'department', 'value' => $v['department_id'], 'labelledby' => 'group-form-department-label', 'title' => 'The department']) ?><?= $err('department') ?></div>
            <?php endif; ?>
            <div class="col-12"><label class="form-label" for="<?= $f('name') ?>">Name</label>
                <input type="text" class="form-control btn-touch" id="<?= $f('name') ?>" name="name" value="<?= e((string) $v['name']) ?>" maxlength="120" placeholder="Finance analysts"<?= $editing ? ' required' : '' ?>>
                <?= $err('name') ?><?php if (!$editing): ?><div class="form-text">A mirror takes the department's name when left empty.</div><?php endif; ?></div>
            <?php if (!$editing): ?>
                <div class="col-md-6"><label class="form-label" for="<?= $f('key') ?>">Key (optional)</label>
                    <input type="text" class="form-control btn-touch font-monospace" id="<?= $f('key') ?>" name="key" value="<?= e((string) $v['key']) ?>" maxlength="60" pattern="[a-z0-9][a-z0-9_\-]{0,59}" placeholder="finance-analysts">
                    <?= $err('key') ?><div class="form-text">Made from the name when empty; it never changes.</div></div>
            <?php endif; ?>
            <div class="col-12"><label class="form-label" for="<?= $f('description') ?>">Description</label>
                <textarea class="form-control" id="<?= $f('description') ?>" name="description" rows="3" maxlength="2000"><?= e((string) ($v['description'] ?? '')) ?></textarea></div>
        </div></div></div>
        <div class="d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="group-form-save-btn"><?= $editing ? 'Save' : 'Make the group' ?></button>
            <?= hx_link($editing ? '/groups/' . $id : '/groups', 'Cancel', 'btn btn-light btn-touch', 'id="group-form-cancel-btn"') ?>
        </div>
    </form>
</div>
