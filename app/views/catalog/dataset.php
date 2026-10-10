<?php
/**
 * The dataset page (screen `dataset-view`; catalog.md): the header, then the EIGHT tabs by anchor — every pane server-rendered, `?tab=`
 * chooses which shows (without JavaScript each tab is a link that reloads on it; with it Bootstrap switches panes in place). The frame is
 * slice 1's; each tab is its own partial `datasets/partials/tab-{name}.php`, and a tab whose data a later slice owns renders tab-pending.php
 * until that slice replaces the partial (divergence 1: never a link to a 404). The notes panel sits below the tabs.
 * Data: d, tab, columns, usage, history, history_page, notes, follow, may, here, notice, tag_suggestions
 */
?>
<?= view('datasets/partials/header.php', ['d' => $d, 'may' => $may, 'follow' => $follow, 'here' => $here]) ?>
<div class="main-content pt-2" id="dataset-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <ul class="nav nav-tabs dataset-tabs mb-3" role="tablist" id="dataset-tabs">
        <?php foreach (DATASET_TABS as $name => $label): $on = $name === $tab; ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link<?= $on ? ' active' : '' ?>" id="dataset-tab-<?= e($name) ?>-link" href="?tab=<?= e($name) ?>" data-bs-toggle="tab" data-bs-target="#dataset-tab-<?= e($name) ?>"
                   role="tab" aria-controls="dataset-tab-<?= e($name) ?>" aria-selected="<?= $on ? 'true' : 'false' ?>"><?= e($label) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
    <div class="tab-content" id="dataset-tab-content">
        <?php foreach (DATASET_TABS as $name => $label): ?>
            <div class="tab-pane fade<?= $name === $tab ? ' show active' : '' ?>" id="dataset-tab-<?= e($name) ?>" role="tabpanel" aria-labelledby="dataset-tab-<?= e($name) ?>-link">
                <?= view('datasets/partials/tab-' . $name . '.php', ['d' => $d, 'columns' => $columns, 'usage' => $usage, 'history' => $history, 'history_page' => $history_page,
                    'may' => $may, 'here' => $here, 'tag_suggestions' => $tag_suggestions]) ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?= view('datasets/partials/notes-panel.php', ['kind' => 'dataset', 'record' => (int) $d['dataset_id'], 'notes' => $notes, 'may_add' => true, 'here' => $here]) ?>
</div>
