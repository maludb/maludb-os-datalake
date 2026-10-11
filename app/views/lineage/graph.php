<?php
/**
 * The lineage graph (screen `lineage-graph`): the dataset's title and key, a depth chooser (1 · 2 · 3 hops each way), the SVG in a card (it scales by its viewBox), the two hop lists below it
 * (#lineage-graph-up, #lineage-graph-down) and the impact of a change (#lineage-impact). Data: d, depth, up, down, impact, centre
 */
$dataset = (int) $d['id'];
$chip = static fn (int $n): string => hx_link('/datasets/' . $dataset . '/lineage?depth=' . $n, $n . ' hop' . ($n === 1 ? '' : 's'), 'btn btn-touch btn-sm ' . ($n === $depth ? 'btn-primary' : 'btn-light'), 'id="lineage-depth-' . $n . '"');
?>
<?= view('shared/page-header.php', ['id' => 'lineage-graph', 'title' => 'Lineage of ' . $d['title'], 'crumbs' => [['Browse', '/browse'], [$d['title'], '/datasets/' . $d['key']], ['Lineage', null]],
    'back' => ['/datasets/' . $d['key'] . '?tab=lineage', $d['title']]]) ?>
<div class="main-content" id="lineage-graph-content">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="dataset-key fw-semibold" id="lineage-graph-key"><?= e((string) $d['key']) ?></span>
        <div class="facet-chips ms-md-auto" id="lineage-graph-depths"><?= $chip(1) ?><?= $chip(2) ?><?= $chip(3) ?></div>
    </div>
    <div class="card mb-3"><div class="card-body p-2 lineage-graph-wrap" id="lineage-graph-card"><?= lineage_svg($up, $down, $centre, $depth) ?></div></div>
    <div class="row g-2 mb-3">
        <div class="col-12 col-md-6"><?= view('lineage/partials/hops.php', ['hops' => $up, 'id' => 'lineage-graph-up', 'title' => 'Made from', 'empty' => 'Nothing upstream is recorded: it was not loaded, promoted or made by a transform here.']) ?></div>
        <div class="col-12 col-md-6"><?= view('lineage/partials/hops.php', ['hops' => $down, 'id' => 'lineage-graph-down', 'title' => 'Used to make', 'empty' => 'Nothing is made from it yet.']) ?></div>
    </div>
    <?= view('lineage/partials/impact.php', ['impact' => $impact]) ?>
</div>
