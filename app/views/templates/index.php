<?php
/** Saved mappings (screen `templates`): cards (template-card-{id}) with an Edit form in a <details> (the name, the kind, the mapping as a table) and Delete. Data: rows, q, notice */
?>
<?= view('shared/page-header.php', ['id' => 'templates', 'title' => 'Saved mappings', 'crumbs' => [['Home', '/'], ['Upload', '/upload'], ['Saved mappings', null]], 'back' => ['/upload', 'Upload']]) ?>
<div class="main-content" id="templates-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/templates" class="mb-3 d-flex gap-2" id="templates-search" hx-get="/templates" hx-target="#page-content" hx-push-url="true">
        <input type="search" class="form-control btn-touch" name="q" value="<?= e($q) ?>" placeholder="Find a mapping by name" id="templates-q" aria-label="Find a mapping by name">
        <button type="submit" class="btn btn-light btn-touch" id="templates-search-btn">Find</button></form>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="templates-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-copy"></i></span>
            <div><div class="fw-semibold">No saved mappings<?= $q !== '' ? ' match' : ' yet' ?></div><div class="fs-12 text-muted">On the mapping screen, "Save this mapping as" keeps one for next month's file.</div></div></div></div></div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-lg-2 g-2" id="templates-cards">
        <?php foreach ($rows as $t): $id = (int) $t['template_id']; $cols = (array) ($t['mapping']['columns'] ?? []); $may = may_manage_template($t); ?>
            <div class="col"><div class="card mb-0" id="template-card-<?= $id ?>"><div class="card-body">
                <div class="d-flex align-items-start gap-2"><div class="flex-grow-1 min-w-0"><div class="fw-semibold text-break"><?= e($t['name']) ?></div>
                    <div class="fs-11 text-muted"><?= e(TEMPLATE_KINDS[$t['kind']] ?? $t['kind']) ?> · <?= count($cols) ?> column<?= count($cols) === 1 ? '' : 's' ?><?= ($t['mapping']['sheet'] ?? null) ? ' · sheet ' . e((string) $t['mapping']['sheet']) : '' ?> · made by <?= e((string) ($t['created_by_name'] ?? 'someone')) ?></div></div>
                    <?php if ($may): ?>
                        <form method="post" action="/templates/delete.php" hx-post="/templates/delete.php" hx-target="#flash" hx-confirm="Delete the mapping <?= e($t['name']) ?>?">
                            <?= csrf_field() ?><input type="hidden" name="template" value="<?= $id ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-touch" id="template-card-<?= $id ?>-delete"><i class="feather-trash-2"></i><span class="visually-hidden">Delete</span></button></form>
                    <?php endif; ?></div>
                <?php if ($may): ?>
                <details class="mt-2" id="template-card-<?= $id ?>-edit"><summary class="fs-12 fw-semibold">Edit</summary>
                    <form method="post" action="/templates/save.php" hx-post="/templates/save.php" hx-target="#flash" class="mt-2" id="template-form-<?= $id ?>">
                        <?= csrf_field() ?><input type="hidden" name="template" value="<?= $id ?>">
                        <div class="row g-2 mb-2"><div class="col-8"><label class="form-label fs-12 mb-1" for="template-<?= $id ?>-name">Name</label>
                            <input type="text" class="form-control btn-touch" id="template-<?= $id ?>-name" name="name" value="<?= e($t['name']) ?>" maxlength="120" required></div>
                            <div class="col-4"><label class="form-label fs-12 mb-1" for="template-<?= $id ?>-kind">Kind</label>
                            <select class="form-select btn-touch" id="template-<?= $id ?>-kind" name="kind"><?php foreach (TEMPLATE_KINDS as $k => $w): ?><option value="<?= e($k) ?>"<?= $k === $t['kind'] ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div></div>
                        <div class="table-responsive"><table class="table table-sm fs-12 mb-2"><thead><tr><th>In the file</th><th>Name</th><th>Type</th><th>Personal</th><th>Skip</th></tr></thead><tbody>
                        <?php $i = 0; foreach ($cols as $src => $c): ?>
                            <tr><td class="text-break"><?= e((string) $src) ?><input type="hidden" name="rows[<?= $i ?>][source]" value="<?= e((string) $src) ?>"></td>
                                <td><input type="text" class="form-control form-control-sm" name="rows[<?= $i ?>][name]" value="<?= e((string) ($c['name'] ?? '')) ?>" aria-label="Name of <?= e((string) $src) ?>"></td>
                                <td><select class="form-select form-select-sm" name="rows[<?= $i ?>][type]" aria-label="Type of <?= e((string) $src) ?>"><?php foreach (MAP_TYPES as $v => $w): ?><option value="<?= e($v) ?>"<?= $v === ($c['type'] ?? 'VARCHAR') ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></td>
                                <td><select class="form-select form-select-sm" name="rows[<?= $i ?>][personal_kind]" aria-label="Personal data in <?= e((string) $src) ?>"><option value="">—</option><?php foreach (PERSONAL_KINDS as $v => $w): ?><option value="<?= e($v) ?>"<?= $v === ($c['personal_kind'] ?? '') ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></td>
                                <td><input type="hidden" name="rows[<?= $i ?>][skip]" value="0"><input class="form-check-input" type="checkbox" name="rows[<?= $i ?>][skip]" value="1"<?= !empty($c['skip']) ? ' checked' : '' ?> aria-label="Skip <?= e((string) $src) ?>"></td></tr>
                        <?php $i++; endforeach; ?>
                        </tbody></table></div>
                        <button type="submit" class="btn btn-primary btn-touch btn-sm" id="template-form-<?= $id ?>-save">Save</button>
                    </form></details>
                <?php endif; ?>
            </div></div></div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
