<?php
/**
 * A dataset's contract (screen `contract-view`): the shown version's schema (contract-column-{n}-{field}), the key, the ordering, the SLA in
 * words, the versions; a draft's breaking changes (contract-breaking) and its impact (contract-impact) before Publish. Data: d, k, versions,
 * draft, breaking, impact, may, version, notice
 */
$key = (string) $d['key'];
$tz = member_timezone();
$odcs = $k === null ? null : '/datasets/' . $key . '/contract.odcs.yaml' . ($version !== '' ? '?version=' . rawurlencode($version) : '');
$actions = ($odcs !== null ? '<a class="btn btn-light btn-touch" href="' . e($odcs) . '" id="contract-download">Download ODCS</a> ' : '')
    . ($may['edit'] ? hx_link('/datasets/' . $key . '/contract/edit', $draft !== null ? 'Edit the draft' : 'Draft the next version', 'btn btn-primary btn-touch', 'id="contract-edit-btn"') : '');
?>
<?= view('shared/page-header.php', ['id' => 'contract-view', 'title' => 'Contract — ' . $key, 'crumbs' => [['Home', '/'], ['Browse', '/browse'], [$key, '/datasets/' . $key . '?tab=quality'], ['Contract', null]],
    'action' => $actions]) ?>
<div class="main-content" id="contract-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($k === null): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="contract-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-file-text"></i></span>
            <div><div class="fw-semibold">No contract yet</div><div class="fs-12 text-muted"><?= $d['zone'] === 'curated' ? 'A contract types this dataset from its raw input: the columns, the key, the SLA. Promotion types by it.' : 'A contract is a curated dataset\'s — it types the promotion from raw.' ?></div></div></div></div></div>
    <?php else: $sb = contract_status_badge((string) $k['status']); ?>
        <div class="card mb-3" id="contract-summary"><div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2"><span class="fs-5 fw-semibold" id="contract-version"><?= e($k['version']) ?></span>
                <span class="badge <?= e($sb[0]) ?>" id="contract-status"><?= e($sb[1]) ?></span>
                <?php if ($k['breaking']): ?><span class="badge bg-danger">Breaking</span><?php endif; ?>
                <?php if ($k['published_at'] !== null): ?><span class="fs-12 text-muted">published <?= e(format_ts($k['published_at'], $tz)) ?><?= $k['published_by_name'] ? ' by ' . e($k['published_by_name']) : '' ?></span><?php endif; ?></div>
            <dl class="row mb-0 fs-13">
                <dt class="col-4 col-md-2">Reads</dt><dd class="col-8 col-md-10 font-monospace" id="contract-raw"><?= e(contract_raw_key((array) $k['schema']) ?? '—') ?></dd>
                <dt class="col-4 col-md-2">Key</dt><dd class="col-8 col-md-10 font-monospace" id="contract-key"><?= e(implode(', ', (array) $k['key']) ?: '—') ?></dd>
                <dt class="col-4 col-md-2">Latest row by</dt><dd class="col-8 col-md-10 font-monospace" id="contract-ordering"><?= e((string) ($k['ordering_column'] ?? '_dl_loaded_at (when it arrived)')) ?></dd>
                <dt class="col-4 col-md-2">SLA</dt><dd class="col-8 col-md-10" id="contract-sla"><?= e(sla_words((array) $k['sla'])) ?></dd>
            </dl>
        </div></div>
        <div class="card mb-3"><div class="table-responsive"><table class="table mb-0 align-middle fs-12 contract-table" id="contract-schema">
            <thead><tr><th>Column</th><th>Type</th><th>Required</th><th>Unique</th><th>Key</th><th>Source</th><th>Classification</th><th>Description</th></tr></thead>
            <tbody>
            <?php foreach ((array) $k['schema'] as $n => $e): ?>
                <tr id="contract-column-<?= (int) $n ?>">
                    <td class="fw-semibold font-monospace" id="contract-column-<?= (int) $n ?>-name"><?= e($e['name']) ?></td>
                    <td id="contract-column-<?= (int) $n ?>-type"><?= e((string) ($e['logical_type'] ?? '')) ?> · <span class="font-monospace"><?= e((string) $e['physical_type']) ?></span></td>
                    <td><?= !empty($e['required']) ? 'yes' : '—' ?></td><td><?= !empty($e['unique']) ? 'yes' : '—' ?></td>
                    <td><?= in_array($e['name'], (array) $k['key'], true) ? '<i class="feather-key" aria-label="key"></i>' : '' ?></td>
                    <td class="font-monospace" id="contract-column-<?= (int) $n ?>-source"><?= e((string) ($e['source'] ?? '—')) ?></td>
                    <td><?= e((string) ($e['classification'] ?? '—')) ?><?= ($e['personal_kind'] ?? null) ? ' · ' . e($e['personal_kind']) : '' ?></td>
                    <td class="text-wrap" style="min-width:12rem"><?= e((string) ($e['description'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    <?php endif; ?>
    <?php if ($draft !== null && ($k === null || (int) $draft['contract_id'] !== (int) $k['contract_id'] || $k['status'] === 'draft')): ?>
        <div class="card mb-3 border-primary" id="contract-draft"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0 me-auto">Draft <?= e($draft['version']) ?></h5>
            <?php if ($k === null || (int) $draft['contract_id'] !== (int) $k['contract_id']): ?><?= hx_link('/datasets/' . $key . '/contract?version=' . rawurlencode((string) $draft['version']), 'See it', 'btn btn-light btn-sm btn-touch', 'id="contract-draft-see"') ?><?php endif; ?></div>
        <div class="card-body">
            <div id="contract-breaking" class="mb-2"><?php if ($breaking === []): ?><span class="fs-12 text-success">No breaking change against the active version.</span>
                <?php else: ?><div class="fs-12 fw-semibold text-danger">Breaking — this must be a new major version:</div>
                    <ul class="fs-12 mb-0"><?php foreach ($breaking as $b): ?><li><?= e(breaking_words($b)) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
            <?php if ($impact !== null): $pi = present_impact($impact); ?>
                <div id="contract-impact" class="fs-12 mb-3"><span class="fw-semibold">Publishing reaches:</span>
                    <?= $pi['datasets'] === [] && $pi['hidden_datasets'] === 0 ? 'no dataset downstream' : e(implode(', ', $pi['datasets'])) . ($pi['hidden_datasets'] > 0 ? ' and ' . $pi['hidden_datasets'] . ' more you may not see' : '') ?>;
                    <?= (int) $pi['saved_queries'] ?> saved quer<?= $pi['saved_queries'] === 1 ? 'y' : 'ies' ?>, <?= (int) $pi['metrics'] ?> metric<?= $pi['metrics'] === 1 ? '' : 's' ?>, <?= (int) $pi['transforms'] ?> transform<?= $pi['transforms'] === 1 ? '' : 's' ?>
                    <?= $pi['consumers'] !== [] ? '; read by ' . e(implode(', ', $pi['consumers'])) : '' ?>.</div>
            <?php endif; ?>
            <?php if ($may['publish']): ?>
                <form method="post" action="/quality/contracts/publish.php" hx-post="/quality/contracts/publish.php" hx-target="#flash" hx-confirm="Publish <?= e($draft['version']) ?>? Promotion types by it from then on." id="contract-publish-form">
                    <?= csrf_field() ?><input type="hidden" name="contract" value="<?= (int) $draft['contract_id'] ?>"><input type="hidden" name="return_to" value="/datasets/<?= e($key) ?>/contract">
                    <button type="submit" class="btn btn-success btn-touch" id="contract-publish">Publish <?= e($draft['version']) ?></button>
                </form>
            <?php endif; ?>
        </div></div>
    <?php endif; ?>
    <?php if ($versions !== []): ?>
        <div class="card" id="contract-versions"><div class="card-header"><h5 class="card-title mb-0">Versions</h5></div><div class="list-group list-group-flush">
        <?php foreach ($versions as $v): $vb = contract_status_badge((string) $v['status']); ?>
            <?= hx_link('/datasets/' . $key . '/contract?version=' . rawurlencode((string) $v['version']), '<span class="fw-semibold me-2">' . e($v['version']) . '</span><span class="badge ' . e($vb[0]) . ' me-1">' . e($vb[1]) . '</span>'
                . ($v['breaking'] ? '<span class="badge bg-danger me-1">Breaking</span>' : '') . '<span class="fs-11 text-muted">' . e(implode('; ', array_map('breaking_words', (array) $v['breaking_changes']))) . '</span>',
                'list-group-item list-group-item-action', 'id="contract-version-' . (int) $v['contract_id'] . '"') ?>
        <?php endforeach; ?>
        </div></div>
    <?php endif; ?>
</div>
