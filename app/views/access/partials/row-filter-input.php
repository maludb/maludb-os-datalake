<?php
/**
 * The row filter's field (grant-form-field-row_filter): the template as typed, the dataset's columns listed, the tokens {member_id},
 * {departments}, {groups} as buttons (they insert at the cursor with JavaScript; typed by hand without), and Check — the dry run through the
 * query service ("valid", or the node it refuses). Data: value, columns, dataset_key, error, check (the last check's answer or null)
 */
?>
<div id="grant-form-row-filter-field">
    <label class="form-label" for="grant-form-field-row_filter">Row filter (optional)</label>
    <div class="input-group">
        <input type="text" class="form-control btn-touch font-monospace<?= ($error ?? null) !== null ? ' is-invalid' : '' ?>" id="grant-form-field-row_filter" name="row_filter" value="<?= e((string) ($value ?? '')) ?>"
               maxlength="2000" placeholder="department_id = ANY({departments})" autocomplete="off" spellcheck="false">
        <button type="button" class="btn btn-light-brand btn-touch" id="row-filter-check" hx-post="/access/grants/check.php" hx-target="#grant-form-row-filter-field" hx-swap="outerHTML"
                hx-include="#grant-form-field-row_filter, #grant-form-field-dataset, [name=csrf_token]"<?= ($dataset_key ?? null) === null ? ' disabled' : '' ?>>Check</button>
    </div>
    <?php if (($error ?? null) !== null): ?><div class="invalid-feedback d-block" id="grant-form-row-filter-error"><?= e((string) $error) ?></div>
    <?php elseif (!empty($check['ok'])): ?><div class="valid-feedback d-block" id="grant-form-row-filter-valid"><i class="feather-check me-1"></i>Valid — for you it reads <span class="font-monospace"><?= e((string) $check['bound']) ?></span>.</div><?php endif; ?>
    <div class="form-text">Only the rows it keeps are read, by every reader this grant reaches. The tokens are bound for each reader:
        <?php foreach (ROW_FILTER_TOKENS as $t): ?><button type="button" class="btn btn-light btn-sm py-0 font-monospace" data-insert="<?= e($t) ?>" data-insert-into="#grant-form-field-row_filter"><?= e($t) ?></button> <?php endforeach; ?></div>
    <?php if (!empty($columns)): ?>
        <div class="fs-12 text-muted mt-1" id="grant-form-row-filter-columns">Columns of <span class="font-monospace"><?= e((string) $dataset_key) ?></span>:
            <?php foreach ($columns as $c): ?><button type="button" class="btn btn-light btn-sm py-0 font-monospace" data-insert="<?= e((string) $c['name']) ?>" data-insert-into="#grant-form-field-row_filter"><?= e((string) $c['name']) ?></button> <?php endforeach; ?></div>
    <?php elseif (($dataset_key ?? null) === null): ?>
        <div class="fs-12 text-muted mt-1">A row filter belongs to a grant on one dataset (its columns) — not to a zone or a tag.</div>
    <?php endif; ?>
</div>
