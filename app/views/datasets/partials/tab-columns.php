<?php
/**
 * Columns (slice 1): a table at 1280, stacked cards at 375 — the name (its page), business name, logical type, description, the
 * classification badge, the personal kind ("proposed" until a person confirms), null % and distinct % from the latest profile, the
 * reference "→ {key}.{column}". Data: d, columns, here
 */
$id = (int) $d['dataset_id'];
$personal = static function (array $c): string {
    if ($c['personal_kind'] !== null) {
        return '<span class="badge text-bg-danger">' . e($c['personal_kind']) . '</span>';
    }
    if ($c['proposed_personal_kind'] !== null) {
        return '<span class="badge border border-danger text-danger" title="The classifier proposes it; a person confirms">' . e($c['proposed_personal_kind']) . ' · proposed</span>';
    }
    return '';
};
$pct = static fn ($v): string => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%';
$ref = static fn (array $c): string => $c['references_column'] === null ? '' : '→ ' . ($c['references_key'] !== null
    ? hx_link('/datasets/' . $c['references_key'], e($c['references_key'] . '.' . $c['references_column']), 'dataset-key') : '<span class="dataset-key">a dataset you may not see</span>');
?>
<?php if ($columns === []): ?>
    <div class="card"><div class="card-body"><div class="empty-state" id="dataset-columns-none"><span class="avatar-text avatar-lg rounded"><i class="feather-columns"></i></span>
        <div><div class="fw-semibold">No columns yet</div><div class="fs-12 text-muted">The writer records them when data first lands (a load, a promotion or a transform).</div></div></div></div></div>
<?php else: ?>
    <div class="card d-none d-md-block" id="dataset-columns-table"><div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Column</th><th>Meaning</th><th>Type</th><th>Classification</th><th class="text-end">Null</th><th class="text-end">Distinct</th></tr></thead>
            <tbody>
            <?php foreach ($columns as $c): $p = present_profile($c['profile'], (string) $c['classification'], $c['personal_kind'] ?? $c['proposed_personal_kind']); ?>
                <tr id="column-row-<?= (int) $c['column_id'] ?>">
                    <td class="text-nowrap"><?= hx_link(with_back('/datasets/' . $id . '/columns/' . rawurlencode((string) $c['name']), $here), '<span class="dataset-key text-reset">' . e($c['name']) . '</span>', 'fw-semibold') ?>
                        <?= $c['is_key'] ? '<span class="badge text-bg-light border ms-1">key</span>' : '' ?><?= $c['critical'] ? '<span class="badge text-bg-light border ms-1">critical</span>' : '' ?></td>
                    <td class="text-wrap" style="min-width: 16rem;">
                        <?php if ($c['business_name'] !== null): ?><div class="fw-semibold"><?= e($c['business_name']) ?></div><?php endif; ?>
                        <div class="fs-12 text-muted"><?= e(mb_substr((string) ($c['description'] ?? ''), 0, 240)) ?></div>
                        <?php if ($c['term_name'] !== null): ?><div class="fs-11"><i class="feather-book-open me-1"></i><?= hx_link('/glossary/' . (int) $c['glossary_term_id'], e($c['term_name'])) ?></div><?php endif; ?>
                        <div class="fs-11"><?= $ref($c) ?></div>
                    </td>
                    <td class="fs-12 text-nowrap"><?= e($c['logical_type'] ?? '—') ?><div class="text-muted"><?= e($c['physical_type'] ?? '') ?></div></td>
                    <td><span class="badge <?= e(classification_class((string) $c['classification'])) ?>"><?= e($c['classification']) ?></span> <?= $personal($c) ?></td>
                    <td class="text-end fs-12"><?= $pct($p['null_pct'] ?? null) ?></td>
                    <td class="text-end fs-12"><?= $pct($p['distinct_pct'] ?? null) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
    <div class="d-md-none" id="dataset-columns-cards">
        <?php foreach ($columns as $c): $p = present_profile($c['profile'], (string) $c['classification'], $c['personal_kind'] ?? $c['proposed_personal_kind']); ?>
            <div class="card mb-2 column-card" id="column-card-<?= (int) $c['column_id'] ?>"><div class="card-body py-3">
                <div class="d-flex justify-content-between gap-2">
                    <div class="min-w-0"><?= hx_link(with_back('/datasets/' . $id . '/columns/' . rawurlencode((string) $c['name']), $here), '<span class="column-name text-break">' . e($c['name']) . '</span>', 'fw-semibold') ?>
                        <?php if ($c['business_name'] !== null): ?><div class="fs-12"><?= e($c['business_name']) ?></div><?php endif; ?></div>
                    <div class="text-end flex-shrink-0 fs-11 text-muted"><?= e($c['logical_type'] ?? $c['physical_type'] ?? '') ?></div>
                </div>
                <?php if (($c['description'] ?? '') !== ''): ?><div class="fs-12 text-muted mt-1 text-break"><?= e(mb_substr((string) $c['description'], 0, 200)) ?></div><?php endif; ?>
                <div class="d-flex flex-wrap gap-1 mt-1"><span class="badge <?= e(classification_class((string) $c['classification'])) ?>"><?= e($c['classification']) ?></span> <?= $personal($c) ?></div>
                <div class="fs-11 text-muted mt-1">Null <?= $pct($p['null_pct'] ?? null) ?> · distinct <?= $pct($p['distinct_pct'] ?? null) ?><?= $c['references_column'] !== null ? ' · ' . $ref($c) : '' ?></div>
            </div></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
