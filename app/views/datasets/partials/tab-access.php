<?php
/**
 * The Access tab (slice 4 — access-policies.md): for the dataset's owner and grants.manage, who reads it (dl_access_list: principal, level,
 * filter, masked columns, via) with Add grant and Revoke; for everyone else who discovers it, why they have what they have (dl_access_explain
 * as sentences) and Request access below read. Rendered with the page (two small reads). Data: d (the dataset page's own)
 */
require_once dirname(__DIR__, 4) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 4) . '/app/features/access/present.php';
$pdo = db();
$id = (int) $d['dataset_id'];
$t = access_tab($pdo, (int) current_member_id(), $id);
$s = access_explain_sentences($t['explain'] ?? []);
$request = dl_level_rank((string) $t['level']) < DL_LEVELS['read'] && has_right('access.request')
    ? '/access/requests/new?' . http_build_query(['dataset' => $id, 'level' => 'read']) : null;
?>
<div id="dataset-access">
<?php if ($t['mode'] === 'list'): ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <div class="fw-semibold me-auto">Who reads <span class="font-monospace"><?= e((string) $d['key']) ?></span></div>
        <?= hx_link('/access/grants/new?dataset=' . $id, '<i class="feather-plus me-1"></i>Add grant', 'btn btn-primary btn-sm btn-touch', 'id="dataset-access-add-grant"') ?>
        <?= hx_link('/access/grants?dataset=' . $id . '&include_revoked=1', 'The grants\' ledger', 'btn btn-light btn-sm btn-touch', 'id="dataset-access-ledger"') ?>
    </div>
    <div class="card"><div class="table-responsive"><table class="table mb-0 align-middle" id="dataset-access-table">
        <thead><tr><th>Who</th><th>Level</th><th>Rows</th><th>Masked</th><th>Via</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($t['rows'] as $r): $b = level_badge((string) $r['level']); $gid = $r['grant_id'] === null ? null : (int) $r['grant_id']; ?>
            <tr id="dataset-access-row-<?= $gid ?? e((string) $r['via']) ?>">
                <td><i class="<?= e(principal_icon((string) $r['principal_kind'])) ?> me-1" aria-hidden="true"></i><?= e((string) ($r['principal_name'] ?? '—')) ?>
                    <span class="fs-11 text-muted d-block"><?= e((string) $r['principal_kind']) ?></span></td>
                <td><span class="badge <?= e($b[0]) ?>"><?= e($b[1]) ?></span><?php if ($r['expires_at'] !== null): ?> <span class="fs-11 text-muted">until <?= e(format_date(substr((string) $r['expires_at'], 0, 10))) ?></span><?php endif; ?></td>
                <td class="fs-12"><?= $r['row_filter'] === null ? 'all' : '<span class="font-monospace">' . e((string) $r['row_filter']) . '</span>' ?></td>
                <td class="fs-12"><?= (int) $r['masked_columns'] === 0 ? '—' : (int) $r['masked_columns'] ?></td>
                <td class="fs-12"><?= e((string) $r['via']) ?></td>
                <td class="text-end"><?php if ($gid !== null): ?>
                    <form method="post" action="/access/grants/revoke.php" hx-post="/access/grants/revoke.php" hx-target="#flash" hx-confirm="Revoke this grant? It stops at once." id="dataset-access-revoke-<?= $gid ?>-form">
                        <?= csrf_field() ?><input type="hidden" name="grant" value="<?= $gid ?>"><input type="hidden" name="return_to" value="/datasets/<?= $id ?>?tab=access">
                        <button type="submit" class="btn btn-light btn-sm btn-touch" id="dataset-access-revoke-<?= $gid ?>">Revoke</button>
                    </form><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
    <div class="fs-12 text-muted mt-2" id="dataset-access-mine"><?= e((string) $s['headline']) ?> <?= e(implode('; ', $s['reasons'])) ?></div>
<?php else: ?>
    <?= view('access/partials/explain.php', ['s' => $s, 'level' => $t['level'], 'request' => $request]) ?>
<?php endif; ?>
</div>
