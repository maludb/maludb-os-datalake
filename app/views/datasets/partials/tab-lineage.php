<?php
/**
 * The Lineage tab (slice 7 — transforms-products-lineage.md): where this data comes from and what is made from it, one hop each way as two lists (lineage_hops depth 1: the dataset or the
 * source, the edge's via — load, promote, transform — and when it was last seen, "n hidden" for the ones the viewer may not discover), Open the graph, the transform that makes it (when one does) and
 * a way to write one. Rendered with the page (two small reads). Data: d (the dataset page's own)
 */
require_once dirname(__DIR__, 4) . '/app/features/lineage/queries.php';
require_once dirname(__DIR__, 4) . '/app/features/lineage/present.php';
$pdo = db();
$id = (int) $d['dataset_id'];
$up = lineage_hops($pdo, $id, 'up', 1);
$down = lineage_hops($pdo, $id, 'down', 1);
$maker = one_row($pdo, 'SELECT transform_id, name FROM mcp_transforms WHERE output_dataset_id = :d', ['d' => $id]);
$mayMake = $maker === null && in_array($d['zone'], ['curated', 'product'], true) && has_right('transforms.manage') && in_array((string) ($d['my_level'] ?? ''), ['write', 'own'], true);
?>
<div id="dataset-lineage">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <div class="fw-semibold me-auto">One hop each way</div>
        <?= hx_link('/datasets/' . $id . '/lineage', '<i class="feather-git-merge me-1"></i>Open the graph', 'btn btn-light btn-sm btn-touch', 'id="dataset-lineage-graph-link"') ?>
    </div>
    <?php if ($maker !== null): ?><div class="fs-12 mb-2" id="dataset-lineage-maker"><i class="feather-shuffle me-1 text-muted"></i>Made by the transform <?= hx_link('/transforms/' . (int) $maker['transform_id'], e((string) $maker['name']), 'font-monospace') ?>.</div>
    <?php elseif ($mayMake): ?><div class="fs-12 mb-2" id="dataset-lineage-make"><?= hx_link('/transforms/new?output_dataset=' . $id, '<i class="feather-plus me-1"></i>Make it with a transform', 'btn btn-light btn-sm btn-touch', 'id="dataset-lineage-make-btn"') ?></div><?php endif; ?>
    <div class="row g-2">
        <div class="col-12 col-md-6"><?= view('lineage/partials/hops.php', ['hops' => $up, 'id' => 'lineage-up', 'title' => 'Made from', 'compact' => true,
            'empty' => 'Nothing upstream is recorded: it was not loaded, promoted or made by a transform here.']) ?></div>
        <div class="col-12 col-md-6"><?= view('lineage/partials/hops.php', ['hops' => $down, 'id' => 'lineage-down', 'title' => 'Used to make', 'compact' => true, 'empty' => 'Nothing is made from it yet.']) ?></div>
    </div>
</div>
