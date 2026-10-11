<?php
/**
 * The Preview tab (slice 2 — uploads-loads.md): the rows come from the query service through the door /datasets/{id}/preview, never at the
 * page's render unless this tab is the one asked for (?tab=preview — one audited read, and the page works without JavaScript); otherwise the
 * pane loads them when it is first shown (hx-trigger intersect once). Data: d, here (the dataset page's own)
 */
$id = (int) $d['dataset_id'];
$active = ($_GET['tab'] ?? '') === 'preview';
?>
<div id="dataset-preview" class="dataset-preview">
<?php if ($active):
    require_once dirname(__DIR__, 4) . '/app/features/preview/queries.php';
    require_once dirname(__DIR__, 4) . '/app/features/loads/queries.php';
    $pdo = db();
    $gate = require_dataset($pdo, (string) $id, 'discover');
    $asOf = request_string('as_of');
    $asOf = $asOf === '' ? null : (ctype_digit($asOf) ? (int) $asOf : $asOf);
    $p = preview($pdo, (int) current_member_id(), $id, $asOf); ?>
    <?= view('datasets/partials/preview-body.php', ['d' => $gate, 'p' => $p, 'prov' => request_bool('prov'), 'as_of' => $asOf,
        'snapshots' => has_right('loads.manage') ? dataset_snapshots($pdo, $id, 10) : [], 'mask_kinds' => preview_mask_kinds($pdo, $id, (string) $gate['my_level'])]) ?>
<?php else: ?>
    <div class="card" id="dataset-preview-lazy" hx-get="/datasets/<?= $id ?>/preview" hx-trigger="intersect once" hx-target="this" hx-swap="outerHTML"><div class="card-body">
        <div class="empty-state"><span class="avatar-text avatar-lg rounded"><span class="spinner-border spinner-border-sm text-primary" aria-hidden="true"></span></span>
            <div><div class="fw-semibold">Reading 100 rows…</div><div class="fs-12 text-muted">Through the query service, masked as you may see them.
                <a href="/datasets/<?= e((string) $d['key']) ?>?tab=preview" id="dataset-preview-open">Open the preview</a></div></div></div>
    </div></div>
<?php endif; ?>
</div>
