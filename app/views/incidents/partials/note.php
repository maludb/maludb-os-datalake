<?php /** One note on an incident. Data: n */ ?>
<div class="mb-2 pb-2 border-bottom" id="incident-note-<?= (int) $n['note_id'] ?>">
    <div class="fs-13"><?= nl2br(e($n['body'])) ?></div>
    <div class="fs-11 text-muted"><?= e($n['author'] ?? 'someone who has left') ?><?= $n['by_agent'] ? ' (agent)' : '' ?> · <?= e(format_ts($n['created_at'], member_timezone(), 'M j, g:i A')) ?></div>
</div>
