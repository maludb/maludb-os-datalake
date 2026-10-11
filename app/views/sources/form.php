<?php
/**
 * A source's form (screens `source-add` and `source-edit`; sources-pulls.md). Step 1 of Add (no connector): the five connectors as cards. Step 2 and Edit:
 * the name; the raw dataset it lands in (the record picker — or the name of a NEW one, which is made); the connector's own fields (partials/form-*.php);
 * the schedule and, for a file fetched politely, the rate. The credential is sealed on the source's page, never here. Data: s (null on add), connector (null at step 1), v
 */
if ($connector === null) {
    echo view('shared/page-header.php', ['id' => 'source-form', 'title' => 'Add a source', 'crumbs' => [['Sources', '/sources'], ['Add a source', null]], 'back' => ['/sources', 'Sources']]);
    echo view('sources/partials/connector-picker.php');
    return;
}
$editing = $s !== null;
$id = $editing ? (int) $s['source_id'] : null;
$caps = dl_fetch_connectors()[$connector]['capabilities'];
$field = static fn (string $n): string => 'source-form-field-' . $n;
$presets = [0 => 'Manual — only when someone pulls it', 60 => 'Every hour', 360 => 'Every 6 hours', 1440 => 'Every day', 10080 => 'Every week'];
$minutes = (int) $v['schedule_minutes'];
if (!isset($presets[$minutes])) {
    $presets[$minutes] = schedule_words($minutes);
}
ksort($presets);
?>
<?= view('shared/page-header.php', ['id' => 'source-form', 'title' => $editing ? 'Edit ' . $s['name'] : 'Add ' . lcfirst($caps['label']),
    'crumbs' => $editing ? [['Sources', '/sources'], [$s['name'], '/sources/' . $id], ['Edit', null]] : [['Sources', '/sources'], ['Add a source', '/sources/new'], [$caps['label'], null]],
    'back' => $editing ? ['/sources/' . $id, $s['name']] : ['/sources/new', 'the connectors']]) ?>
<div class="main-content" id="source-form-content">
    <form method="post" action="/sources/save.php" hx-post="/sources/save.php" hx-target="#flash" id="source-form" class="page-header-form">
        <?= csrf_field() ?><input type="hidden" name="connector" value="<?= e($connector) ?>">
        <?php if ($editing): ?><input type="hidden" name="source" value="<?= $id ?>"><input type="hidden" name="return_to" value="/sources/<?= $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-12"><div class="d-flex align-items-center gap-2"><span class="avatar-text avatar-md rounded" aria-hidden="true"><i class="<?= e($caps['icon']) ?>"></i></span>
                <div><div class="fw-semibold" id="source-form-connector"><?= e($caps['label']) ?></div><div class="fs-12 text-muted"><?= e($caps['description']) ?></div></div></div></div>
            <div class="col-12"><label class="form-label" for="<?= $field('name') ?>">Name</label>
                <input type="text" class="form-control btn-touch" id="<?= $field('name') ?>" name="name" value="<?= e((string) $v['name']) ?>" maxlength="200" required autofocus
                       placeholder="<?= e($connector === 'k7' ? 'GL account balances' : ($connector === 'postgres' ? 'Orders in the shop database' : 'Daily sales file')) ?>"></div>
            <div class="col-md-7"><label class="form-label" id="source-form-target-label" for="<?= $field('target_dataset') ?>">Lands in the raw dataset</label>
                <?= picker_field(['id' => $field('target_dataset'), 'name' => 'target_dataset', 'source' => 'raw_dataset', 'value' => $v['target_dataset'], 'labelledby' => 'source-form-target-label',
                    'title' => 'The raw dataset', 'placeholder' => 'Choose a raw dataset']) ?>
                <div class="form-text">One source reads one thing into one raw dataset — every pull appends to it.</div></div>
            <div class="col-md-5"><label class="form-label" for="<?= $field('target_name') ?>">…or name a new one</label>
                <div class="input-group"><span class="input-group-text">raw.</span>
                    <input type="text" class="form-control btn-touch" id="<?= $field('target_name') ?>" name="target_name" value="<?= e((string) $v['target_name']) ?>" maxlength="63" pattern="[a-z][a-z0-9_]{0,62}" autocomplete="off" placeholder="gl_account_balances"></div>
                <div class="form-text">Lower case, digits and underscores. Used only when no dataset is chosen.</div></div>
        </div></div></div>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <h6 class="fs-12 text-uppercase text-muted mb-0">Where it comes from</h6>
            <?= view('sources/partials/form-' . $connector . '.php', ['v' => $v, 'field' => $field]) ?>
        </div></div></div>
        <?php if ($caps['pulls']): ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <h6 class="fs-12 text-uppercase text-muted mb-0">When</h6>
            <div class="col-md-6"><label class="form-label" for="<?= $field('schedule_minutes') ?>">Pull it</label>
                <select class="form-select btn-touch" id="<?= $field('schedule_minutes') ?>" name="schedule_minutes">
                    <?php foreach ($presets as $m => $label): ?><option value="<?= (int) $m ?>"<?= $minutes === $m ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select><div class="form-text">The worker pulls it when it is due; a person can pull it now at any time.</div></div>
            <?php if (in_array($connector, ['http_file', 'sftp_file'], true)): ?>
                <div class="col-md-3"><label class="form-label" for="<?= $field('rate_per_second') ?>">Requests a second</label>
                    <input type="number" class="form-control btn-touch" id="<?= $field('rate_per_second') ?>" name="rate_per_second" min="0.1" max="10" step="0.1" value="<?= e((string) $v['rate_per_second']) ?>"></div>
            <?php endif; ?>
            <?php if ($connector === 'http_file'): ?>
                <div class="col-12"><label class="form-label" for="<?= $field('user_agent') ?>">User-agent <span class="text-muted">(optional)</span></label>
                    <input type="text" class="form-control btn-touch" id="<?= $field('user_agent') ?>" name="user_agent" value="<?= e((string) $v['user_agent']) ?>" maxlength="200" placeholder="MaluDB-DataLake/1.0 (+this address) — the default says who we are">
                    <div class="form-text">The site sees this name. robots.txt is honoured; a site that refuses us pauses the source.</div></div>
            <?php endif; ?>
        </div></div></div>
        <?php endif; ?>
        <div class="d-flex gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="source-form-save-btn"><?= $editing ? 'Save' : 'Add the source' ?></button>
            <?= hx_link($editing ? '/sources/' . $id : '/sources/new', 'Cancel', 'btn btn-light btn-touch', 'id="source-form-cancel-btn"') ?>
        </div>
    </form>
</div>
