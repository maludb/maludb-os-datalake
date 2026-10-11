<?php
/**
 * The masks (screen `policies`): fifteen rows — what it applies to, the mask (a select), an example of the masked form, the note; Save per
 * row (policies.manage; pauses for an agent). Below read_unmasked the query service applies them from every reader's next query. Data: rows, may_edit, notice
 */
?>
<?= view('shared/page-header.php', ['id' => 'policies', 'title' => 'Masks', 'crumbs' => [['Home', '/'], ['Access', '/access'], ['Masks', null]]]) ?>
<div class="main-content" id="policies-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('access/partials/subnav.php', ['active' => 'policies']) ?>
    <p class="fs-12 text-muted">Below <strong>read unmasked</strong> a column is shown masked: by its personal kind when it has one, else by its classification. A change applies to every reader from their next query.</p>
    <div class="card"><div class="table-responsive"><table class="table mb-0 align-middle" id="policies-table">
        <thead><tr><th>Applies to</th><th>Mask</th><th>Looks like</th><th class="d-none d-md-table-cell">Note</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): $a = (string) $p['applies_to']; $fid = 'policy-' . $a; ?>
            <tr id="policy-row-<?= e($a) ?>">
                <td><div class="fw-semibold"><?= e(MASK_SUBJECTS[$a] ?? $a) ?></div><div class="fs-11 text-muted font-monospace"><?= e($a) ?></div></td>
                <td><?php if ($may_edit): ?>
                    <form method="post" action="/access/policies/save.php" hx-post="/access/policies/save.php" hx-target="#flash" id="<?= e($fid) ?>-form" class="d-flex gap-2">
                        <?= csrf_field() ?><input type="hidden" name="applies_to" value="<?= e($a) ?>">
                        <select class="form-select form-select-sm btn-touch" name="mask" id="<?= e($fid) ?>-mask" aria-label="The mask for <?= e(MASK_SUBJECTS[$a] ?? $a) ?>">
                            <?php foreach (MASKS as $m => [$label, $ex]): ?><option value="<?= e($m) ?>"<?= $m === $p['mask'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-light btn-sm btn-touch" id="<?= e($fid) ?>-save">Save</button>
                    </form>
                <?php else: ?><?= e(MASKS[$p['mask']][0] ?? $p['mask']) ?><?php endif; ?></td>
                <td class="font-monospace fs-12" id="<?= e($fid) ?>-example"><?= e(MASKS[$p['mask']][1] ?? '') ?></td>
                <td class="fs-12 text-muted d-none d-md-table-cell"><?= e((string) ($p['note'] ?? '')) ?></td>
                <td class="fs-11 text-muted"><?= e(format_ts((string) $p['updated_at'], member_timezone(), 'M j, Y')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>
