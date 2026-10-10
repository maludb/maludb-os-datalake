<?php
/**
 * The notes on a record (slice 1 renders a dataset's; slices 3 and 5 render a saved query's and an incident's with this same partial and the
 * same two handlers): newest first, each deletable by its author or an engineer; one field to add. Data: kind, record, notes, may_add, here
 */
$me = (int) current_member_id();
$engineer = has_right('catalog.edit');
?>
<div class="card mt-3" id="notes-panel"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div><div class="card-body">
    <?php if ($may_add): ?>
        <form method="post" action="/notes/add.php" hx-post="/notes/add.php" hx-target="#flash" id="notes-add-form" class="mb-3">
            <?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="record" value="<?= (int) $record ?>">
            <label class="visually-hidden" for="notes-field-body">A note</label>
            <textarea class="form-control mb-2" id="notes-field-body" name="body" rows="2" maxlength="<?= NOTE_MAX ?>" required placeholder="A note for everyone who can see this — what you learned, a caveat, a question"></textarea>
            <button type="submit" class="btn btn-light-brand btn-touch" id="notes-add-btn">Add the note</button>
        </form>
    <?php endif; ?>
    <?php if ($notes === []): ?><div class="fs-12 text-muted" id="notes-none">No notes yet.</div><?php endif; ?>
    <?php foreach ($notes as $n): ?>
        <div class="border-top pt-2 mt-2" id="note-<?= (int) $n['note_id'] ?>">
            <div class="d-flex justify-content-between gap-2">
                <div class="fs-11 text-muted"><?= e((string) ($n['author_name'] ?? 'Someone')) ?> · <?= e(format_ts($n['created_at'], member_timezone(), 'M j, g:i A')) ?></div>
                <?php if ((int) $n['member_id'] === $me || $engineer): ?>
                    <form method="post" action="/notes/delete.php" hx-post="/notes/delete.php" hx-target="#flash" hx-confirm="Delete this note?" class="d-inline">
                        <?= csrf_field() ?><input type="hidden" name="note" value="<?= (int) $n['note_id'] ?>">
                        <button type="submit" class="btn btn-link btn-sm p-0 text-muted" id="note-<?= (int) $n['note_id'] ?>-delete" aria-label="Delete this note"><i class="feather-trash-2"></i></button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="text-break fs-13"><?= nl2br(e((string) $n['body'])) ?></div>
        </div>
    <?php endforeach; ?>
</div></div>
