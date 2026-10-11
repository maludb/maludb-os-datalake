<?php
/**
 * The preview itself (#preview-table at 768 and up; one record at a time below with Prev / Next — pure CSS anchors, no new read): a masked
 * column's header is marked "masked for you" (preview-masked-{column}, the mask's kind on hover); the as-of picker for engineers; a refusal's
 * sentence with Request access; the engine away. Data: d, p (preview()), prov, as_of, snapshots, mask_kinds
 */
$id = (int) $d['id'];
$cols = [];
foreach ($p['columns'] as $i => $c) {
    if ($prov || !str_starts_with((string) $c['name'], '_dl_')) {
        $cols[$i] = (string) $c['name'];
    }
}
$masked = array_flip($p['masked_columns']);
$requestable = in_array($p['refusal_rule'], ['discover_only', 'not_readable', 'not_shared'], true);
$door = '/datasets/' . $id . '/preview';
$q = static fn (array $x): string => $door . '?' . http_build_query(array_filter($x + ['as_of' => $as_of, 'prov' => $prov ? 1 : null], static fn ($v) => $v !== null && $v !== ''));
?>
<div class="card" id="preview-card"><div class="card-body">
<?php if ($p['status'] !== 'ok'): ?>
    <?php if ($p['refusal_rule'] === 'engine_unavailable' || $p['refusal_rule'] === 'busy'): ?>
        <div class="empty-state" id="preview-engine-down"><span class="avatar-text avatar-lg rounded"><i class="feather-cloud-off"></i></span>
            <div><div class="fw-semibold">The engine is not answering</div><div class="fs-12 text-muted"><?= e((string) $p['message']) ?></div>
                <a href="/datasets/<?= e((string) $d['key']) ?>?tab=preview" class="fs-12 fw-semibold" hx-get="<?= e($door) ?>" hx-target="#preview-card" hx-swap="outerHTML" id="preview-retry">Try again</a></div></div>
    <?php else: ?>
        <div class="empty-state" id="preview-refused"><span class="avatar-text avatar-lg rounded"><i class="feather-lock"></i></span>
            <div><div class="fw-semibold">You may not read these rows</div><div class="fs-12 text-muted" id="preview-refusal"><?= e(ucfirst((string) $p['message'])) ?></div>
                <?php if ($requestable && has_right('access.request')): ?>
                    <a class="btn btn-primary btn-touch btn-sm mt-2" href="/access/requests/new?<?= e(http_build_query(['dataset' => $id, 'level' => 'read'])) ?>" id="preview-request-access">Request access</a>
                <?php endif; ?></div></div>
    <?php endif; ?>
<?php else: ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2 fs-12">
        <span class="text-muted" id="preview-count"><?= count($p['rows']) ?> row<?= count($p['rows']) === 1 ? '' : 's' ?><?= $p['truncated'] ? ' (the first)' : '' ?><?= $p['as_of'] !== null ? ' · snapshot ' . (int) $p['as_of'] : '' ?></span>
        <?php if ($masked !== []): ?><span class="badge bg-soft-warning text-warning" id="preview-masked-count"><?= count($masked) ?> column<?= count($masked) === 1 ? '' : 's' ?> masked for you</span><?php endif; ?>
        <a class="ms-auto" href="<?= e($q(['prov' => $prov ? null : 1])) ?>" hx-get="<?= e($q(['prov' => $prov ? null : 1])) ?>" hx-target="#preview-card" hx-swap="outerHTML" id="preview-prov-toggle"><?= $prov ? 'Hide' : 'Show' ?> provenance</a>
        <?php if ($snapshots !== []): ?>
            <form method="get" action="<?= e($door) ?>" hx-get="<?= e($door) ?>" hx-target="#preview-card" hx-swap="outerHTML" hx-trigger="change from:#preview-as-of" class="d-flex gap-1" id="preview-as-of-form">
                <label class="visually-hidden" for="preview-as-of">As of</label>
                <select class="form-select form-select-sm" id="preview-as-of" name="as_of"><option value="">Latest</option>
                    <?php foreach ($snapshots as $s): ?><option value="<?= (int) $s['snapshot_id'] ?>"<?= (string) $as_of === (string) $s['snapshot_id'] ? ' selected' : '' ?>>Snapshot <?= (int) $s['snapshot_id'] ?> — <?= e((string) $s['what']) ?></option><?php endforeach; ?>
                </select><?php if ($prov): ?><input type="hidden" name="prov" value="1"><?php endif; ?><noscript><button type="submit" class="btn btn-sm btn-light">Show</button></noscript></form>
        <?php endif; ?>
        <?php if ($p['query_run_id'] !== null): ?><span class="text-muted" id="preview-run">read #<?= (int) $p['query_run_id'] ?></span><?php endif; ?>
    </div>
    <?php if ($p['rows'] === []): ?>
        <div class="fs-12 text-muted" id="preview-empty"><?= e((string) ($p['message'] ?? 'No rows.')) ?></div>
    <?php else: ?>
        <div class="table-responsive d-none d-md-block"><table class="table table-sm table-striped fs-12 mb-0 preview-table" id="preview-table">
            <thead><tr><?php foreach ($cols as $c): $m = isset($masked[$c]); ?>
                <th class="text-nowrap<?= $m ? ' is-masked' : '' ?>"<?= $m ? ' id="preview-masked-' . e($c) . '" title="masked for you: ' . e((string) ($mask_kinds[$c] ?? 'masked')) . '"' : '' ?>><?= e($c) ?><?= $m ? ' <span class="badge bg-warning text-dark fw-normal">masked for you</span>' : '' ?></th>
            <?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($p['rows'] as $r): ?><tr><?php foreach ($cols as $i => $c): ?><td class="text-nowrap<?= isset($masked[$c]) ? ' is-masked' : '' ?>"><?= $r[$i] === null ? '<span class="text-muted">∅</span>' : e(mb_substr(is_scalar($r[$i]) ? (string) $r[$i] : json_encode($r[$i]), 0, 120)) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
        </table></div>
        <div class="d-md-none preview-records" id="preview-records">
            <?php $n = count($p['rows']); foreach ($p['rows'] as $k => $r): ?>
                <div class="preview-record" id="preview-record-<?= $k + 1 ?>">
                    <div class="d-flex justify-content-between align-items-center mb-2 fs-12">
                        <?= $k > 0 ? '<a href="#preview-record-' . $k . '" class="btn btn-sm btn-light btn-touch" id="preview-prev-' . ($k + 1) . '">Prev</a>' : '<span></span>' ?>
                        <span class="text-muted">Record <?= $k + 1 ?> of <?= $n ?></span>
                        <?= $k + 1 < $n ? '<a href="#preview-record-' . ($k + 2) . '" class="btn btn-sm btn-light btn-touch" id="preview-next-' . ($k + 1) . '">Next</a>' : '<span></span>' ?>
                    </div>
                    <dl class="mb-0"><?php foreach ($cols as $i => $c): ?>
                        <dt><?= e($c) ?><?= isset($masked[$c]) ? ' <span class="badge bg-warning text-dark fw-normal">masked for you</span>' : '' ?></dt>
                        <dd class="<?= isset($masked[$c]) ? 'is-masked' : '' ?>"><?= $r[$i] === null ? '∅' : e(mb_substr(is_scalar($r[$i]) ? (string) $r[$i] : json_encode($r[$i]), 0, 400)) ?></dd>
                    <?php endforeach; ?></dl>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div></div>
