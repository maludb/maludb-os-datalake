<?php
/**
 * The transform page (screen `transform-view`): the header, then the FOUR tabs by anchor — every pane server-rendered, `?tab=` chooses which shows (without JavaScript each tab is a link
 * that reloads on it; with it Bootstrap switches panes in place). SQL (read-only; hidden when the viewer may not read every input), Inputs and output, Runs (polled), Lineage (the edges
 * it makes). Data: t, tab, runs, edges, may, busy, here, notice
 */
$id = (int) $t['transform_id'];
[$stateKey, $stateClass, $stateWord] = transform_state($t);
$tz = member_timezone();
$act = static function (string $name, string $label, string $icon, string $btnClass, string $confirm = '', array $hidden = []) use ($id): string {
    return '<form method="post" action="/transforms/' . $name . '.php" hx-post="/transforms/' . $name . '.php" hx-target="#flash" class="d-inline"' . ($confirm === '' ? '' : ' hx-confirm="' . e($confirm) . '"') . '>'
        . csrf_field() . '<input type="hidden" name="transform" value="' . $id . '">'
        . implode('', array_map(static fn (string $k, string $v): string => '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">', array_keys($hidden), array_values($hidden)))
        . '<button type="submit" class="btn ' . $btnClass . ' btn-touch" id="transform-' . $id . '-' . $name . '"><i class="' . $icon . ' me-1"></i>' . e($label) . '</button></form>';
};
?>
<?= view('shared/page-header.php', ['id' => 'transform-view', 'title' => $t['name'], 'crumbs' => [['Transforms', '/transforms'], [$t['name'], null]], 'back' => back_link() ?? ['/transforms', 'Transforms']]) ?>
<div class="main-content pt-0 pb-2" id="transform-view-header" data-transform-id="<?= $id ?>">
    <div class="d-flex align-items-start gap-2 mb-2">
        <span class="avatar-text avatar-md rounded flex-shrink-0" aria-hidden="true"><i class="feather-shuffle"></i></span>
        <div class="min-w-0">
            <div class="fw-semibold fs-5 text-break" id="transform-view-name"><?= e($t['name']) ?></div>
            <div class="fs-12 text-muted" id="transform-view-version">version <?= (int) $t['version'] ?> · <?= $t['mode'] === 'incremental_merge' ? 'incremental merge' : 'replace' ?> · <?= e(cron_words($t['schedule'])) ?></div>
        </div>
    </div>
    <?php if ($t['description'] !== null && $t['description'] !== ''): ?><div class="fs-13 text-break mb-2" id="transform-view-description"><?= e((string) $t['description']) ?></div><?php endif; ?>
    <div class="d-flex flex-wrap align-items-center gap-1 mb-2" id="transform-view-badges">
        <span class="badge bg-soft-<?= e($stateClass) ?> text-<?= e($stateClass) ?>" id="transform-view-state"><?= e($stateWord) ?></span>
        <?php if ($t['last_run_id'] !== null): [$bc, $bw] = transform_run_badge((string) $t['last_status']); ?><span class="badge <?= e($bc) ?>" id="transform-view-last-status">Last run: <?= e($bw) ?></span><?php endif; ?>
        <?php if ($t['frozen_at'] === null): ?><span class="fs-12 text-muted" id="transform-view-not-frozen">Its SQL changed and its owner's level was not frozen again — Edit it and save to run it.</span><?php endif; ?>
    </div>
    <div class="row g-2 fs-12 text-muted mb-2">
        <div class="col-12 col-md-6">
            <div id="transform-view-output"><i class="feather-arrow-right-circle me-1"></i>Makes <?= hx_link('/datasets/' . $t['output_key'], e((string) $t['output_key']), 'dataset-key fw-semibold', 'id="transform-view-output-link"') ?></div>
            <div id="transform-view-owner"><i class="feather-user me-1"></i>Frozen at <?= e((string) ($t['owner_name'] ?? 'its owner')) ?>'s level<?= $t['frozen_at'] !== null ? ' on ' . e(format_ts($t['frozen_at'], $tz, 'M j, Y')) : '' ?></div>
        </div>
        <div class="col-12 col-md-6">
            <div id="transform-view-updated"><i class="feather-database me-1"></i>The dataset: <?= $t['output_rows'] === null ? 'no rows yet' : e(count_words((int) $t['output_rows'])) . ' rows, data ' . e(ago_words($t['output_updated_at']) ?? 'never loaded') ?></div>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2" id="transform-view-actions">
        <?php if ($may['run']): ?><?= $act('run', 'Run', 'feather-play', 'btn-primary') ?><?php endif; ?>
        <?php if ($may['enable']): ?><?= $t['enabled'] ? $act('enable', 'Disable', 'feather-pause', 'btn-light', '', ['enabled' => 'no']) : $act('enable', 'Enable', 'feather-play-circle', 'btn-light', '', ['enabled' => 'yes']) ?><?php endif; ?>
        <?php if ($may['edit'] || $may['delete']): ?>
        <details class="dataset-more" id="transform-more">
            <summary class="btn btn-light btn-touch" id="transform-more-btn" aria-label="More actions"><i class="feather-more-horizontal"></i></summary>
            <div class="dataset-more-menu" id="transform-more-menu">
                <?php if ($may['edit']): ?><?= hx_link('/transforms/' . $id . '/edit', '<i class="feather-edit-2 me-2"></i>Edit', 'dropdown-item', 'id="transform-edit-link"') ?><?php endif; ?>
                <?php if ($may['delete']): ?>
                <form method="post" action="/transforms/delete.php" hx-post="/transforms/delete.php" hx-target="#flash"
                      hx-confirm="Delete <?= e($t['name']) ?>? Its runs go with it; <?= e((string) $t['output_key']) ?> stays, with its data.">
                    <?= csrf_field() ?><input type="hidden" name="transform" value="<?= $id ?>">
                    <button type="submit" class="dropdown-item text-danger" id="transform-delete-btn"><i class="feather-trash-2 me-2"></i>Delete</button>
                </form>
                <?php endif; ?>
            </div>
        </details>
        <?php endif; ?>
    </div>
