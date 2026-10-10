<?php
/**
 * A column's page (screen `column-view`; catalog.md): its meaning (business name, description, the glossary term, critical), its types,
 * its classification and personal kind — read-only, each with the action that changes it (slice 4's forms) —, the reference, the latest
 * profile (null %, distinct, the length range, min and max masked when personal, the top values masked when personal and withheld when
 * confidential or restricted) and the profile over time as a small table. Data: d, c, profile, history, may, here
 */
$id = (int) $d['dataset_id'];
$tz = member_timezone();
$pct = static fn ($v): string => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.') . '%';
$classifyBuilt = nav_built('/access/index.php');
?>
<?= view('shared/page-header.php', ['id' => 'column', 'title' => $c['business_name'] ?? $c['name'],
    'crumbs' => [['Browse', '/browse'], [$d['key'], '/datasets/' . $id . '?tab=columns'], [$c['name'], null]],
    'back' => back_link() ?? ['/datasets/' . $id . '?tab=columns', $d['title']],
    'action' => $may['describe'] ? hx_link('/datasets/' . $id . '/columns/' . rawurlencode((string) $c['name']) . '/edit', '<i class="feather-edit-2 me-1"></i>Describe', 'btn btn-primary btn-touch', 'id="column-edit-btn"') : '']) ?>
