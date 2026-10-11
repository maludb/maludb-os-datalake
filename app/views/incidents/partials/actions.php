<?php
/** An incident's actions (phone-first, each a plain form with HTMX on top): Acknowledge · Assign · Resolve · Dismiss. Data: i, may, here */
$id = (int) $i['incident_id'];
if (!$may['ack'] && !$may['assign'] && !$may['resolve'] && !$may['dismiss']) {
    return;
}
?>
<div class="card mb-3" id="incident-actions"><div class="card-body d-grid gap-3">
    <?php if ($may['ack']): ?>
        <form method="post" action="/incidents/ack.php" hx-post="/incidents/ack.php" hx-target="#flash" id="incident-<?= $id ?>-ack-form">
            <?= csrf_field() ?><input type="hidden" name="incident" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <button type="submit" class="btn btn-warning btn-touch w-100" id="incident-<?= $id ?>-ack"><i class="feather-eye me-1"></i>Acknowledge</button>
        </form>
    <?php endif; ?>
    <?php if ($may['assign']): ?>
        <form method="post" action="/incidents/assign.php" hx-post="/incidents/assign.php" hx-target="#flash" id="incident-<?= $id ?>-assign-form">
            <?= csrf_field() ?><input type="hidden" name="incident" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <label class="form-label fs-12" id="incident-assignee-label" for="incident-assignee-pick-open">Assign to</label>
            <div class="d-flex gap-2"><div class="flex-grow-1"><?= picker_field(['id' => 'incident-assignee-pick', 'name' => 'assignee', 'source' => 'member',
                'value' => $i['assignee_member_id'], 'labelledby' => 'incident-assignee-label', 'title' => 'Assign to']) ?></div>
            <button type="submit" class="btn btn-light btn-touch" id="incident-<?= $id ?>-assign">Assign</button></div>
        </form>
    <?php endif; ?>
    <?php if ($may['resolve']): ?>
        <details id="incident-<?= $id ?>-resolve-box"><summary class="btn btn-success btn-touch w-100">Resolve…</summary>
        <form method="post" action="/incidents/resolve.php" hx-post="/incidents/resolve.php" hx-target="#flash" class="mt-2" id="incident-<?= $id ?>-resolve-form">
            <?= csrf_field() ?><input type="hidden" name="incident" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <label class="form-label fs-12" for="incident-<?= $id ?>-resolution">What was done</label>
            <textarea class="form-control mb-2" id="incident-<?= $id ?>-resolution" name="resolution" rows="2" maxlength="4000" required></textarea>
            <label class="form-label fs-12" for="incident-<?= $id ?>-root-cause">Root cause (optional)</label>
            <input type="text" class="form-control mb-2" id="incident-<?= $id ?>-root-cause" name="root_cause" maxlength="2000">
            <button type="submit" class="btn btn-success btn-touch w-100" id="incident-<?= $id ?>-resolve">Resolve</button>
        </form></details>
    <?php endif; ?>
    <?php if ($may['dismiss']): ?>
        <details id="incident-<?= $id ?>-dismiss-box"><summary class="btn btn-light btn-touch w-100">Dismiss…</summary>
        <form method="post" action="/incidents/dismiss.php" hx-post="/incidents/dismiss.php" hx-target="#flash" class="mt-2" id="incident-<?= $id ?>-dismiss-form">
            <?= csrf_field() ?><input type="hidden" name="incident" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <label class="form-label fs-12" for="incident-<?= $id ?>-reason">Why it is no incident</label>
            <textarea class="form-control mb-2" id="incident-<?= $id ?>-reason" name="reason" rows="2" maxlength="2000" required></textarea>
            <button type="submit" class="btn btn-secondary btn-touch w-100" id="incident-<?= $id ?>-dismiss">Dismiss</button>
        </form></details>
    <?php endif; ?>
</div></div>
