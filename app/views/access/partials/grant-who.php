<?php
/** The grant form's who and level (grant-form-who): the principal picker for the chosen kind (none for everyone) and the levels the database admits for it. Fetched again when the kind changes. Data: v */
$kind = (string) $v['principal_kind'];
$levels = PRINCIPAL_LEVELS[$kind] ?? ACCESS_LEVELS;
$pdo = db();
?>
<div class="row g-3" id="grant-form-who">
    <div class="col-md-6"><label class="form-label" id="grant-form-principal-label" for="grant-form-field-principal"><?= e(PRINCIPAL_KINDS[$kind] ?? 'Who') ?></label>
        <?php if ($kind === 'everyone'): ?>
            <div class="form-control-plaintext" id="grant-form-field-principal">Everyone inside who holds a role here — never an external.</div>
        <?php else: ?>
            <?= picker_field(['id' => 'grant-form-field-principal', 'name' => 'principal', 'source' => 'principal', 'value' => $v['principal'],
                'label' => $v['principal'] !== null ? principal_label($pdo, $kind, (int) $v['principal']) : null, 'params' => ['kind' => PRINCIPAL_PICK_KIND[$kind] ?? 0],
                'labelledby' => 'grant-form-principal-label', 'title' => PRINCIPAL_KINDS[$kind] ?? 'Who', 'required' => true]) ?>
        <?php endif; ?></div>
    <div class="col-md-6"><label class="form-label" for="grant-form-field-level">Level</label>
        <select class="form-select btn-touch" id="grant-form-field-level" name="level">
            <?php foreach ($levels as $l): ?><option value="<?= e($l) ?>"<?= $l === $v['level'] ? ' selected' : '' ?>><?= e(ucfirst(level_words($l))) ?></option><?php endforeach; ?>
        </select>
        <?php if ($kind === 'everyone'): ?><div class="form-text">Everyone may at most read (masked).</div><?php endif; ?></div>
</div>
