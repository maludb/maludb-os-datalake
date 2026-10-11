<?php /** The sftp_file connector's fields. Data: v, field */ ?>
<div class="col-md-8"><label class="form-label" for="<?= $field('sftp_host') ?>">Server</label>
    <input type="text" class="form-control btn-touch" id="<?= $field('sftp_host') ?>" name="sftp_host" value="<?= e((string) $v['sftp_host']) ?>" maxlength="253" required placeholder="sftp.example.com" autocomplete="off"></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('sftp_port') ?>">Port</label>
    <input type="number" class="form-control btn-touch" id="<?= $field('sftp_port') ?>" name="sftp_port" min="1" max="65535" value="<?= e((string) $v['sftp_port']) ?>"></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('sftp_user') ?>">User name</label>
    <input type="text" class="form-control btn-touch" id="<?= $field('sftp_user') ?>" name="sftp_user" value="<?= e((string) $v['sftp_user']) ?>" maxlength="100" required autocomplete="off"></div>
<div class="col-md-8"><label class="form-label" for="<?= $field('sftp_path') ?>">Directory</label>
    <input type="text" class="form-control btn-touch" id="<?= $field('sftp_path') ?>" name="sftp_path" value="<?= e((string) $v['sftp_path']) ?>" maxlength="1000" required placeholder="/outgoing/reports" autocomplete="off"></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('pattern') ?>">Files named</label>
    <input type="text" class="form-control btn-touch" id="<?= $field('pattern') ?>" name="pattern" value="<?= e((string) $v['pattern']) ?>" maxlength="200" placeholder="sales_*.csv" autocomplete="off">
    <div class="form-text">Every new file that matches lands once, oldest first.</div></div>
<div class="col-md-3"><label class="form-label" for="<?= $field('format') ?>">Format</label>
    <select class="form-select btn-touch" id="<?= $field('format') ?>" name="format">
        <?php foreach (DL_FILE_FORMATS as $k => $label): ?><option value="<?= e($k) ?>"<?= $v['format'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></div>
<div class="col-md-5"><label class="form-label" id="source-form-template-label" for="<?= $field('template_id') ?>">Saved mapping <span class="text-muted">(optional)</span></label>
    <?= picker_field(['id' => $field('template_id'), 'name' => 'template_id', 'source' => 'template', 'value' => $v['template_id'], 'labelledby' => 'source-form-template-label', 'title' => 'The saved mapping', 'placeholder' => 'None']) ?></div>
<div class="col-12"><label class="form-label" for="<?= $field('host_key_sha256') ?>">Host key fingerprint <span class="text-muted">(optional, SHA-256)</span></label>
    <input type="text" class="form-control btn-touch" id="<?= $field('host_key_sha256') ?>" name="host_key_sha256" value="<?= e((string) $v['host_key_sha256']) ?>" maxlength="80" autocomplete="off" placeholder="43 characters, as ssh-keygen -lf prints it">
    <div class="form-text">Pin it and the server must present this key; empty, the key is not checked.</div></div>