<div class="main-content" id="column-content" data-column-id="<?= (int) $c['column_id'] ?>">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($c['removed_snapshot_id'] !== null): ?><div class="alert alert-secondary" id="column-removed">This column was removed from the dataset; its history stays.</div><?php endif; ?>
    <div class="card mb-3" id="column-meaning"><div class="card-body">
        <div class="dataset-key mb-1" id="column-name"><?= e($d['key'] . '.' . $c['name']) ?></div>
        <h6 class="fs-12 text-uppercase text-muted mt-2 mb-1">What it means</h6>
        <div class="text-break" id="column-description"><?= ($c['description'] ?? '') === '' ? '<span class="text-muted">Not described yet.</span>' : nl2br(e((string) $c['description'])) ?></div>
        <dl class="row mb-0 mt-3 fs-13">
            <dt class="col-5 col-md-3">Business name</dt><dd class="col-7 col-md-9" id="column-business-name"><?= e((string) ($c['business_name'] ?? '—')) ?></dd>
            <dt class="col-5 col-md-3">Glossary term</dt><dd class="col-7 col-md-9" id="column-term"><?= $c['glossary_term_id'] === null ? '—' : hx_link(with_back('/glossary/' . (int) $c['glossary_term_id'], $here), e((string) $c['term_name'])) . ($c['term_status'] === 'draft' ? ' <span class="badge text-bg-secondary">draft</span>' : '') ?></dd>
            <dt class="col-5 col-md-3">Critical</dt><dd class="col-7 col-md-9" id="column-critical"><?= $c['critical'] ? 'Yes — a critical data element' : 'No' ?></dd>
            <dt class="col-5 col-md-3">References</dt><dd class="col-7 col-md-9" id="column-references"><?= $c['references_column'] === null ? '—' : ($c['references_key'] !== null ? hx_link('/datasets/' . $c['references_key'] . '/columns/' . rawurlencode((string) $c['references_column']), e($c['references_key'] . '.' . $c['references_column']), 'dataset-key') : 'a dataset you may not see') ?></dd>
        </dl>
    </div></div>
    <div class="card mb-3" id="column-types"><div class="card-body">
        <h6 class="fs-12 text-uppercase text-muted mb-2">Type and classification</h6>
        <dl class="row mb-0 fs-13">
            <dt class="col-5 col-md-3">Type</dt><dd class="col-7 col-md-9" id="column-type"><?= e((string) ($c['logical_type'] ?? '—')) ?> <span class="text-muted"><?= e((string) ($c['physical_type'] ?? '')) ?></span><?= $c['nullable'] ? '' : ' · not null' ?><?= $c['is_key'] ? ' · key' : '' ?>
                <div class="fs-11 text-muted">The engine's — recorded by the writer from the data.</div></dd>
            <dt class="col-5 col-md-3">Classification</dt><dd class="col-7 col-md-9" id="column-classification"><span class="badge <?= e(classification_class((string) $c['classification'])) ?>"><?= e($c['classification']) ?></span>
                <span class="fs-11 text-muted"><?= $classifyBuilt ? hx_link('/access/personal-data?dataset=' . $id, 'change') : 'changed by an admin (slice 4 builds the form)' ?></span></dd>
            <dt class="col-5 col-md-3">Personal data</dt><dd class="col-7 col-md-9" id="column-personal">
                <?php if ($c['personal_kind'] !== null): ?><span class="badge text-bg-danger"><?= e($c['personal_kind']) ?></span> <span class="fs-11 text-muted">confirmed — masked below unmasked read</span>
                <?php elseif ($c['proposed_personal_kind'] !== null): ?><span class="badge border border-danger text-danger"><?= e($c['proposed_personal_kind']) ?> · proposed</span> <span class="fs-11 text-muted"><?= $c['proposed_confidence'] !== null ? e(round(100 * (float) $c['proposed_confidence']) . '% sure') . ' — ' : '' ?>a person confirms</span>
                <?php else: ?>None<?php endif; ?></dd>
        </dl>
    </div></div>
    <div class="card mb-3" id="column-profile"><div class="card-body">
        <h6 class="fs-12 text-uppercase text-muted mb-2">The latest profile<?= $c['profiled_at'] !== null ? ' <span class="text-lowercase fw-normal">· ' . e(ago_words($c['profiled_at'])) . '</span>' : '' ?></h6>
        <?php if ($profile === null): ?><div class="fs-12 text-muted" id="column-profile-none">No profile yet — the writer profiles every load.</div>
        <?php else: ?>
            <dl class="row mb-0 fs-13">
                <dt class="col-5 col-md-3">Empty</dt><dd class="col-7 col-md-9" id="column-profile-null"><?= $pct($profile['null_pct']) ?></dd>
                <dt class="col-5 col-md-3">Distinct</dt><dd class="col-7 col-md-9" id="column-profile-distinct"><?= $profile['distinct_approx'] === null ? '—' : e(count_words($profile['distinct_approx'])) ?> (<?= $pct($profile['distinct_pct']) ?>)</dd>
                <?php if ($profile['length_min'] !== null): ?><dt class="col-5 col-md-3">Length</dt><dd class="col-7 col-md-9"><?= (int) $profile['length_min'] ?>–<?= (int) $profile['length_max'] ?> characters</dd><?php endif; ?>
                <dt class="col-5 col-md-3">Range</dt><dd class="col-7 col-md-9 text-break" id="column-profile-range"><?= $profile['withheld'] === 'restricted' ? 'withheld — restricted' : e(($profile['min'] ?? '—') . ' … ' . ($profile['max'] ?? '—')) ?><?= $profile['masked'] ? ' <span class="badge text-bg-light border">masked</span>' : '' ?></dd>
            </dl>
            <h6 class="fs-12 text-uppercase text-muted mt-3 mb-1">Most common values</h6>
            <?php if ($profile['top'] === null): ?><div class="fs-12 text-muted" id="column-profile-top-withheld">Withheld — the column is <?= e((string) $profile['withheld']) ?>.</div>
            <?php elseif ($profile['top'] === []): ?><div class="fs-12 text-muted">None.</div>
            <?php else: ?>
                <ul class="list-unstyled mb-0 fs-13" id="column-profile-top"><?php foreach ($profile['top'] as $t): ?><li class="d-flex justify-content-between gap-2"><span class="text-break"><?= e((string) $t['value']) ?></span><span class="text-muted"><?= e(count_words($t['count'])) ?></span></li><?php endforeach; ?></ul>
            <?php endif; ?>
        <?php endif; ?>
    </div></div>
    <?php if ($history !== []): ?>
    <div class="card mb-3" id="column-profile-history"><div class="table-responsive">
        <table class="table table-sm mb-0"><thead><tr><th>Profiled</th><th class="text-end">Empty</th><th class="text-end">Distinct</th><th class="d-none d-md-table-cell">Range</th></tr></thead><tbody>
            <?php foreach ($history as $h): $p = $h['profile']; ?>
                <tr id="column-profile-<?= (int) $h['profile_id'] ?>"><td class="fs-12 text-nowrap"><?= e(format_ts($h['profiled_at'], $tz, 'M j, g:i A')) ?></td><td class="text-end fs-12"><?= $pct($p['null_pct'] ?? null) ?></td>
                    <td class="text-end fs-12"><?= $pct($p['distinct_pct'] ?? null) ?></td><td class="d-none d-md-table-cell fs-12 text-break"><?= ($p['withheld'] ?? null) === 'restricted' ? 'withheld' : e(($p['min'] ?? '—') . ' … ' . ($p['max'] ?? '—')) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table>
    </div></div>
    <?php endif; ?>
</div>
