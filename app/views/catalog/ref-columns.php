<?php /** The referenced dataset's live columns as a select (Pattern A: reloaded when the dataset picker changes). Data: names, selected */ ?>
<select class="form-select btn-touch" id="column-form-field-references_column" name="references_column">
    <option value=""><?= $names === [] ? 'Choose the dataset first' : '— its column —' ?></option>
    <?php foreach ($names as $n): ?><option value="<?= e($n) ?>"<?= $selected === $n ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
</select>
