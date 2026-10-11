<?php
/**
 * A saved query's form (screen `query-edit`, page-header-form): title, the question, description, the SQL, the parameters (rows of name, type,
 * default, required — query-form-field-{name}), shared. "Saving re-checks the SQL." Data: q
 */
$id = $q['saved_query_id'] === null ? null : (int) $q['saved_query_id'];
$params = $q['parameters'];
$params[] = ['name' => '', 'type' => 'text', 'default' => '', 'required' => false];   // one empty row to add a parameter
?>
<?= view('shared/page-header.php', ['id' => 'query-edit', 'title' => $id === null ? 'New saved query' : 'Edit ' . $q['title'],
    'crumbs' => [['Home', '/'], ['Saved queries', '/queries'], [$id === null ? 'New' : (string) $q['title'], null]]]) ?>
<div class="main-content" id="query-edit-content">
    <form method="post" action="/queries/save.php" class="card" id="query-edit-form"><div class="card-body">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="saved_query" value="<?= $id ?>"><?php endif; ?>
        <div class="mb-3"><label class="form-label" for="query-form-field-title">Title</label>
            <input class="form-control btn-touch" id="query-form-field-title" name="title" maxlength="200" required value="<?= e((string) $q['title']) ?>"></div>
        <div class="mb-3"><label class="form-label" for="query-form-field-question">The question it answers</label>
            <input class="form-control btn-touch" id="query-form-field-question" name="question" maxlength="500" value="<?= e((string) ($q['question'] ?? '')) ?>" placeholder="What did each region sell last month?"></div>
        <div class="mb-3"><label class="form-label" for="query-form-field-description">Description</label>
            <textarea class="form-control" id="query-form-field-description" name="description" rows="2"><?= e((string) ($q['description'] ?? '')) ?></textarea></div>
        <div class="mb-3"><label class="form-label" for="query-form-field-sql">SQL — one SELECT over dataset keys; a parameter is <code>$name</code></label>
            <textarea class="form-control font-monospace" id="query-form-field-sql" name="sql" rows="8" spellcheck="false" required><?= e((string) $q['sql']) ?></textarea>
            <div class="form-text">Saving re-checks the SQL through the lake: the datasets it reads are taken from it; a change of the SQL removes the Verified badge.</div></div>
        <fieldset class="mb-3" id="query-form-field-parameters"><legend class="fs-14 fw-semibold">Parameters</legend>
            <?php foreach ($params as $i => $p): ?>
                <div class="row g-2 align-items-end mb-2 query-param-row">
                    <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="query-param-name-<?= $i ?>">Name</label>
                        <input class="form-control form-control-sm font-monospace" id="query-param-name-<?= $i ?>" name="param_name[]" value="<?= e((string) $p['name']) ?>" pattern="[a-z][a-z0-9_]{0,40}"></div>
                    <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="query-param-type-<?= $i ?>">Type</label>
                        <select class="form-select form-select-sm" id="query-param-type-<?= $i ?>" name="param_type[]"><?php foreach (PARAM_TYPES as $k => $w): ?><option value="<?= e($k) ?>"<?= $p['type'] === $k ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
                    <div class="col-8 col-md-4"><label class="form-label fs-12 mb-1" for="query-param-default-<?= $i ?>">Default</label>
                        <input class="form-control form-control-sm" id="query-param-default-<?= $i ?>" name="param_default[]" value="<?= e((string) ($p['default'] ?? '')) ?>"></div>
                    <div class="col-4 col-md-2"><div class="form-check"><input type="hidden" name="param_required[<?= $i ?>]" value="0">
                        <input class="form-check-input" type="checkbox" id="query-param-required-<?= $i ?>" name="param_required[<?= $i ?>]" value="1"<?= !empty($p['required']) ? ' checked' : '' ?>>
                        <label class="form-check-label fs-12" for="query-param-required-<?= $i ?>">Required</label></div></div>
                </div>
            <?php endforeach; ?>
            <div class="form-text">Leave a name empty to remove that row. Save to add another.</div>
        </fieldset>
        <div class="form-check mb-3"><input type="hidden" name="shared" value="no">
            <input class="form-check-input" type="checkbox" id="query-form-field-shared" name="shared" value="yes"<?= !empty($q['shared']) ? ' checked' : '' ?>>
            <label class="form-check-label" for="query-form-field-shared">Share it — whoever may read every dataset it reads finds it and may run it</label></div>
        <div class="d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="query-edit-save">Save</button>
            <?= hx_link($id === null ? '/queries' : '/queries/' . $id, 'Cancel', 'btn btn-light btn-touch', 'id="query-edit-cancel"') ?></div>
    </div></form>
</div>
