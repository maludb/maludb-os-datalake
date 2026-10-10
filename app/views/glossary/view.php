<?php
/**
 * A term (screen `term-view`; catalog.md): the definition, synonyms, the category, the steward, the status; the columns that carry it
 * (discoverable datasets only); Approve for a draft (catalog.edit, a person), Deprecate, Edit. Data: t, columns, children, may, notice, here
 */
$id = (int) $t['term_id'];
$b = term_status_badge((string) $t['status']);
?>
<?= view('shared/page-header.php', ['id' => 'term', 'title' => $t['name'], 'crumbs' => [['Glossary', '/glossary'], [$t['name'], null]], 'back' => back_link(),
    'action' => $may['edit'] ? hx_link('/glossary/' . $id . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-primary btn-touch', 'id="term-edit-btn"') : '']) ?>
<div class="main-content" id="term-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="term-definition"><div class="card-body">
        <div class="d-flex flex-wrap gap-1 mb-2"><span class="badge <?= e($b[0]) ?>" id="term-status"><?= e($b[1]) ?></span>
            <?php if ($t['parent_id'] !== null): ?><?= hx_link('/glossary/' . (int) $t['parent_id'], e((string) $t['parent_name']), 'badge text-bg-light border text-decoration-none', 'id="term-category"') ?><?php endif; ?></div>
        <div class="text-break" id="term-definition-text"><?= nl2br(e((string) $t['definition'])) ?></div>
        <dl class="row mb-0 mt-3 fs-13">
            <dt class="col-5 col-md-3">Also called</dt><dd class="col-7 col-md-9" id="term-synonyms"><?= $t['synonyms'] === [] ? '—' : e(implode(', ', $t['synonyms'])) ?></dd>
            <dt class="col-5 col-md-3">Steward</dt><dd class="col-7 col-md-9" id="term-steward"><?= e((string) ($t['steward_name'] ?? '—')) ?></dd>
            <?php if ($t['approved_at'] !== null): ?><dt class="col-5 col-md-3">Approved</dt><dd class="col-7 col-md-9" id="term-approved"><?= e(format_ts($t['approved_at'], member_timezone(), 'M j, Y')) ?><?= $t['approved_by_name'] !== null ? ' by ' . e($t['approved_by_name']) : '' ?></dd><?php endif; ?>
        </dl>
        <?php if ($may['approve'] || $may['deprecate']): ?>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <?php if ($may['approve']): ?>
                    <form method="post" action="/glossary/approve.php" hx-post="/glossary/approve.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="term" value="<?= $id ?>">
                        <button type="submit" class="btn btn-success btn-touch" id="term-approve-btn"><i class="feather-check me-1"></i>Approve</button></form>
                <?php endif; ?>
                <?php if ($may['deprecate']): ?>
                    <form method="post" action="/glossary/deprecate.php" hx-post="/glossary/deprecate.php" hx-target="#flash" hx-confirm="Deprecate “<?= e($t['name']) ?>”? Its columns keep the link; it leaves the pickers and search."><?= csrf_field() ?><input type="hidden" name="term" value="<?= $id ?>">
                        <button type="submit" class="btn btn-light btn-touch text-danger" id="term-deprecate-btn"><i class="feather-archive me-1"></i>Deprecate</button></form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div></div>
    <?php if ($children !== []): ?>
        <div class="card mb-3" id="term-children"><div class="card-body"><h6 class="fs-12 text-uppercase text-muted mb-2">Terms in this category</h6>
            <div class="facet-chips"><?php foreach ($children as $ch): ?><?= hx_link('/glossary/' . (int) $ch['term_id'], e($ch['name']), 'btn btn-sm btn-light btn-touch', 'id="term-child-' . (int) $ch['term_id'] . '"') ?><?php endforeach; ?></div></div></div>
    <?php endif; ?>
    <div class="card" id="term-columns"><div class="card-body">
        <h6 class="fs-12 text-uppercase text-muted mb-2">Columns that carry it</h6>
        <?php if ($columns === []): ?><div class="fs-12 text-muted" id="term-columns-none">None you may see yet — describe a column with this term from its page.</div><?php endif; ?>
        <?php foreach ($columns as $c): ?>
            <div class="py-1" id="term-column-<?= (int) $c['column_id'] ?>"><?= hx_link(with_back('/datasets/' . $c['key'] . '/columns/' . rawurlencode((string) $c['column']), $here), e($c['key'] . '.' . $c['column']), 'dataset-key') ?>
                <span class="fs-12 text-muted">— <?= e($c['title']) ?></span></div>
        <?php endforeach; ?>
    </div></div>
</div>
