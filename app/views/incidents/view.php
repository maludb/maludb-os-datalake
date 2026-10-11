<?php
/**
 * One incident (screen `incident-view`) — phone-first: the status line and the actions first, then what opened it, what it reaches, the
 * Steward's triage and the notes. Data: i, may, notice, back
 */
$tz = member_timezone();
$b = incident_badge((string) $i['status']);
$s = severity_badge((string) $i['severity']);
$r = $i['opened_by'];
$keys = $r === null ? [] : (json_decode((string) $r['failing_keys'], true) ?: []);
$here = '/incidents/' . (int) $i['incident_id'];
?>
<?= view('shared/page-header.php', ['id' => 'incident-view', 'title' => $i['title'], 'crumbs' => [['Home', '/'], ['Quality', '/quality'], ['Incidents', '/incidents'], ['Incident ' . (int) $i['incident_id'], null]],
    'back' => $back]) ?>
<div class="main-content" id="incident-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="incident-status"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <span class="badge <?= e($s[0]) ?>" id="incident-severity"><?= e($s[1]) ?></span>
            <span class="badge <?= e($b[0]) ?>" id="incident-status-badge"><?= e($b[1]) ?></span>
            <span class="fs-12 text-muted">opened <?= e(format_ts($i['opened_at'], $tz)) ?><?= $i['acknowledged_at'] !== null ? ' · acknowledged ' . e(format_ts($i['acknowledged_at'], $tz, 'M j, g:i A')) : '' ?>
                <?= $i['resolved_at'] !== null ? ' · ' . e($i['status']) . ' ' . e(format_ts($i['resolved_at'], $tz, 'M j, g:i A')) : '' ?></span>
        </div>
        <div class="fs-13">On <?= hx_link('/datasets/' . $i['key'] . '?tab=quality', '<span class="font-monospace">' . e($i['key']) . '</span>', '', 'id="incident-dataset"') ?>
            · assigned to <span id="incident-assignee"><?= e($i['assignee_name'] ?? 'nobody yet') ?></span></div>
        <?php if ($i['resolution'] !== null): ?><div class="fs-12 mt-2" id="incident-resolution"><span class="fw-semibold">Resolution:</span> <?= e($i['resolution']) ?>
            <?= $i['root_cause'] !== null ? '<br><span class="fw-semibold">Root cause:</span> ' . e($i['root_cause']) : '' ?></div><?php endif; ?>
    </div></div>
    <?= view('incidents/partials/actions.php', ['i' => $i, 'may' => $may, 'here' => $here]) ?>
    <div class="card mb-3" id="incident-opened-by"><div class="card-header"><h5 class="card-title mb-0">What opened it</h5></div><div class="card-body fs-13">
        <?php if ($i['check'] !== null): ?>
            <div class="mb-1">The check <?= hx_link('/datasets/' . $i['key'] . '/checks#check-row-' . (int) $i['check']['check_id'], e(check_words($i['check'])), 'fw-semibold', 'id="incident-check"') ?>
                <span class="fs-11 text-muted">(<?= e($i['check']['dimension']) ?><?= $i['check']['blocking'] ? ', blocking' : '' ?>)</span></div>
        <?php elseif ($i['kind'] === 'load'): ?>
            <div class="mb-1">A load was refused<?= $i['load_id'] !== null ? ' — ' . hx_link('/loads/' . (int) $i['load_id'], 'load ' . (int) $i['load_id']) : '' ?>.</div>
        <?php endif; ?>
        <?php if ($r !== null): ?>
            <dl class="row mb-0 fs-12" id="incident-result">
                <dt class="col-5 col-md-3">Value</dt><dd class="col-7 col-md-9"><?= e(check_num($r['value'])) ?></dd>
                <dt class="col-5 col-md-3">Threshold</dt><dd class="col-7 col-md-9"><?= e((string) ($r['threshold'] ?? '—')) ?></dd>
                <dt class="col-5 col-md-3">Rows failed</dt><dd class="col-7 col-md-9"><?= e(check_num($r['rows_failed'])) ?></dd>
                <?php if ($r['error'] !== null): ?><dt class="col-5 col-md-3">Could not run</dt><dd class="col-7 col-md-9"><?= e($r['error']) ?></dd><?php endif; ?>
                <dt class="col-5 col-md-3">Failing keys</dt><dd class="col-7 col-md-9 font-monospace" id="incident-failing-keys"><?= $keys === [] ? '—' : e(implode(', ', array_map(static fn ($k): string => is_array($k) ? implode('/', $k) : (string) $k, $keys))) ?></dd>
                <dt class="col-5 col-md-3">Ran</dt><dd class="col-7 col-md-9"><?= e(format_ts($r['ran_at'], $tz)) ?></dd>
            </dl>
        <?php endif; ?>
        <?php if ($i['resolved_by'] !== null): ?><div class="fs-12 text-success mt-2" id="incident-resolved-by">A passing run resolved it (<?= e(format_ts($i['resolved_by']['ran_at'], $tz, 'M j, g:i A')) ?>).</div><?php endif; ?>
    </div></div>
    <div class="card mb-3" id="incident-downstream"><div class="card-header"><h5 class="card-title mb-0">What it reaches</h5></div><div class="card-body fs-13">
        <?php if ($i['downstream_known'] === [] && (int) $i['downstream_hidden'] === 0): ?><span class="text-muted">Nothing downstream.</span>
        <?php else: ?>
            <?php foreach ($i['downstream_known'] as $k): ?><?= hx_link('/datasets/' . $k, '<span class="badge bg-light text-dark font-monospace me-1">' . e($k) . '</span>') ?><?php endforeach; ?>
            <?php if ((int) $i['downstream_hidden'] > 0): ?><span class="fs-12 text-muted">and <?= (int) $i['downstream_hidden'] ?> more you may not see</span><?php endif; ?>
            <div class="fs-11 text-muted mt-1">Their pages carry "upstream incident" while this is open.</div>
        <?php endif; ?>
    </div></div>
    <div class="card mb-3" id="incident-notes"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div><div class="card-body">
        <?php $triage = array_values(array_filter($i['notes'], static fn (array $n): bool => $n['kind'] === 'triage')); ?>
        <?php if ($triage !== []): ?><?= view('incidents/partials/triage.php', ['notes' => $triage]) ?><?php endif; ?>
        <?php $rest = array_values(array_filter($i['notes'], static fn (array $n): bool => $n['kind'] !== 'triage')); ?>
        <?php if ($rest === [] && $triage === []): ?><div class="fs-12 text-muted mb-2" id="incident-notes-empty">No notes yet.</div><?php endif; ?>
        <?php foreach ($rest as $n): ?><?= view('incidents/partials/note.php', ['n' => $n]) ?><?php endforeach; ?>
        <form method="post" action="/incidents/note.php" hx-post="/incidents/note.php" hx-target="#flash" class="mt-2" id="incident-note-form">
            <?= csrf_field() ?><input type="hidden" name="incident" value="<?= (int) $i['incident_id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <label class="form-label fs-12" for="incident-note-body">Add a note (names and counts — never a value)</label>
            <textarea class="form-control mb-2" id="incident-note-body" name="body" rows="2" maxlength="8000" required></textarea>
            <button type="submit" class="btn btn-light btn-touch" id="incident-note-btn">Add note</button>
        </form>
    </div></div>
</div>