</div>
<div class="main-content pt-2" id="transform-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <ul class="nav nav-tabs dataset-tabs mb-3" role="tablist" id="transform-tabs">
        <?php foreach (TRANSFORM_TABS as $name => $label): $on = $name === $tab; ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link<?= $on ? ' active' : '' ?>" id="transform-tab-<?= e($name) ?>-link" href="?tab=<?= e($name) ?>" data-bs-toggle="tab" data-bs-target="#transform-tab-<?= e($name) ?>"
                   role="tab" aria-controls="transform-tab-<?= e($name) ?>" aria-selected="<?= $on ? 'true' : 'false' ?>"><?= e($label) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
    <div class="tab-content" id="transform-tab-content">
        <div class="tab-pane fade<?= $tab === 'sql' ? ' show active' : '' ?>" id="transform-tab-sql" role="tabpanel" aria-labelledby="transform-tab-sql-link">
            <div class="card"><div class="card-body">
            <?php if ($t['may_read_sql']): ?>
                <pre class="sql-readonly font-monospace fs-13 mb-0" id="transform-sql"><?= e((string) $t['sql']) ?></pre>
            <?php else: ?>
                <div class="empty-state" id="transform-sql-hidden"><span class="avatar-text avatar-lg rounded"><i class="feather-lock"></i></span>
                    <div><div class="fw-semibold">You may not read every input</div><div class="fs-12 text-muted">The SQL names datasets you may not read, so it is not shown. Ask the owner, or request access to the inputs.</div></div></div>
            <?php endif; ?>
            </div></div>
        </div>
        <div class="tab-pane fade<?= $tab === 'io' ? ' show active' : '' ?>" id="transform-tab-io" role="tabpanel" aria-labelledby="transform-tab-io-link">
            <div class="row g-2">
                <div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header"><h5 class="card-title mb-0">Reads</h5></div><div class="card-body" id="transform-inputs">
                    <?php if ($t['inputs'] === [] && (int) $t['input_hidden'] === 0): ?><div class="fs-12 text-muted">No inputs yet — it has not been saved against any dataset.</div><?php endif; ?>
                    <?php foreach ($t['inputs'] as $i): ?>
                        <div class="mb-1"><?= hx_link('/datasets/' . $i['key'], e((string) $i['key']), 'dataset-key') ?> <span class="badge text-bg-<?= e(zone_color((string) $i['zone'])) ?> zone-chip"><?= e((string) $i['zone']) ?></span>
                            <div class="fs-11 text-muted"><?= e((string) $i['title']) ?></div></div>
                    <?php endforeach; ?>
                    <?php if ((int) $t['input_hidden'] > 0): ?><div class="mt-2" id="transform-inputs-hidden"><span class="badge bg-soft-secondary text-secondary"><?= (int) $t['input_hidden'] ?> hidden</span>
                        <span class="fs-12 text-muted">input<?= (int) $t['input_hidden'] === 1 ? '' : 's' ?> you may not discover.</span></div><?php endif; ?>
                </div></div></div>
                <div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header"><h5 class="card-title mb-0">Makes</h5></div><div class="card-body" id="transform-output-card">
                    <div><?= hx_link('/datasets/' . $t['output_key'], e((string) $t['output_key']), 'dataset-key') ?> <span class="badge text-bg-<?= e(zone_color((string) $t['output_zone'])) ?> zone-chip"><?= e((string) $t['output_zone']) ?></span></div>
                    <div class="fs-11 text-muted mb-2"><?= e((string) $t['output_title']) ?></div>
                    <div class="fs-12" id="transform-output-mode"><?= $t['mode'] === 'incremental_merge' ? 'Merged into the dataset by its key: ' . ($t['output_key_columns'] === [] ? '<span class="text-danger">none set</span>' : '<code>' . e(implode(', ', $t['output_key_columns'])) . '</code>') : 'The whole dataset is replaced on each run.' ?></div>
                    <div class="fs-12 text-muted mt-2" id="transform-output-frozen">Read at <?= e((string) ($t['owner_name'] ?? 'the owner')) ?>'s level, frozen when the SQL was saved: what was masked for them arrives masked in the product, and stays so.</div>
                </div></div></div>
            </div>
        </div>
        <div class="tab-pane fade<?= $tab === 'runs' ? ' show active' : '' ?>" id="transform-tab-runs" role="tabpanel" aria-labelledby="transform-tab-runs-link">
            <?= view('transforms/partials/runs-table.php', ['t' => $t, 'runs' => $runs]) ?>
        </div>
        <div class="tab-pane fade<?= $tab === 'lineage' ? ' show active' : '' ?>" id="transform-tab-lineage" role="tabpanel" aria-labelledby="transform-tab-lineage-link">
            <?php if ($edges === []): ?>
                <div class="card"><div class="card-body"><div class="empty-state" id="transform-lineage-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-git-merge"></i></span>
                    <div><div class="fw-semibold">No edges yet</div><div class="fs-12 text-muted">The first run records where the data came from.</div></div></div></div></div>
            <?php else: ?>
                <div class="card"><div class="list-group list-group-flush" id="transform-edges">
                <?php foreach ($edges as $e): ?>
                    <div class="list-group-item" id="transform-edge-<?= (int) $e['edge_id'] ?>">
                        <?= hx_link('/datasets/' . $e['from_key'], e((string) $e['from_key']), 'dataset-key') ?> <i class="feather-arrow-right mx-1 text-muted"></i> <?= hx_link('/datasets/' . $e['to_key'], e((string) $e['to_key']), 'dataset-key') ?>
                        <span class="badge bg-soft-success text-success ms-1">transform</span>
                        <div class="fs-11 text-muted">last seen <?= e(ago_words($e['last_seen']) ?? '') ?> · <?= (int) count($e['column_map']) ?> column<?= count($e['column_map']) === 1 ? '' : 's' ?> traced</div>
                    </div>
                <?php endforeach; ?>
                </div></div>
            <?php endif; ?>
        </div>
    </div>
</div>
