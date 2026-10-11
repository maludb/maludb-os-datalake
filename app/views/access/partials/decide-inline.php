<?php
/**
 * The owner's one-tap decision on a phone (requests → To decide): Approve with a level (the asked one by default) and an optional expiry —
 * the impact line follows the level (impact.php, fetched again on change) — or Deny with a reason (required). Data: r
 */
$id = (int) $r['request_id'];
$pdo = db();
$impact = request_impact($pdo, $id, (string) $r['level']);
?>
<div class="border-top mt-3 pt-3 decide-inline" id="request-<?= $id ?>-decide">
    <form method="post" action="/access/requests/decide.php" hx-post="/access/requests/decide.php" hx-target="#flash" id="request-<?= $id ?>-approve-form">
        <?= csrf_field() ?><input type="hidden" name="access_request" value="<?= $id ?>"><input type="hidden" name="approve" value="yes"><input type="hidden" name="return_to" value="/access/requests?box=to_decide">
        <div class="row g-2 align-items-end">
            <div class="col-6"><label class="form-label fs-12 mb-1" for="request-<?= $id ?>-level">Level</label>
                <select class="form-select btn-touch" id="request-<?= $id ?>-level" name="level" hx-get="/access/requests?impact=<?= $id ?>" hx-trigger="change" hx-target="#request-<?= $id ?>-impact" hx-swap="outerHTML" hx-include="this">
                    <?php foreach (REQUEST_LEVELS as $l): ?><option value="<?= e($l) ?>"<?= $l === $r['level'] ? ' selected' : '' ?>><?= e(level_badge($l)[1]) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-6"><label class="form-label fs-12 mb-1" for="request-<?= $id ?>-until">Until (optional)</label>
                <input type="date" class="form-control btn-touch" id="request-<?= $id ?>-until" name="until" value="<?= e((string) ($r['wanted_until'] ?? '')) ?>" min="<?= e(date('Y-m-d', time() + 86400)) ?>"></div>
        </div>
        <?= view('access/partials/impact.php', ['r' => $r, 'impact' => $impact]) ?>
        <button type="submit" class="btn btn-success btn-touch w-100 mt-2" id="request-<?= $id ?>-approve"><i class="feather-check me-1"></i>Approve</button>
    </form>
    <form method="post" action="/access/requests/decide.php" hx-post="/access/requests/decide.php" hx-target="#flash" class="mt-2" id="request-<?= $id ?>-deny-form">
        <?= csrf_field() ?><input type="hidden" name="access_request" value="<?= $id ?>"><input type="hidden" name="approve" value="no"><input type="hidden" name="return_to" value="/access/requests?box=to_decide">
        <label class="form-label fs-12 mb-1" for="request-<?= $id ?>-note">Why not (required to deny)</label>
        <div class="input-group">
            <input type="text" class="form-control btn-touch" id="request-<?= $id ?>-note" name="note" maxlength="2000" minlength="3" placeholder="Ask Finance for the summary instead" required>
            <button type="submit" class="btn btn-outline-danger btn-touch" id="request-<?= $id ?>-deny">Deny</button>
        </div>
    </form>
</div>
