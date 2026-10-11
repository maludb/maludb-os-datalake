<?php
/**
 * To ask for access (screen `request-add`): the dataset (the picker — a dataset I may only know exists is accepted: its stub), the level
 * (discover, read, read unmasked, write), the reason (3–2,000), wanted until; "a request already waits" when one does. Data: v, d (the
 * prefilled dataset's gate row or null), pending, errors
 */
$f = static fn (string $n): string => 'request-form-field-' . $n;
?>
<?= view('shared/page-header.php', ['id' => 'request-form', 'title' => 'Request access',
    'crumbs' => [['Home', '/'], ['Access', '/access'], ['Requests', '/access/requests'], ['Request access', null]],
    'back' => $d !== null ? ['/datasets/' . (int) $d['id'], (string) $d['title']] : ['/access', 'My access']]) ?>
<div class="main-content" id="request-form-content">
    <?php if ($pending !== null): ?>
        <div class="alert alert-warning" id="request-form-pending">A request already waits for <?= e((string) $d['key']) ?> (asked <?= e(format_ts((string) $pending['created_at'], member_timezone())) ?>).
            <?= hx_link('/access/requests?box=mine', 'See it', 'alert-link') ?></div>
    <?php endif; ?>
    <form method="post" action="/access/requests/save.php" hx-post="/access/requests/save.php" hx-target="#flash" id="request-form" class="page-header-form">
        <?= csrf_field() ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-12"><label class="form-label" id="request-form-dataset-label" for="<?= $f('dataset') ?>">Dataset</label>
                <?= picker_field(['id' => $f('dataset'), 'name' => 'dataset', 'source' => 'any_dataset', 'value' => $v['dataset'], 'label' => $d !== null ? $d['title'] . ' (' . $d['key'] . ')' : null,
                    'labelledby' => 'request-form-dataset-label', 'title' => 'The dataset', 'required' => true]) ?></div>
            <div class="col-md-6"><label class="form-label" for="<?= $f('level') ?>">Level</label>
                <select class="form-select btn-touch" id="<?= $f('level') ?>" name="level">
                    <?php foreach (REQUEST_LEVELS as $l): ?><option value="<?= e($l) ?>"<?= $l === $v['level'] ? ' selected' : '' ?>><?= e(ucfirst(level_words($l))) ?></option><?php endforeach; ?>
                </select>
                <div class="form-text">Read shows the rows with personal and confidential columns masked; read unmasked shows them as they are.</div></div>
            <div class="col-md-6"><label class="form-label" for="<?= $f('wanted_until') ?>">Wanted until (optional)</label>
                <input type="date" class="form-control btn-touch" id="<?= $f('wanted_until') ?>" name="wanted_until" value="<?= e((string) $v['wanted_until']) ?>" min="<?= e(date('Y-m-d', time() + 86400)) ?>"></div>
            <div class="col-12"><label class="form-label" for="<?= $f('reason') ?>">Why you need it</label>
                <textarea class="form-control" id="<?= $f('reason') ?>" name="reason" rows="3" minlength="3" maxlength="2000" required placeholder="The quarterly sales review needs last year's orders by region."><?= e((string) $v['reason']) ?></textarea>
                <div class="form-text">The dataset's owner reads this and decides.</div></div>
        </div></div></div>
        <div class="d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="request-form-save-btn">Send the request</button>
            <?= hx_link($d !== null ? '/datasets/' . (int) $d['id'] : '/access', 'Cancel', 'btn btn-light btn-touch', 'id="request-form-cancel-btn"') ?>
        </div>
    </form>
</div>
