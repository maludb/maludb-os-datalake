<?php
/**
 * The contract draft (screen `contract-edit`): the raw input (the record picker — choosing reloads the prefill), the columns as a table at 1280
 * and stacked at 375 (one set of fields), the key by the rows ticked, the ordering, the SLA, the version, the breaking changes live; Import ODCS.
 * Data: d, p (prefill), raw_id, raw_cols, breaking, errors, notice
 */
$key = (string) $d['key'];
$rows = array_values((array) $p['schema']);
$rows[] = [];                                                       // a blank row: a column the raw input does not yet name
$err = static fn (string $n): string => isset($errors[$n]) ? '<div class="invalid-feedback d-block">' . e($errors[$n]) . '</div>' : '';
$keyCols = (array) ($p['key'] ?? []);
$sla = (array) ($p['sla'] ?? []);
$names = array_values(array_filter(array_map(static fn (array $r): string => (string) ($r['name'] ?? ''), $rows)));
$src = static function (array $r): string {
    $parts = explode('.', (string) ($r['source'] ?? ''));
    return count($parts) === 3 ? $parts[2] : (string) ($r['source'] ?? '');
};
?>
<?= view('shared/page-header.php', ['id' => 'contract-form', 'title' => 'Contract draft — ' . $key, 'crumbs' => [['Home', '/'], ['Browse', '/browse'], [$key, '/datasets/' . $key . '?tab=quality'],
    ['Contract', '/datasets/' . $key . '/contract'], ['Draft', null]]]) ?>
