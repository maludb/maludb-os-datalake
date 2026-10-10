<?php
/**
 * The tags of a dataset with the inline editor (dataset_tag / dataset_untag — the owner, the steward or an engineer): each tag a chip
 * (removable), and one field that adds a tag by its words — an existing tag by its slug or label, else a new FREE tag. Works without
 * JavaScript (two plain forms); assets/js/tag-editor.js previews the slug as you type. Data: d, may, here, suggestions [{slug, label}]
 */
$id = (int) $d['dataset_id'];
?>
<div id="dataset-tags" class="tag-editor">
    <div class="facet-chips mb-2" id="dataset-tags-list">
        <?php if ($d['tag_rows'] === []): ?><span class="fs-12 text-muted" id="dataset-tags-none">No tags.</span><?php endif; ?>
        <?php foreach ($d['tag_rows'] as $t): ?>
            <span class="badge text-bg-light border tag-chip d-inline-flex align-items-center gap-1" id="tag-chip-<?= e($t['slug']) ?>"<?= $t['colour'] ? ' style="border-color:' . e($t['colour']) . ' !important"' : '' ?>>
                <?= hx_link('/browse?tag=' . rawurlencode((string) $t['slug']), e($t['label']), 'text-reset text-decoration-none') ?>
                <?php if ($may['describe']): ?>
                    <form method="post" action="/datasets/untag.php" hx-post="/datasets/untag.php" hx-target="#flash" class="d-inline">
                        <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="tag" value="<?= e($t['slug']) ?>">
                        <input type="hidden" name="return_to" value="/datasets/<?= $id ?>?tab=about#dataset-tags">
                        <button type="submit" class="btn btn-link btn-sm p-0 ms-1 text-muted" id="tag-chip-<?= e($t['slug']) ?>-remove" aria-label="Remove the tag <?= e($t['label']) ?>"><i class="feather-x"></i></button>
                    </form>
                <?php endif; ?>
            </span>
        <?php endforeach; ?>
    </div>
    <?php if ($may['describe']): ?>
        <form method="post" action="/datasets/tag.php" hx-post="/datasets/tag.php" hx-target="#flash" id="dataset-tag-form" class="d-flex gap-2">
            <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="return_to" value="/datasets/<?= $id ?>?tab=about#dataset-tags">
            <div class="flex-grow-1 min-w-0">
                <label class="visually-hidden" for="dataset-tag-field-tag">Add a tag</label>
                <input type="text" class="form-control btn-touch" id="dataset-tag-field-tag" name="tag" list="dataset-tag-suggestions" maxlength="80" placeholder="Add a tag — finance, monthly…"
                       autocomplete="off" required data-tag-input data-tag-preview="#dataset-tag-preview">
                <datalist id="dataset-tag-suggestions"><?php foreach ($suggestions as $s): ?><option value="<?= e($s['slug']) ?>"><?= e($s['label']) ?></option><?php endforeach; ?></datalist>
                <div class="form-text" id="dataset-tag-preview" aria-live="polite"></div>
            </div>
            <button type="submit" class="btn btn-light-brand btn-touch" id="dataset-tag-add-btn">Add</button>
        </form>
    <?php endif; ?>
</div>
