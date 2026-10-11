<?php
/**
 * The Quality tab (slice 5 — contracts-quality.md): dl_dataset_health — the health word and dot, freshness, certification and what it lacks
 * (certification-missing; Certify when eligible and checks.manage — certify-btn; Remove certification), the contract (its version, a draft
 * waiting), the latest promotion polled while it runs with Promote now / Rebuild (a curated dataset, promote.run and write), the raw input's
 * pending schema changes (Accept / Reject — contracts.manage, people), the checks with their last results and strips (Run checks), open
 * incidents (its own and upstream), the quarantine (write). Rendered with the page. Data: d (the dataset page's own)
 */
require_once dirname(__DIR__, 4) . '/app/features/quality/queries.php';
require_once dirname(__DIR__, 4) . '/app/features/quality/present.php';
require_once dirname(__DIR__, 4) . '/app/features/contracts/present.php';
$pdo = db();
$id = (int) $d['dataset_id'];
$key = (string) $d['key'];
$me = (int) current_member_id();
$h = dataset_health($pdo, $id) ?? ['health' => 'green', 'certification_missing' => [], 'checks' => [], 'open_incidents' => [], 'upstream_incidents' => []];
[$hw, $hword] = health_words((string) $h['health']);
$missing = array_values((array) ($h['certification_missing'] ?? []));
$checks = find_checks($pdo, ['dataset' => $id]);
$strips = check_strips($pdo, array_map(static fn (array $c): int => (int) $c['check_id'], $checks));
$curated = $d['zone'] === 'curated';
$level = dl_level_rank((string) ($d['my_level'] ?? dataset_level($pdo, $id)));
$k = $curated ? find_contract($pdo, $id, null) : null;
$draft = $curated ? contract_draft($pdo, $id) : null;
$drift = $curated ? contract_pending_drift($pdo, $id) : [];
$promo = $curated ? last_promotion($pdo, $id) : null;
$owner = (int) ($d['owner_member_id'] ?? 0) === $me;
$may = [
    'certify' => has_right('checks.manage') && $d['zone'] !== 'sandbox' && ($d['certification'] ?? 'none') === 'none',
    'promote_badge' => $owner && !has_right('checks.manage') && $d['zone'] !== 'sandbox' && ($d['certification'] ?? 'none') === 'none',
    'uncertify' => ($d['certification'] ?? 'none') !== 'none' && (has_right('checks.manage') || $owner),
    'run' => may_run_checks($d) && $checks !== [],
    'promote' => $curated && has_right('promote.run') && $level >= DL_LEVELS['write'] && $k !== null && $k['status'] === 'active',
    'drift' => has_right('contracts.manage') && (current_member()['member_kind'] ?? '') === 'human',
    'quarantine' => $curated && $level >= DL_LEVELS['write'] && $k !== null && $k['status'] === 'active',
    'checks' => may_manage_checks(),
];
$tz = member_timezone();
$here = '/datasets/' . $key . '?tab=quality';
?>
<div id="dataset-quality">
    <div class="card mb-3" id="quality-health"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <span class="health-dot bg-<?= e($hw) ?>" aria-hidden="true"></span><span class="fw-semibold" id="quality-health-word"><?= e($hword) ?></span>
            <span class="fs-12 text-muted" id="quality-freshness">· <?= e((string) ($h['freshness'] ?? 'no expectation')) ?><?= ($h['data_updated_at'] ?? null) !== null ? ' — data updated ' . e(format_ts($h['data_updated_at'], $tz, 'M j, g:i A')) : '' ?></span>
        </div>
        <div id="quality-certification" class="fs-13">
            <?php if (($d['certification'] ?? 'none') !== 'none'): $cb = certification_badge((string) $d['certification']); ?>
                <span class="badge <?= e($cb[0] ?? 'bg-success') ?>"><?= e($cb[1] ?? ucfirst((string) $d['certification'])) ?></span>
            <?php elseif ($missing === []): ?>
                <span class="text-success">Eligible for certification.</span>
            <?php else: ?>
                <span id="certification-missing">Not eligible — it lacks: <?= e(implode(', ', $missing)) ?>.</span>
            <?php endif; ?>
            <?php if (($h['certification_lost_reason'] ?? null) !== null && ($d['certification'] ?? 'none') === 'none'): ?><div class="fs-12 text-danger mt-1" id="quality-cert-lost">Certification lost: <?= e((string) $h['certification_lost_reason']) ?></div><?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <?php if ($may['certify'] && $missing === []): ?>
                <form method="post" action="/quality/certify.php" hx-post="/quality/certify.php" hx-target="#flash" hx-confirm="Certify <?= e($key) ?>? It keeps the badge only while every condition holds." id="certify-form">
                    <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="level" value="certified"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                    <button type="submit" class="btn btn-success btn-sm btn-touch" id="certify-btn"><i class="feather-award me-1"></i>Certify</button></form>
            <?php elseif ($may['promote_badge'] && $missing === []): ?>
                <form method="post" action="/quality/certify.php" hx-post="/quality/certify.php" hx-target="#flash" id="certify-form">
                    <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="level" value="promoted"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                    <button type="submit" class="btn btn-light btn-sm btn-touch" id="certify-btn">Promote it (the owner's badge)</button></form>
            <?php endif; ?>
            <?php if ($may['uncertify']): ?>
                <details id="uncertify-box"><summary class="btn btn-light btn-sm btn-touch">Remove certification…</summary>
                <form method="post" action="/quality/uncertify.php" hx-post="/quality/uncertify.php" hx-target="#flash" class="mt-2 d-flex gap-2" id="uncertify-form">
                    <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                    <input type="text" class="form-control form-control-sm" name="reason" maxlength="500" required placeholder="Why" aria-label="Why" id="uncertify-reason">
                    <button type="submit" class="btn btn-outline-danger btn-sm btn-touch" id="uncertify-btn">Remove</button></form></details>
            <?php endif; ?>
        </div>
    </div></div>
    <?php if ($curated): ?>
    <div class="card mb-3" id="quality-contract"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="fw-semibold me-1">Contract</span>
            <?php if ($k !== null && $k['status'] === 'active'): ?>
                <?= hx_link('/datasets/' . $key . '/contract', e($k['version']), 'badge bg-success', 'id="quality-contract-version"') ?>
                <span class="fs-12 text-muted">reads <span class="font-monospace"><?= e(contract_raw_key((array) $k['schema']) ?? '—') ?></span> · <?= e(sla_words((array) $k['sla'])) ?></span>
            <?php else: ?><span class="fs-12 text-muted" id="quality-contract-none">None published — promotion types by one.</span><?php endif; ?>
            <?php if ($draft !== null): ?><?= hx_link('/datasets/' . $key . '/contract?version=' . rawurlencode((string) $draft['version']), 'Draft ' . e($draft['version']) . ' waiting', 'badge bg-secondary', 'id="quality-contract-draft"') ?><?php endif; ?>
            <span class="ms-auto d-flex gap-2"><?= hx_link('/datasets/' . $key . '/contract', 'The contract', 'btn btn-light btn-sm btn-touch', 'id="quality-contract-link"') ?>
                <?php if ($may['quarantine']): ?><?= hx_link('/datasets/' . $key . '/quarantine', 'Quarantine', 'btn btn-light btn-sm btn-touch', 'id="quality-quarantine-link"') ?><?php endif; ?></span>
        </div>
        <?php if ($k !== null && $k['status'] === 'active'): ?>
            <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">
                <?= view('quality/partials/promotion.php', ['p' => $promo, 'dataset' => $id]) ?>
                <?php if ($may['promote']): ?>
                    <span class="ms-auto d-flex gap-2">
                    <form method="post" action="/quality/promote.php" hx-post="/quality/promote.php" hx-target="#flash" id="promote-form">
                        <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                        <button type="submit" class="btn btn-primary btn-sm btn-touch" id="promote-btn"><i class="feather-arrow-up-circle me-1"></i>Promote now</button></form>
                    <form method="post" action="/quality/rebuild.php" hx-post="/quality/rebuild.php" hx-target="#flash" hx-confirm="Rebuild <?= e($key) ?> from all of raw?" id="rebuild-form">
                        <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                        <button type="submit" class="btn btn-light btn-sm btn-touch" id="rebuild-btn">Rebuild</button></form>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($drift !== []): ?>
            <div class="mt-3" id="quality-drift"><div class="fs-12 fw-semibold mb-1">The raw input changed shape — waiting for a decision:</div>
            <?php foreach ($drift as $v): $svid = (int) $v['schema_version_id']; ?>
                <div class="d-flex flex-wrap gap-2 align-items-center fs-12 mb-1" id="drift-<?= $svid ?>">
                    <span class="font-monospace"><?= e($v['key']) ?> v<?= (int) $v['version'] ?></span>
                    <span><?= e(implode('; ', array_map(static fn (array $c): string => ($c['column'] ?? '?') . ' ' . ($c['kind'] ?? ''), $v['changes']))) ?></span>
                    <?php if ($may['drift']): ?>
                        <form method="post" action="/quality/drift.php" hx-post="/quality/drift.php" hx-target="#flash" class="d-inline" id="drift-<?= $svid ?>-accept-form">
                            <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="schema_version" value="<?= $svid ?>"><input type="hidden" name="accept" value="1">
                            <button type="submit" class="btn btn-light btn-sm btn-touch" id="drift-<?= $svid ?>-accept">Accept (draft the contract)</button></form>
                        <form method="post" action="/quality/drift.php" hx-post="/quality/drift.php" hx-target="#flash" class="d-inline" id="drift-<?= $svid ?>-reject-form">
                            <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="schema_version" value="<?= $svid ?>"><input type="hidden" name="accept" value="0">
                            <input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="drift-<?= $svid ?>-reject">Reject</button></form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?></div>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>
    <div class="card mb-3" id="quality-checks"><div class="card-header d-flex align-items-center gap-2"><h5 class="card-title mb-0 me-auto">Checks</h5>
        <?php if ($may['run']): ?>
            <form method="post" action="/quality/checks/run.php" hx-post="/quality/checks/run.php" hx-target="#flash" id="quality-run-form">
                <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <button type="submit" class="btn btn-light btn-sm btn-touch" id="quality-run-btn">Run checks</button></form>
        <?php endif; ?>
        <?= hx_link('/datasets/' . $key . '/checks', $may['checks'] ? 'Checks — add or change' : 'All checks', 'btn btn-light btn-sm btn-touch', 'id="quality-checks-link"') ?></div>
    <?php if ($checks === []): ?>
        <div class="card-body fs-12 text-muted" id="quality-checks-empty">No checks yet.<?= $may['checks'] ? ' A blocking one is what certification asks for first.' : '' ?></div>
    <?php else: ?>
        <div class="list-group list-group-flush">
        <?php foreach ($checks as $c): $cid = (int) $c['check_id']; $strip = $strips[$cid] ?? []; $last = $strip === [] ? null : end($strip); $b = result_badge($last, (string) $c['severity']); ?>
            <div class="list-group-item d-flex flex-wrap gap-2 align-items-center fs-12" id="quality-check-<?= $cid ?>">
                <span class="me-auto"><?= e(check_words($c)) ?><?= $c['blocking'] ? ' <span class="badge bg-dark">Blocking</span>' : '' ?><?= $c['enabled'] ? '' : ' <span class="badge bg-light text-muted">Off</span>' ?></span>
                <span class="badge <?= e($b[0]) ?>"><?= e($b[1]) ?></span><?= view('checks/partials/strip.php', ['strip' => $strip, 'id' => 'quality-strip-' . $cid]) ?>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?></div>
    <div class="card mb-3" id="quality-open-incidents"><div class="card-header"><h5 class="card-title mb-0">Open incidents</h5></div>
    <?php $open = (array) ($h['open_incidents'] ?? []); $up = (array) ($h['upstream_incidents'] ?? []); ?>
    <?php if ($open === [] && $up === []): ?><div class="card-body fs-12 text-muted" id="quality-incidents-none">None.</div>
    <?php else: ?><div class="list-group list-group-flush">
        <?php foreach ($open as $i): $ib = incident_badge((string) $i['status']); ?>
            <?= hx_link(with_back('/incidents/' . (int) $i['incident_id'], $here), '<span class="fw-semibold">' . e($i['title']) . '</span> <span class="badge ' . e(severity_badge((string) $i['severity'])[0]) . '">' . e($i['severity']) . '</span> <span class="badge ' . e($ib[0]) . '">' . e($ib[1]) . '</span>',
                'list-group-item list-group-item-action fs-12', 'id="quality-incident-' . (int) $i['incident_id'] . '"') ?>
        <?php endforeach; ?>
        <?php foreach ($up as $u): ?>
            <?= hx_link('/datasets/' . $u . '?tab=quality', '<span class="badge bg-warning text-dark me-1">Upstream incident</span><span class="font-monospace">' . e((string) $u) . '</span>', 'list-group-item list-group-item-action fs-12', 'id="quality-upstream-' . e(str_replace('.', '-', (string) $u)) . '"') ?>
        <?php endforeach; ?>
    </div><?php endif; ?></div>
</div>
