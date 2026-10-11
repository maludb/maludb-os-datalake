<?php /** The upload shape's field (a saved mapping people's uploads can use). Data: v, field */ ?>
<div class="col-12"><label class="form-label" id="source-form-template-label" for="<?= $field('template_id') ?>">Saved mapping <span class="text-muted">(optional)</span></label>
    <?= picker_field(['id' => $field('template_id'), 'name' => 'template_id', 'source' => 'template', 'value' => $v['template_id'], 'labelledby' => 'source-form-template-label', 'title' => 'The saved mapping', 'placeholder' => 'None — people map each file']) ?>
    <div class="form-text">An upload source fetches nothing; it names a shape of file people upload into the dataset.</div></div>
