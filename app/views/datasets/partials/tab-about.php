<?php
/**
 * About (slice 1): the description with where it came from ("drafted by the Steward, accepted by …"), purpose / usage / limitations, the
 * tags with the inline editor (the owner, the steward or an engineer), the glossary terms of its columns, the expectations (tier, freshness,
 * schema evolution), the contract's version (slice 5 makes it a link), the deprecation note and the successor. Data: d, may, here, tag_suggestions
 */
$id = (int) $d['dataset_id'];
$source = match ($d['description_source']) {
    'accepted'       => 'Drafted by the Steward' . ($d['accepted_by'] !== null ? ', accepted by ' . $d['accepted_by'] : ', accepted'),
    'agent_proposed' => 'Proposed by the Steward — not yet accepted',
    default          => null,
};
$warn = interval_words($d['freshness_warn_seconds'] ?? null);
$err = interval_words($d['freshness_error_seconds'] ?? null);
?>
<?php if ($d['status'] === 'deprecated'): ?>
    <div class="alert alert-warning" id="dataset-deprecation" role="status">
        <div class="fw-semibold">Deprecated</div>
        <div class="text-break"><?= nl2br(e((string) $d['deprecation_note'])) ?></div>
        <?php if ($d['successor'] !== null): ?><div class="mt-1">Use <?= hx_link('/datasets/' . $d['successor']['key'], e($d['successor']['title']) . ' <span class="dataset-key">' . e($d['successor']['key']) . '</span>', 'alert-link', 'id="dataset-successor-link"') ?> instead.</div><?php endif; ?>
    </div>
<?php endif; ?>
<div class="card mb-3" id="dataset-about"><div class="card-body">
    <h6 class="fs-12 text-uppercase text-muted mb-2">What it is</h6>
    <?php if (($d['description'] ?? '') === ''): ?>
        <div class="text-muted" id="dataset-about-description">No description yet.<?php if ($may['describe']): ?> <?= hx_link('/datasets/' . $id . '/edit', 'Describe it', '', 'id="dataset-about-describe-link"') ?>.<?php endif; ?></div>
    <?php else: ?>
        <div class="text-break" id="dataset-about-description"><?= nl2br(e((string) $d['description'])) ?></div>
        <?php if ($source !== null): ?><div class="fs-11 text-muted mt-1" id="dataset-about-description-source"><i class="feather-feather me-1"></i><?= e($source) ?></div><?php endif; ?>
    <?php endif; ?>
    <?php foreach (['purpose' => 'Purpose', 'usage' => 'How to use it', 'limitations' => 'Limitations'] as $k => $label): if (($d[$k] ?? '') === '') { continue; } ?>
        <h6 class="fs-12 text-uppercase text-muted mt-3 mb-1"><?= e($label) ?></h6>
        <div class="text-break" id="dataset-about-<?= e($k) ?>"><?= nl2br(e((string) $d[$k])) ?></div>
    <?php endforeach; ?>
</div></div>
<div class="card mb-3"><div class="card-body">
    <h6 class="fs-12 text-uppercase text-muted mb-2">Tags</h6>
    <?= view('datasets/partials/tag-editor.php', ['d' => $d, 'may' => $may, 'here' => $here, 'suggestions' => $tag_suggestions]) ?>
    <?php if ($d['terms'] !== []): ?>
        <h6 class="fs-12 text-uppercase text-muted mt-3 mb-2">Glossary terms of its columns</h6>
        <div class="facet-chips" id="dataset-about-terms">
            <?php foreach ($d['terms'] as $t): ?><?= hx_link(with_back('/glossary/' . (int) $t['term_id'], $here), e($t['name']), 'btn btn-sm btn-light btn-touch', 'id="dataset-term-' . (int) $t['term_id'] . '"') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
</div></div>
<div class="card mb-3" id="dataset-expectations"><div class="card-body">
    <h6 class="fs-12 text-uppercase text-muted mb-2">Expectations</h6>
    <dl class="row mb-0 fs-13">
        <dt class="col-5 col-md-3">Tier</dt><dd class="col-7 col-md-9" id="dataset-about-tier"><?= e(DATASET_TIERS[$d['tier']] ?? $d['tier']) ?></dd>
        <dt class="col-5 col-md-3">Fresh within</dt><dd class="col-7 col-md-9" id="dataset-about-freshness"><?= $warn === null ? 'No expectation' : e('warn after ' . $warn . ($err !== null ? ', fail after ' . $err : '')) ?></dd>
        <dt class="col-5 col-md-3">New columns</dt><dd class="col-7 col-md-9" id="dataset-about-evolution"><?= e(EVOLUTION_MODES[$d['evolution_mode']] ?? $d['evolution_mode']) ?></dd>
        <dt class="col-5 col-md-3">Contract</dt><dd class="col-7 col-md-9" id="dataset-about-contract"><?= $d['contract_version'] === null ? 'None published yet (slice 5 builds contracts)' : 'Version ' . e($d['contract_version']) . ($d['dedupe_key'] !== null && $d['dedupe_key'] !== '{}' ? ' · key ' . e(implode(', ', pg_text_array((string) $d['dedupe_key']))) : '') ?></dd>
        <dt class="col-5 col-md-3">Classification</dt><dd class="col-7 col-md-9"><span class="badge <?= e(classification_class((string) $d['classification'])) ?>"><?= e($d['classification']) ?></span><?= $d['has_personal_data'] ? ' <span class="badge text-bg-danger">personal data</span>' : '' ?> <span class="fs-11 text-muted">— from its columns</span></dd>
        <dt class="col-5 col-md-3">Department</dt><dd class="col-7 col-md-9"><?= $d['department_id'] === null ? '—' : hx_link('/browse?department=' . (int) $d['department_id'], e((string) $d['department_name'])) ?></dd>
        <dt class="col-5 col-md-3">Registered</dt><dd class="col-7 col-md-9"><?= e(format_ts($d['created_at'], member_timezone(), 'M j, Y')) ?></dd>
    </dl>
</div></div>
