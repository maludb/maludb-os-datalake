<?php
/** A dataset's checks (screen `checks`): the table (check-row-{id}) with each check's last result and 7-cell strip; Add check; Run all. Data: d, checks, strips, may, notice */
$key = (string) $d['key'];
$id = (int) $d['id'];
$here = '/datasets/' . $key . '/checks';
?>
<?= view('shared/page-header.php', ['id' => 'checks', 'title' => 'Checks — ' . $key, 'crumbs' => [['Home', '/'], ['Browse', '/browse'], [$key, '/datasets/' . $key . '?tab=quality'], ['Checks', null]],
    'action' => $may['manage'] ? hx_link('/datasets/' . $key . '/checks/new', '<i class="feather-plus me-1"></i>Add check', 'btn btn-primary btn-touch', 'id="checks-add-btn"') : '']) ?>
<div class="main-content" id="checks-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($may['run']): ?>
        <form method="post" action="/quality/checks/run.php" hx-post="/quality/checks/run.php" hx-target="#flash" class="mb-3" id="checks-run-form">
            <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <button type="submit" class="btn btn-light btn-touch" id="checks-run-all"><i class="feather-play me-1"></i>Run all now</button>
        </form>
    <?php endif; ?>
    <?php if ($checks === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="checks-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-check-square"></i></span>
            <div><div class="fw-semibold">No checks yet</div><div class="fs-12 text-muted">A check is a rule the data must keep: not null, unique, a range, a row count, freshness… A blocking one quarantines the rows that fail it.</div></div></div></div></div>
    <?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table table-hover mb-0 align-middle fs-12" id="checks-table">
            <thead><tr><th>Check</th><th class="d-none d-lg-table-cell">Dimension</th><th>Severity</th><th class="d-none d-md-table-cell">When</th><th>Last</th><th>Last 7</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($checks as $c): $cid = (int) $c['check_id']; $strip = $strips[$cid] ?? []; $lastS = $strip === [] ? null : end($strip); $b = result_badge($lastS, (string) $c['severity']); ?>
                <tr id="check-row-<?= $cid ?>"<?= $c['enabled'] ? '' : ' class="text-muted"' ?>>
                    <td><div class="fw-semibold"><?= e(CHECK_KINDS[$c['kind']]['label'] ?? $c['kind']) ?><?= $c['blocking'] ? ' <span class="badge bg-dark ms-1">Blocking</span>' : '' ?><?= $c['enabled'] ? '' : ' <span class="badge bg-light text-muted ms-1">Off</span>' ?><?= $c['source'] === 'agent' ? ' <span class="badge bg-info ms-1">Agent</span>' : '' ?></div>
                        <div><?= e(check_words($c)) ?></div><?php if ($c['description']): ?><div class="fs-11 text-muted"><?= e($c['description']) ?></div><?php endif; ?></td>
                    <td class="d-none d-lg-table-cell"><?= e($c['dimension']) ?></td>
                    <td><span class="badge <?= e(severity_badge((string) $c['severity'])[0]) ?>"><?= e(severity_badge((string) $c['severity'])[1]) ?></span></td>
                    <td class="d-none d-md-table-cell"><?= e(CHECK_WHEN[$c['run_when']] ?? $c['run_when']) ?></td>
                    <td><span class="badge <?= e($b[0]) ?>" id="check-last-<?= $cid ?>"><?= e($b[1]) ?></span><?php if ($lastS !== null && $lastS['value'] !== null): ?><div class="fs-11 text-muted">value <?= e(check_num($lastS['value'])) ?></div><?php endif; ?></td>
                    <td><?= view('checks/partials/strip.php', ['strip' => $strip, 'id' => 'check-strip-' . $cid]) ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($may['manage']): ?><?= hx_link('/checks/' . $cid . '/edit', 'Edit', 'btn btn-light btn-sm btn-touch', 'id="check-edit-' . $cid . '"') ?><?php endif; ?>
                        <?php if ($may['run'] && $c['enabled']): ?>
                            <form method="post" action="/quality/checks/run.php" hx-post="/quality/checks/run.php" hx-target="#flash" class="d-inline" id="check-run-<?= $cid ?>-form">
                                <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="check" value="<?= $cid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                                <button type="submit" class="btn btn-light btn-sm btn-touch" id="check-run-<?= $cid ?>">Run</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    <?php endif; ?>
</div>
