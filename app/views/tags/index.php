<?php
/**
 * The tag vocabulary (screen `tags`; catalog.md): managed and free tags as chips with their counts and colours; for catalog.edit, make one,
 * change a label, kind or colour, archive (a tag carrying a grant warns: its grants stay until revoked). Data: tags, grants (tag_id => n), may_edit, edit (a tag or null), notice, archived
 */
$f = static fn (string $n): string => 'tag-form-field-' . $n;
$editing = $edit !== null;
?>
<?= view('shared/page-header.php', ['id' => 'tags', 'title' => 'Tags', 'crumbs' => [['Glossary', '/glossary'], ['Tags', null]], 'back' => back_link()]) ?>
<div class="main-content" id="tags-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php foreach (['managed' => 'Managed', 'free' => 'Free'] as $kind => $title): $list = array_values(array_filter($tags, static fn (array $t): bool => $t['kind'] === $kind && $t['archived_at'] === null)); ?>
        <div class="card mb-3" id="tags-<?= e($kind) ?>"><div class="card-body">
            <h6 class="fs-12 text-uppercase text-muted mb-2"><?= e($title) ?> <span class="text-lowercase fw-normal">· <?= e($kind === 'managed' ? 'the vocabulary engineers keep' : 'made by tagging a dataset') ?></span></h6>
            <?php if ($list === []): ?><div class="fs-12 text-muted">None.</div><?php endif; ?>
            <div class="facet-chips">
                <?php foreach ($list as $t): $g = $grants[(int) $t['tag_id']] ?? 0; ?>
                    <span class="badge text-bg-light border tag-chip d-inline-flex align-items-center gap-1 py-2" id="tag-chip-<?= e($t['slug']) ?>"<?= $t['colour'] ? ' style="border-color:' . e($t['colour']) . ' !important"' : '' ?>>
                        <?php if ($t['colour']): ?><span class="health-dot" style="background:<?= e($t['colour']) ?>" aria-hidden="true"></span><?php endif; ?>
                        <?= hx_link('/browse?tag=' . rawurlencode((string) $t['slug']), e($t['label']), 'text-reset text-decoration-none') ?>
                        <span class="text-muted fw-normal"><?= (int) $t['usage_count'] ?></span>
                        <?php if ($g > 0): ?><i class="feather-lock text-warning" title="<?= $g ?> grant<?= $g === 1 ? '' : 's' ?> reach the datasets carrying it"></i><?php endif; ?>
                        <?php if ($may_edit): ?><?= hx_link('/tags?edit=' . (int) $t['tag_id'] . '#tag-form', '<i class="feather-edit-2"></i>', 'text-muted ms-1', 'id="tag-chip-' . e($t['slug']) . '-edit" aria-label="Change ' . e($t['label']) . '"') ?><?php endif; ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div></div>
    <?php endforeach; ?>
    <?php if ($archived !== []): ?>
        <div class="fs-12 text-muted mb-3" id="tags-archived">Archived: <?= e(implode(', ', array_map(static fn (array $t): string => $t['label'], $archived))) ?></div>
    <?php endif; ?>
    <?php if ($may_edit): ?>
        <div class="card mb-3" id="tag-form-card"><div class="card-body">
            <h6 class="mb-2"><?= $editing ? 'Change the tag ' . e($edit['label']) : 'A new tag' ?></h6>
            <form method="post" action="/tags/save.php" hx-post="/tags/save.php" hx-target="#flash" id="tag-form">
                <?= csrf_field() ?><?php if ($editing): ?><input type="hidden" name="tag" value="<?= (int) $edit['tag_id'] ?>"><?php endif; ?>
                <div class="row g-2">
                    <div class="col-md-5"><label class="form-label" for="<?= $f('label') ?>">Label</label>
                        <input type="text" class="form-control btn-touch" id="<?= $f('label') ?>" name="label" value="<?= e((string) ($edit['label'] ?? '')) ?>" maxlength="80" required
                               <?= $editing ? '' : 'data-tag-input data-tag-preview="#tag-form-slug-preview"' ?>>
                        <div class="form-text" id="tag-form-slug-preview"><?= $editing ? '#' . e($edit['slug']) . ' — a slug never changes' : '' ?></div></div>
                    <div class="col-md-4"><label class="form-label" for="<?= $f('kind') ?>">Kind</label>
                        <select class="form-select btn-touch" id="<?= $f('kind') ?>" name="kind">
                            <?php foreach (TAG_KINDS as $k => $line): ?><option value="<?= e($k) ?>"<?= ($edit['kind'] ?? 'managed') === $k ? ' selected' : '' ?>><?= e($line) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-md-3"><label class="form-label" for="<?= $f('colour') ?>">Colour</label>
                        <input type="color" class="form-control form-control-color btn-touch w-100" id="<?= $f('colour') ?>" name="colour" value="<?= e((string) ($edit['colour'] ?? '#6c757d')) ?>"></div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="submit" class="btn btn-primary btn-touch" id="tag-form-save-btn"><?= $editing ? 'Save' : 'Make the tag' ?></button>
                    <?php if ($editing): ?><?= hx_link('/tags', 'Cancel', 'btn btn-light btn-touch', 'id="tag-form-cancel-btn"') ?><?php endif; ?>
                </div>
            </form>
            <?php if ($editing): $g = $grants[(int) $edit['tag_id']] ?? 0; ?>
                <form method="post" action="/tags/archive.php" hx-post="/tags/archive.php" hx-target="#flash" class="mt-3" id="tag-archive-form"
                      hx-confirm="Archive <?= e($edit['label']) ?>?<?= $g > 0 ? ' Grants on this tag stay until revoked.' : '' ?>">
                    <?= csrf_field() ?><input type="hidden" name="tag" value="<?= (int) $edit['tag_id'] ?>">
                    <?php if ($g > 0): ?><div class="alert alert-warning py-2 fs-12" id="tag-archive-warning">Grants on this tag stay until revoked — <?= $g ?> grant<?= $g === 1 ? '' : 's' ?> reach every dataset that carries it.</div><?php endif; ?>
                    <button type="submit" class="btn btn-light btn-touch text-danger" id="tag-archive-btn"><i class="feather-archive me-1"></i>Archive the tag</button>
                </form>
            <?php endif; ?>
        </div></div>
    <?php endif; ?>
</div>
