<?php
/**
 * One saved query (screen `query-view`): the question, the description, the SQL (read-only), the parameters as a form — Run shows the result
 * only (#query-result, the editor's partial); Verify / Unverify (queries.verify), Open in the editor, Edit, Copy, Delete (the owner). Data: q,
 * result, stats, errors, given, notice
 */
$me = (int) current_member_id();
$mine = (int) $q['owner_member_id'] === $me;
$b = verified_badge($q);
$id = (int) $q['saved_query_id'];
$tz = member_timezone();
?>
<?= view('shared/page-header.php', ['id' => 'query-view', 'title' => (string) $q['title'], 'crumbs' => [['Home', '/'], ['Saved queries', '/queries'], [(string) $q['title'], null]]]) ?>
<div class="main-content" id="query-view-content">
    <?php if ($notice !== null): ?><?= view('shared/notice.php', ['notice' => $notice]) ?><?php endif; ?>
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap gap-1 mb-2">
            <?php if ($b !== null): ?><span class="badge <?= e($b[0]) ?>" id="query-view-badge" title="<?= e($q['verified_at'] === null ? '' : format_ts($q['verified_at'], $tz)) ?>"><?= e($b[1]) ?></span><?php endif; ?>
            <span class="badge bg-soft-secondary text-secondary"><?= $mine ? ($q['shared'] ? 'Mine · shared' : 'Mine') : 'Shared by ' . e((string) $q['owner_name']) ?></span>
            <?php foreach ($q['datasets'] as $d): ?><?= hx_link('/datasets/' . $d['key'], e($d['key']), 'badge text-bg-light font-monospace') ?><?php endforeach; ?>
        </div>
        <?php if (($q['question'] ?? '') !== ''): ?><div class="fw-semibold mb-1" id="query-view-question"><?= e((string) $q['question']) ?></div><?php endif; ?>
        <?php if (($q['description'] ?? '') !== ''): ?><div class="fs-12 text-muted mb-2"><?= nl2br(e((string) $q['description'])) ?></div><?php endif; ?>
        <pre class="bg-light p-2 fs-12 mb-2 sql-readonly" id="query-view-sql"><?= e((string) $q['sql']) ?></pre>
        <form method="get" action="/queries/<?= $id ?>" hx-get="/queries/<?= $id ?>" hx-target="#query-result" hx-swap="innerHTML" id="query-run-form" class="row g-2 align-items-end">
            <input type="hidden" name="run" value="1"><input type="hidden" name="partial" value="1">
            <?php foreach ($q['parameters'] as $p): $n = (string) $p['name']; ?>
                <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="query-form-field-<?= e($n) ?>">$<?= e($n) ?><?= !empty($p['required']) ? ' *' : '' ?> <span class="text-muted">(<?= e(PARAM_TYPES[$p['type']] ?? $p['type']) ?>)</span></label>
                    <input type="<?= $p['type'] === 'date' ? 'date' : 'text' ?>" class="form-control btn-touch<?= isset($errors['p_' . $n]) ? ' is-invalid' : '' ?>" id="query-form-field-<?= e($n) ?>" name="p[<?= e($n) ?>]"
                           value="<?= e((string) ($given[$n] ?? '')) ?>" placeholder="<?= e((string) ($p['default'] ?? '')) ?>">
                    <?php if (isset($errors['p_' . $n])): ?><div class="invalid-feedback"><?= e($errors['p_' . $n]) ?></div><?php endif; ?></div>
            <?php endforeach; ?>
            <div class="col-12 col-md-auto"><button type="submit" class="btn btn-primary btn-touch" id="query-run-btn"><i class="feather-play me-1"></i>Run</button></div>
        </form>
        <div class="d-flex flex-wrap gap-2 mt-3" id="query-view-actions">
            <?php if (has_right('sql.editor')): ?><?= hx_link('/sql?saved_query=' . $id, '<i class="feather-terminal me-1"></i>Open in the editor', 'btn btn-light btn-sm btn-touch', 'id="query-open-editor"') ?><?php endif; ?>
            <?php if (has_right('queries.verify')): ?>
                <form method="post" action="/queries/verify.php" class="d-inline"><?= csrf_field() ?><input type="hidden" name="saved_query" value="<?= $id ?>">
                    <input type="hidden" name="verify" value="<?= $q['verified_at'] === null ? 'yes' : 'no' ?>">
                    <button type="submit" class="btn btn-light btn-sm btn-touch" id="query-verify-btn"><?= $q['verified_at'] === null ? '<i class="feather-check-circle me-1"></i>Verify' : 'Remove the badge' ?></button></form>
            <?php endif; ?>
            <?php if ($mine && has_right('queries.save')): ?>
                <?= hx_link('/queries/' . $id . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-sm btn-touch', 'id="query-edit-btn"') ?>
                <form method="post" action="/queries/delete.php" class="d-inline" hx-confirm="Delete this saved query?"><?= csrf_field() ?><input type="hidden" name="saved_query" value="<?= $id ?>">
                    <button type="submit" class="btn btn-light btn-sm btn-touch text-danger" id="query-delete-btn"><i class="feather-trash-2 me-1"></i>Delete</button></form>
            <?php elseif (has_right('queries.save')): ?>
                <?= hx_link('/queries/new?copy=' . $id, '<i class="feather-copy me-1"></i>Copy', 'btn btn-light btn-sm btn-touch', 'id="query-copy-btn"') ?>
            <?php endif; ?>
        </div>
    </div></div>
    <div id="query-result" aria-live="polite"><?php if ($result !== null): ?><?= view($result['status'] === 'ok' ? 'sql/partials/result.php' : 'sql/partials/refusal.php',
        ['r' => $result, 'stats' => $stats, 'sql' => (string) $q['sql'], 'as_of' => null]) ?><?php endif; ?></div>
</div>
