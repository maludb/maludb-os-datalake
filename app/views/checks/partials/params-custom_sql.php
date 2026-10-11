<?php /** custom_sql's params: a SELECT over this dataset that returns the failing rows (dry-run at save — it reads only this dataset). Data: p, err, d */ ?>
<div class="mb-3" id="check-form-field-sql"><label class="form-label" for="check-form-sql">SQL — returns the rows that fail</label>
    <textarea class="form-control font-monospace" id="check-form-sql" name="sql" rows="4" maxlength="4000" placeholder="SELECT * FROM <?= e((string) $d['key']) ?> WHERE amount < 0"><?= e((string) ($p['sql'] ?? '')) ?></textarea><?= $err('sql') ?></div>
