<?php
/** The strip: the last seven results as 10 px cells — green passed, red failed, grey could not run; empty cells before the first run. Data: strip, id */
$cells = array_slice($strip, -7);
$pad = 7 - count($cells);
?>
<span class="check-strip" id="<?= e($id) ?>" aria-label="The last <?= count($cells) ?> results">
<?php for ($k = 0; $k < $pad; $k++): ?><span class="check-cell empty"></span><?php endfor; ?>
<?php foreach ($cells as $s): ?><span class="check-cell <?= e($s['state']) ?>" title="<?= e(ucfirst($s['state']) . ' · ' . format_ts($s['ran_at'], member_timezone(), 'M j, g:i A')) ?>"></span><?php endforeach; ?>
</span>
