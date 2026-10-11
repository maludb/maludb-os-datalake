<?php /** The http_file connector's fields. Data: v, field */ ?>
<div class="col-12"><label class="form-label" for="<?= $field('url') ?>">Address of the file</label>
    <input type="url" class="form-control btn-touch" id="<?= $field('url') ?>" name="url" value="<?= e((string) $v['url']) ?>" maxlength="2000" required placeholder="https://files.example.com/exports/sales.csv" autocomplete="off">
    <div class="form-text">https only. Leave a user name and password out of it — they belong in the credential, sealed.</div></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('format') ?>">Format</label>
    <select class="form-select btn-touch" id="<?= $field('format') ?>" name="format">
        <?php foreach (DL_FILE_FORMATS as $k => $label): ?><option value="<?= e($k) ?>"<?= $v['format'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></div>
<div class="col-md-8"><label class="form-label" id="source-form-template-label" for="<?= $field('template_id') ?>">Saved mapping <span class="text-muted">(optional)</span></label>
    <?= picker_field(['id' => $field('template_id'), 'name' => 'template_id', 'source' => 'template', 'value' => $v['template_id'], 'labelledby' => 'source-form-template-label', 'title' => 'The saved mapping', 'placeholder' => 'None — the columns keep the file\'s names']) ?></div>
<div class="col-md-6"><label class="form-label" for="<?= $field('username') ?>">User name <span class="text-muted">(for a password credential)</span></label>
    <input type="text" class="form-control btn-touch" id="<?= $field('username') ?>" name="username" value="<?= e((string) $v['username']) ?>" maxlength="100" autocomplete="off"></div>
<div class="col-md-6"><label class="form-label" for="<?= $field('api_key_header') ?>">API key header <span class="text-muted">(for an API key; X-API-Key if empty)</span></label>
    <input type="text" class="form-control btn-touch" id="<?= $field('api_key_header') ?>" name="api_key_header" value="<?= e((string) $v['api_key_header']) ?>" maxlength="60" autocomplete="off" placeholder="X-API-Key"></div>
