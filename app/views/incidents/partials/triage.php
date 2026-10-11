<?php /** The Steward's triage notes (slice 10 writes them; shown first). Data: notes */ $tz = member_timezone(); ?>
<div class="border-start border-3 border-info ps-2 mb-3" id="incident-triage">
    <div class="fs-11 text-uppercase text-info fw-semibold mb-1"><i class="feather-feather me-1"></i>The Steward's triage</div>
    <?php foreach ($notes as $n): ?>
        <div class="fs-13 mb-1" id="incident-note-<?= (int) $n['note_id'] ?>"><?= nl2br(e($n['body'])) ?>
            <div class="fs-11 text-muted"><?= e($n['author'] ?? 'the Steward') ?> · <?= e(format_ts($n['created_at'], $tz, 'M j, g:i A')) ?></div></div>
    <?php endforeach; ?>
</div>
