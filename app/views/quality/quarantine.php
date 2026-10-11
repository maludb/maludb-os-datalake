<?php
/** A curated dataset's quarantine (screen `quarantine`): the count per reason as filter chips, then the held rows (quarantine-table) — the reason first. Data: d, a, reason, page */
$key = (string) $d['key'];
$ok = ($a['status'] ?? '') === 'ok';
$cols = array_map(static fn ($c): string => is_array($c) ? (string) ($c['name'] ?? '') : (string) $c, (array) ($a['columns'] ?? []));
$order = array_values(array_unique(array_merge(array_filter($cols, static fn (string $c): bool => $c === '_dl_reason'),
    array_filter($cols, static fn (string $c): bool => !str_starts_with($c, '_dl_')), array_filter($cols, static fn (string $c): bool => str_starts_with($c, '_dl_') && $c !== '_dl_reason'))));
$idx = array_flip($cols);
$masked = array_map(static fn (string $m): string => substr($m, strrpos($m, '.') + 1), (array) ($a['masked_columns'] ?? []));
$base = '/datasets/' . $key . '/quarantine';
?>
<?= view('shared/page-header.php', ['id' => 'quarantine', 'title' => 'Quarantine — ' . $key, 'crumbs' => [['Home', '/'], ['Browse', '/browse'], [$key, '/datasets/' . $key . '?tab=quality'], ['Quarantine', null]]]) ?>
<div class="main-content" id="quarantine-content">
    <p class="fs-12 text-muted">The rows promotion held back, as they arrived — each with its reasons. They stay out of <span class="font-monospace"><?= e($key) ?></span> until a load brings them again, fixed.</p>
    <?php if (!$ok): ?>
        <div class="alert alert-warning" id="quarantine-refused"><?= e(lake_refusal_message($a)) ?></div>
    <?php else: ?>
        <div class="d-flex flex-wrap gap-2 mb-3" id="quarantine-reasons">
            <?= hx_link($base, 'All <span class="badge bg-light text-dark ms-1">' . number_format(array_sum((array) ($a['reasons'] ?? []))) . '</span>', 'btn btn-sm btn-touch ' . ($reason === null ? 'btn-primary' : 'btn-light'), 'id="quarantine-reason-all"') ?>
            <?php foreach ((array) ($a['reasons'] ?? []) as $r => $n): ?>
                <?= hx_link($base . '?reason=' . rawurlencode((string) $r), e((string) $r) . ' <span class="badge bg-light text-dark ms-1">' . number_format((int) $n) . '</span>',
                    'btn btn-sm btn-touch font-monospace ' . ($reason === (string) $r ? 'btn-primary' : 'btn-light'), 'id="quarantine-reason-' . e(preg_replace('/[^a-z0-9_-]/i', '-', (string) $r)) . '"') ?>
            <?php endforeach; ?>
        </div>
        <?php if (($a['rows'] ?? []) === []): ?>
            <div class="card"><div class="card-body"><div class="empty-state" id="quarantine-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-shield"></i></span>
                <div><div class="fw-semibold">Nothing held back</div><div class="fs-12 text-muted"><?= e((string) ($a['message'] ?? 'Every promoted row passed.')) ?></div></div></div></div></div>
        <?php else: ?>
            <?php if ($masked !== []): ?><div class="fs-12 text-muted mb-2" id="quarantine-masked">Masked for you: <?= e(implode(', ', $masked)) ?></div><?php endif; ?>
            <div class="card"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="quarantine-table">
                <thead><tr><?php foreach ($order as $c): ?><th class="text-nowrap<?= str_starts_with($c, '_dl_') && $c !== '_dl_reason' ? ' text-muted' : '' ?>"><?= e($c) ?><?= in_array($c, $masked, true) ? ' <i class="feather-eye-off" aria-label="masked"></i>' : '' ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($a['rows'] as $row): ?>
                    <tr><?php foreach ($order as $c): $v = $row[$idx[$c]] ?? null; ?><td class="<?= $c === '_dl_reason' ? 'fw-semibold text-danger font-monospace' : '' ?>"><?= $v === null ? '<span class="text-muted">∅</span>' : e(is_scalar($v) ? (string) $v : json_encode($v)) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div></div>
            <div class="d-flex gap-2 mt-2">
                <?php if ($page > 1): ?><?= hx_link($base . '?' . http_build_query(array_filter(['reason' => $reason, 'page' => $page - 1])), 'Newer', 'btn btn-light btn-sm btn-touch', 'id="quarantine-prev"') ?><?php endif; ?>
                <?php if (($a['next_offset'] ?? null) !== null): ?><?= hx_link($base . '?' . http_build_query(array_filter(['reason' => $reason, 'page' => $page + 1])), 'Older', 'btn btn-light btn-sm btn-touch', 'id="quarantine-next"') ?><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
