<?php
/**
 * The glossary (screen `glossary`; catalog.md): terms as cards grouped by their category (the parent term) — name, status, the first line
 * of the definition, how many columns carry it; draft and approved (deprecated on request). Data: q, status, result, may_edit, notice
 */
$url = static function (array $change) use ($q, $status): string {
    $p = array_filter(array_merge(['q' => $q, 'status' => $status], $change), static fn ($v) => $v !== null && $v !== '');
    return '/glossary' . ($p === [] ? '' : '?' . http_build_query($p));
};
$parentIds = array_flip(array_map('intval', array_filter(array_column($result['rows'], 'parent_id'), static fn ($v) => $v !== null)));
$groups = [];
foreach ($result['rows'] as $t) {
    $groups[$t['parent_name'] ?? (isset($parentIds[(int) $t['term_id']]) ? $t['name'] : 'Uncategorised')][] = $t;
}
uksort($groups, static fn (string $a, string $b): int => ($a === 'Uncategorised') <=> ($b === 'Uncategorised') ?: strcasecmp($a, $b));
?>
<?= view('shared/page-header.php', ['id' => 'glossary', 'title' => 'Glossary', 'crumbs' => [['Home', '/'], ['Glossary', null]],
    'action' => '<div class="d-flex gap-2">' . hx_link('/tags', '<i class="feather-tag me-1"></i>Tags', 'btn btn-light btn-touch', 'id="glossary-tags-btn"')
        . ($may_edit ? hx_link('/glossary/new', '<i class="feather-plus me-1"></i>New term', 'btn btn-primary btn-touch', 'id="glossary-add-btn"') : '') . '</div>']) ?>
<div class="main-content" id="glossary-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/glossary" class="mb-2" id="glossary-search" role="search">
        <?php if ($status !== null): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <div class="input-group"><input type="search" name="q" id="glossary-field-q" class="form-control btn-touch" value="<?= e($q) ?>" placeholder="A term, a synonym, words of a definition" maxlength="120" aria-label="Search the glossary">
            <button type="submit" class="btn btn-primary btn-touch" id="glossary-search-btn" aria-label="Search"><i class="feather-search"></i></button></div>
    </form>
    <div class="facet-chips mb-3" id="glossary-statuses">
        <?php foreach ([null => 'Draft and approved', 'draft' => 'Draft', 'approved' => 'Approved', 'deprecated' => 'Deprecated'] as $k => $label): $on = $status === ($k === '' ? null : $k); ?>
            <?= hx_link($url(['status' => $k === '' ? null : $k]), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="glossary-status-' . e($k === '' ? 'live' : $k) . '"') ?>
        <?php endforeach; ?>
    </div>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="glossary-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-book-open"></i></span>
            <div><div class="fw-semibold"><?= $q !== '' ? 'No term matches “' . e($q) . '”' : 'No terms yet' ?></div><div class="fs-12 text-muted">A term says what a word means here — revenue, an active customer — once, for every dataset.</div></div></div></div></div>
    <?php endif; ?>
    <?php foreach ($groups as $category => $terms): ?>
        <h6 class="fs-12 text-uppercase text-muted mt-3 mb-2"><?= e($category) ?></h6>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2">
            <?php foreach ($terms as $t): $b = term_status_badge((string) $t['status']); ?>
                <div class="col"><div class="card h-100 dataset-card" id="term-card-<?= (int) $t['term_id'] ?>"><div class="card-body py-3">
                    <div class="d-flex justify-content-between gap-2"><div class="fw-semibold text-break"><?= hx_link(with_back('/glossary/' . (int) $t['term_id'], $url([])), e($t['name']), 'text-dark') ?></div>
                        <?php if ($t['status'] !== 'approved'): ?><span class="badge <?= e($b[0]) ?> align-self-start"><?= e($b[1]) ?></span><?php endif; ?></div>
                    <div class="fs-12 text-muted text-break mt-1"><?= e(mb_substr((string) strtok((string) $t['definition'], "\n"), 0, 160)) ?></div>
                    <div class="fs-11 text-muted mt-1"><i class="feather-columns me-1"></i><?= (int) $t['column_count'] ?> column<?= (int) $t['column_count'] === 1 ? '' : 's' ?><?= $t['synonyms'] !== [] ? ' · also ' . e(implode(', ', array_slice($t['synonyms'], 0, 3))) : '' ?></div>
                </div></div></div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