<div class="main-content" id="contract-form-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/datasets/<?= e($key) ?>/contract/edit" class="card mb-3" id="contract-raw-form"><div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-8"><label class="form-label" id="contract-raw-label" for="contract-raw-pick-open">The raw dataset it promotes from</label>
            <?= picker_field(['id' => 'contract-raw-pick', 'name' => 'raw', 'source' => 'raw_dataset', 'value' => $raw_id, 'labelledby' => 'contract-raw-label', 'title' => 'The raw input',
                'extra' => ' hx-get="/datasets/' . e($key) . '/contract/edit" hx-trigger="change" hx-include="#contract-raw-form" hx-target="#page-content" hx-push-url="true"']) ?></div>
        <div class="col-12 col-md-4"><button type="submit" class="btn btn-light btn-touch w-100" id="contract-raw-load">Fill from its columns</button></div>
    </div></form>
    <form method="post" action="/quality/contracts/save.php" hx-post="/quality/contracts/save.php" hx-target="#flash" id="contract-form-form" novalidate>
        <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= (int) $d['id'] ?>"><input type="hidden" name="raw" value="<?= e((string) ($p['raw'] ?? '')) ?>">
        <?php if (!empty($p['contract'])): ?><input type="hidden" name="contract" value="<?= (int) $p['contract'] ?>"><?php endif; ?>
        <input type="hidden" name="return_to" value="/datasets/<?= e($key) ?>/contract">
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Columns<?= $p['raw'] ? ' — reading <span class="font-monospace">' . e((string) $p['raw']) . '</span>' : '' ?></h5></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 align-middle fs-12 contract-table" id="contract-columns">
            <thead class="d-none d-lg-table-header-group"><tr><th>Name</th><th>Logical</th><th>Physical</th><th>Req.</th><th>Unique</th><th>Key</th><th>Source (raw column)</th><th>Description</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $n => $r): $pre = 'columns[' . $n . ']'; ?>
                <tr id="contract-column-<?= $n ?>" class="d-block d-lg-table-row border-bottom">
                    <td class="d-block d-lg-table-cell"><label class="form-label fs-11 d-lg-none" for="contract-column-<?= $n ?>-name">Name</label>
                        <input type="text" class="form-control form-control-sm font-monospace" id="contract-column-<?= $n ?>-name" name="<?= $pre ?>[name]" value="<?= e((string) ($r['name'] ?? '')) ?>" placeholder="<?= $r === [] ? 'add a column' : '' ?>"><?= $err("columns.$n.name") ?></td>
                    <td class="d-block d-lg-table-cell"><label class="form-label fs-11 d-lg-none" for="contract-column-<?= $n ?>-logical_type">Logical type</label>
                        <select class="form-select form-select-sm" id="contract-column-<?= $n ?>-logical_type" name="<?= $pre ?>[logical_type]"><option value="">(from the type)</option>
                        <?php foreach (CONTRACT_LOGICAL as $l): ?><option value="<?= e($l) ?>"<?= ($r['logical_type'] ?? '') === $l ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td>
                    <td class="d-block d-lg-table-cell"><label class="form-label fs-11 d-lg-none" for="contract-column-<?= $n ?>-physical_type">Physical type</label>
                        <input type="text" class="form-control form-control-sm font-monospace" id="contract-column-<?= $n ?>-physical_type" name="<?= $pre ?>[physical_type]" list="contract-physical-types"
                               value="<?= e((string) ($r['physical_type'] ?? 'VARCHAR')) ?>"><?= $err("columns.$n.physical_type") ?></td>
                    <?php foreach (['required' => 'Required', 'unique' => 'Unique', 'primary_key' => 'Key'] as $flag => $label): $on = $flag === 'primary_key' ? in_array($r['name'] ?? null, $keyCols, true) || !empty($r['primary_key']) : !empty($r[$flag]); ?>
                        <td class="d-inline-block d-lg-table-cell me-3 me-lg-0"><div class="form-check"><input class="form-check-input" type="checkbox" id="contract-column-<?= $n ?>-<?= $flag ?>" name="<?= $pre ?>[<?= $flag ?>]" value="1"<?= $on ? ' checked' : '' ?>>
                            <label class="form-check-label d-lg-none fs-11" for="contract-column-<?= $n ?>-<?= $flag ?>"><?= e($label) ?></label></div></td>
                    <?php endforeach; ?>
                    <td class="d-block d-lg-table-cell"><label class="form-label fs-11 d-lg-none" for="contract-column-<?= $n ?>-source">Source (raw column)</label>
                        <select class="form-select form-select-sm font-monospace" id="contract-column-<?= $n ?>-source" name="<?= $pre ?>[source]"><option value="">—</option>
                        <?php foreach ($raw_cols as $c): ?><option value="<?= e($c) ?>"<?= $src($r) === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
                        <?php if ($src($r) !== '' && !in_array($src($r), $raw_cols, true)): ?><option value="<?= e($src($r)) ?>" selected><?= e($src($r)) ?> (not in raw)</option><?php endif; ?></select><?= $err("columns.$n.source") ?></td>
                    <td class="d-block d-lg-table-cell"><label class="form-label fs-11 d-lg-none" for="contract-column-<?= $n ?>-description">Description</label>
                        <input type="text" class="form-control form-control-sm" id="contract-column-<?= $n ?>-description" name="<?= $pre ?>[description]" maxlength="4000" value="<?= e((string) ($r['description'] ?? '')) ?>">
                        <input type="hidden" name="<?= $pre ?>[classification]" value="<?= e((string) ($r['classification'] ?? '')) ?>"><input type="hidden" name="<?= $pre ?>[personal_kind]" value="<?= e((string) ($r['personal_kind'] ?? '')) ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <datalist id="contract-physical-types"><?php foreach (array_merge(CONTRACT_PHYSICAL, ['DECIMAL(12,2)', 'DECIMAL(18,4)']) as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
        <div class="fs-11 text-muted px-3 py-2">Clear a column's name to leave it out. The key is the columns ticked Key — promotion keeps the latest row of each.</div>
        <?= $err('schema') ?><?= $err('key') ?></div></div>
        <div class="card mb-3"><div class="card-body row g-3">
            <div class="col-12 col-md-4"><label class="form-label" for="contract-form-ordering">The latest row is the one with the highest</label>
                <select class="form-select btn-touch" id="contract-form-ordering" name="ordering_column"><option value="">_dl_loaded_at (when it arrived)</option>
                    <?php foreach ($names as $nm): ?><option value="<?= e($nm) ?>"<?= ($p['ordering_column'] ?? '') === $nm ? ' selected' : '' ?>><?= e($nm) ?></option><?php endforeach; ?></select><?= $err('ordering_column') ?></div>
            <?php foreach (CONTRACT_SLA as $s => $label): ?>
                <div class="col-6 col-md-2"><label class="form-label" for="contract-form-sla-<?= e($s) ?>"><?= e($label) ?></label>
                    <input type="text" class="form-control btn-touch font-monospace" id="contract-form-sla-<?= e($s) ?>" name="sla[<?= e($s) ?>]" value="<?= e((string) ($sla[$s] ?? '')) ?>" placeholder="<?= $s === 'retention' ? 'P2Y' : ($s === 'freshness' ? 'P1D' : 'PT1H') ?>"><?= $err('sla.' . $s) ?></div>
            <?php endforeach; ?>
            <div class="col-6 col-md-3"><label class="form-label" for="contract-form-version">Version</label>
                <input type="text" class="form-control btn-touch font-monospace" id="contract-form-version" name="version" value="<?= e((string) $p['version']) ?>"><?= $err('version') ?></div>
            <div class="col-12" hx-get="/datasets/<?= e($key) ?>/contract/edit?fragment=breaking" hx-trigger="change from:#contract-form-form delay:300ms"
                 hx-include="#contract-form-form" hx-target="#contract-breaking" hx-swap="outerHTML"><?= view('contracts/partials/breaking.php', ['breaking' => $breaking, 'suggest' => (string) $p['version']]) ?></div>
        </div></div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="submit" class="btn btn-primary btn-touch" id="contract-form-save">Save the draft</button>
            <?= hx_link('/datasets/' . $key . '/contract', 'Cancel', 'btn btn-light btn-touch', 'id="contract-form-cancel"') ?>
        </div>
    </form>
    <details class="card" id="contract-import"><summary class="card-body fw-semibold">Import an ODCS contract (YAML)</summary>
        <form method="post" action="/datasets/<?= e($key) ?>/contract/edit" class="card-body pt-0" id="contract-import-form">
            <?= csrf_field() ?><label class="form-label fs-12" for="contract-import-odcs">Paste the YAML (as Download ODCS writes it) — the form is filled from it; nothing is saved until you save.</label>
            <textarea class="form-control font-monospace fs-12 mb-2" id="contract-import-odcs" name="odcs" rows="6"></textarea>
            <button type="submit" class="btn btn-light btn-touch" id="contract-import-btn">Read it</button>
        </form></details>
</div>
