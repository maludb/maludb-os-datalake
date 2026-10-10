<?php
/** A term's form (screens `term-add` and `term-edit`): name, definition (4000), synonyms (a tag-like field), the category (a term), the steward. Data: t (null on add), v */
$editing = $t !== null;
$id = $editing ? (int) $t['term_id'] : null;
$f = static fn (string $n): string => 'term-form-field-' . $n;
?>
<?= view('shared/page-header.php', ['id' => 'term-form', 'title' => $editing ? 'Edit ' . $t['name'] : 'New term',
    'crumbs' => $editing ? [['Glossary', '/glossary'], [$t['name'], '/glossary/' . $id], ['Edit', null]] : [['Glossary', '/glossary'], ['New term', null]],
    'back' => $editing ? ['/glossary/' . $id, $t['name']] : ['/glossary', 'Glossary']]) ?>
<div class="main-content" id="term-form-content">
    <form method="post" action="/glossary/save.php" hx-post="/glossary/save.php" hx-target="#flash" id="term-form" class="page-header-form">
        <?= csrf_field() ?><?php if ($editing): ?><input type="hidden" name="term" value="<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-12"><label class="form-label" for="<?= $f('name') ?>">Name</label>
                <input type="text" class="form-control btn-touch" id="<?= $f('name') ?>" name="name" value="<?= e((string) $v['name']) ?>" maxlength="120" required autofocus placeholder="Active customer"></div>
            <div class="col-12"><label class="form-label" for="<?= $f('definition') ?>">Definition</label>
                <textarea class="form-control" id="<?= $f('definition') ?>" name="definition" rows="4" maxlength="4000" required><?= e((string) $v['definition']) ?></textarea>
                <div class="form-text">What it means here, precisely enough that two people would count the same rows.</div></div>
            <div class="col-12"><label class="form-label" for="<?= $f('synonyms') ?>">Also called</label>
                <input type="text" class="form-control btn-touch" id="<?= $f('synonyms') ?>" name="synonyms" value="<?= e(implode(', ', $v['synonyms'])) ?>" maxlength="1000" placeholder="live customer, current client" data-chips-input data-chips-preview="#term-form-synonyms-preview">
                <div class="form-text">Separate with commas — search finds the term by each.</div><div class="facet-chips mt-1" id="term-form-synonyms-preview" aria-hidden="true"></div></div>
            <div class="col-md-6"><label class="form-label" id="term-form-parent-label" for="<?= $f('parent') ?>">Category</label>
                <?= picker_field(['id' => $f('parent'), 'name' => 'parent', 'source' => 'term', 'value' => $v['parent_id'], 'params' => $editing ? ['except' => $id] : [], 'labelledby' => 'term-form-parent-label', 'title' => 'The category', 'placeholder' => 'None — a category of its own']) ?></div>
            <div class="col-md-6"><label class="form-label" id="term-form-steward-label" for="<?= $f('steward') ?>">Steward</label>
                <?= picker_field(['id' => $f('steward'), 'name' => 'steward', 'source' => 'member', 'value' => $v['steward_member_id'], 'labelledby' => 'term-form-steward-label', 'title' => 'The steward', 'placeholder' => 'None']) ?></div>
        </div></div></div>
        <div class="d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="term-form-save-btn"><?= $editing ? 'Save' : 'Add the term' ?></button>
            <?= hx_link($editing ? '/glossary/' . $id : '/glossary', 'Cancel', 'btn btn-light btn-touch', 'id="term-form-cancel-btn"') ?>
        </div>
    </form>
</div>
