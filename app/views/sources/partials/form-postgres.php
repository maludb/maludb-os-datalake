<?php /** The postgres connector's fields — one table of an outside PostgreSQL database. Data: v, field */ ?>
<div class="col-md-8"><label class="form-label" for="<?= $field('host') ?>">Server</label>
    <input type="text" class="form-control btn-touch" id="<?= $field('host') ?>" name="host" value="<?= e((string) $v['host']) ?>" maxlength="253" required placeholder="db.example.com" autocomplete="off">
    <div class="form-text">A database the business owns elsewhere — never one of this server's.</div></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('port') ?>">Port</label>
    <input type="number" class="form-control btn-touch" id="<?= $field('port') ?>" name="port" min="1" max="65535" value="<?= e((string) $v['port']) ?>"></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('dbname') ?>">Database</label>
    <input type="text" class="form-control btn-touch" id="<?= $field('dbname') ?>" name="dbname" value="<?= e((string) $v['dbname']) ?>" maxlength="63" required autocomplete="off"></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('user') ?>">User</label>
    <input type="text" class="form-control btn-touch" id="<?= $field('user') ?>" name="user" value="<?= e((string) $v['user']) ?>" maxlength="63" required autocomplete="off">
    <div class="form-text">A read-only login. Its password is the credential, sealed.</div></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('sslmode') ?>">Connection</label>
    <select class="form-select btn-touch" id="<?= $field('sslmode') ?>" name="sslmode">
        <?php foreach (['require' => 'Encrypted (require)', 'verify-full' => 'Encrypted, certificate checked', 'prefer' => 'Encrypted if the server can'] as $k => $label): ?><option value="<?= e($k) ?>"<?= $v['sslmode'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('table') ?>">Table</label>
    <input type="text" class="form-control btn-touch" id="<?= $field('table') ?>" name="table" value="<?= e((string) $v['table']) ?>" maxlength="127" required placeholder="public.orders" autocomplete="off">
    <div class="form-text">One table: several tables are several sources.</div></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('watermark') ?>">Watermark column <span class="text-muted">(optional)</span></label>
    <input type="text" class="form-control btn-touch" id="<?= $field('watermark') ?>" name="watermark" value="<?= e((string) $v['watermark']) ?>" maxlength="63" placeholder="updated_at" autocomplete="off">
    <div class="form-text">A column that only grows: each pull reads the rows after the last one's highest. Empty, the dataset is replaced each pull.</div></div>
<div class="col-md-4"><label class="form-label" for="<?= $field('key') ?>">Key columns <span class="text-muted">(optional)</span></label>
    <input type="text" class="form-control btn-touch" id="<?= $field('key') ?>" name="key" value="<?= e((string) $v['key']) ?>" maxlength="300" placeholder="id" autocomplete="off">
    <div class="form-text">Separated by commas. They order the rows, and say what one row is.</div></div>
