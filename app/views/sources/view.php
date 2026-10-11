<?php
/**
 * The source page (screen `source-view`; sources-pulls.md): the header, then the THREE tabs by anchor — every pane server-rendered, `?tab=` chooses which
 * shows (without JavaScript each tab is a link that reloads on it; with it Bootstrap switches panes in place — the dataset page's frame). Data: s, tab,
 * pulls, feeds, may, caps, here, notice
 */
$id = (int) $s['source_id'];
[$stateKey, $stateClass, $stateWord, $why] = source_state($s);
$tz = member_timezone();
$act = static function (string $name, string $label, string $icon, string $btnClass, string $confirm = '', array $hidden = []) use ($id): string {
    return '<form method="post" action="/sources/' . $name . '.php" hx-post="/sources/' . $name . '.php" hx-target="#flash" class="d-inline"' . ($confirm === '' ? '' : ' hx-confirm="' . e($confirm) . '"') . '>'
        . csrf_field() . '<input type="hidden" name="source" value="' . $id . '">'
        . implode('', array_map(static fn (string $k, string $v): string => '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">', array_keys($hidden), array_values($hidden)))
        . '<button type="submit" class="btn ' . $btnClass . ' btn-touch" id="source-' . $id . '-' . $name . '"><i class="' . $icon . ' me-1"></i>' . e($label) . '</button></form>';
};
?>
<?= view('shared/page-header.php', ['id' => 'source-view', 'title' => $s['name'], 'crumbs' => [['Sources', '/sources'], [$s['name'], null]], 'back' => back_link() ?? ['/sources', 'Sources']]) ?>
<div class="main-content pt-0 pb-2" id="source-view-header" data-source-id="<?= $id ?>">
    <div class="d-flex align-items-start gap-2 mb-2">
        <span class="avatar-text avatar-md rounded flex-shrink-0" aria-hidden="true"><i class="<?= e(connector_icon((string) $s['connector'])) ?>"></i></span>
        <div class="min-w-0">
            <div class="fw-semibold fs-5 text-break" id="source-view-name"><?= e($s['name']) ?></div>
            <div class="fs-12 text-muted" id="source-view-connector"><?= e($caps['label']) ?></div>
        </div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-1 mb-2" id="source-view-badges">
        <span class="badge bg-soft-<?= e($stateClass) ?> text-<?= e($stateClass === 'light' ? 'dark' : $stateClass) ?>" id="source-view-state"><?= e($stateWord) ?></span>
        <?php if ($why !== ''): ?><span class="fs-12 text-muted text-break" id="source-view-why"><?= e($why) ?></span><?php endif; ?>
        <?php if ((int) $s['consecutive_failures'] > 0): ?><span class="badge bg-soft-danger text-danger" id="source-view-failures"><?= (int) $s['consecutive_failures'] ?> failure<?= (int) $s['consecutive_failures'] === 1 ? '' : 's' ?> in a row</span><?php endif; ?>
    </div>
    <div class="row g-2 fs-12 text-muted mb-2">
        <div class="col-12 col-md-6">
            <div id="source-view-target"><i class="feather-database me-1"></i>Feeds
                <?= $s['target_key'] !== null ? hx_link('/datasets/' . $s['target_key'], e((string) $s['target_key']), 'dataset-key fw-semibold', 'id="source-view-target-link"') : '<span>no dataset yet</span>' ?></div>
            <div id="source-view-schedule"><i class="feather-clock me-1"></i><?= e(schedule_words((int) $s['schedule_minutes'])) ?></div>
        </div>
        <div class="col-12 col-md-6">
            <div id="source-view-last-ok"><i class="feather-check-circle me-1"></i>Last good pull <?= e(ago_words($s['last_ok_at']) ?? 'never') ?></div>
            <div id="source-view-made"><i class="feather-user me-1"></i>Added <?= e(format_ts($s['created_at'], $tz, 'M j, Y')) ?><?= $s['created_by_name'] !== null ? ' by ' . e((string) $s['created_by_name']) : '' ?></div>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2" id="source-view-actions">
        <?= $act('test', 'Test', 'feather-activity', 'btn-light-brand') ?>
        <?php if ($caps['pulls']): ?>
            <?php if ($may['pull']): ?><?= $act('pull', 'Pull now', 'feather-download-cloud', 'btn-primary') ?><?php endif; ?>
            <?php if ($may['pause']): ?><?= $act('pause', 'Pause', 'feather-pause', 'btn-light') ?><?php endif; ?>
            <?php if ($may['resume']): ?><?= $act('resume', 'Resume', 'feather-play', 'btn-primary') ?><?php endif; ?>
        <?php endif; ?>
        <details class="dataset-more" id="source-more">
            <summary class="btn btn-light btn-touch" id="source-more-btn" aria-label="More actions"><i class="feather-more-horizontal"></i></summary>
            <div class="dataset-more-menu" id="source-more-menu">
                <?= hx_link('/sources/' . $id . '/edit', '<i class="feather-edit-2 me-2"></i>Edit', 'dropdown-item', 'id="source-edit-link"') ?>
                <?php if ($may['credential']): ?><a class="dropdown-item" href="/sources/<?= $id ?>?tab=settings#source-credential-form" id="source-credential-link"><i class="feather-key me-2"></i>Credential</a><?php endif; ?>
                <form method="post" action="/sources/delete.php" hx-post="/sources/delete.php" hx-target="#flash"
                      hx-confirm="Delete <?= e($s['name']) ?>? Its pulls and its sealed credential go with it; the dataset it fed stays.">
                    <?= csrf_field() ?><input type="hidden" name="source" value="<?= $id ?>">
                    <button type="submit" class="dropdown-item text-danger" id="source-delete-btn"><i class="feather-trash-2 me-2"></i>Delete</button>
                </form>
            </div>
        </details>
    </div>
</div>
<div class="main-content pt-2" id="source-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <ul class="nav nav-tabs dataset-tabs mb-3" role="tablist" id="source-tabs">
        <?php foreach (SOURCE_TABS as $name => $label): $on = $name === $tab; ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link<?= $on ? ' active' : '' ?>" id="source-tab-<?= e($name) ?>-link" href="?tab=<?= e($name) ?>" data-bs-toggle="tab" data-bs-target="#source-tab-<?= e($name) ?>"
                   role="tab" aria-controls="source-tab-<?= e($name) ?>" aria-selected="<?= $on ? 'true' : 'false' ?>"><?= e($label) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
    <div class="tab-content" id="source-tab-content">
        <div class="tab-pane fade<?= $tab === 'settings' ? ' show active' : '' ?>" id="source-tab-settings" role="tabpanel" aria-labelledby="source-tab-settings-link">
            <?= view('sources/partials/settings-dl.php', ['s' => $s, 'caps' => $caps, 'may' => $may, 'here' => $here]) ?>
        </div>
        <div class="tab-pane fade<?= $tab === 'pulls' ? ' show active' : '' ?>" id="source-tab-pulls" role="tabpanel" aria-labelledby="source-tab-pulls-link">
            <?= view('sources/partials/pulls-table.php', ['s' => $s, 'pulls' => $pulls]) ?>
        </div>
        <div class="tab-pane fade<?= $tab === 'feeds' ? ' show active' : '' ?>" id="source-tab-feeds" role="tabpanel" aria-labelledby="source-tab-feeds-link">
            <?= view('sources/partials/feeds.php', ['s' => $s, 'feeds' => $feeds]) ?>
        </div>
    </div>
</div>
