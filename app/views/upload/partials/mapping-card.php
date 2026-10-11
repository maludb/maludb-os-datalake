<?php
/**
 * One source column → its name, type, personal kind (the classifier's proposal preselected with its confidence: keep or clear), the key and
 * skip. A card at 375; a row of the table from md. Ids map-column-{n}-name|type|personal (uploads-loads.md). Data: i, c, t (a template's), samples, key
 */
$name = (string) ($t['name'] ?? $c['name']);
$type = (string) ($t['type'] ?? ($c['type'] ?? 'VARCHAR'));
$proposed = $c['proposed_personal_kind'] ?? null;
$kind = is_array($t) && array_key_exists('personal_kind', $t) ? (string) ($t['personal_kind'] ?? '') : (string) ($proposed ?? '');
$skip = !empty($t['skip']);
$n = (int) $i;
?>
<div class="row g-2 align-items-start map-col" id="map-column-<?= $n ?>">
    <div class="col-12 col-md-3">
        <div class="fw-semibold text-break" id="map-column-<?= $n ?>-source"><?= e((string) $c['source_name']) ?></div>
        <div class="fs-11 text-muted text-truncate"><?= e(implode(' · ', array_map(static fn ($v): string => mb_substr((string) ($v ?? '∅'), 0, 30), $samples))) ?></div>
        <?php if (!empty($c['note'])): ?><div class="fs-11 text-info"><?= e((string) $c['note']) ?></div><?php endif; ?>
    </div>
    <div class="col-12 col-md-3"><label class="form-label fs-11 mb-0 d-md-none" for="map-column-<?= $n ?>-name">Name</label>
        <input type="text" class="form-control form-control-sm btn-touch" id="map-column-<?= $n ?>-name" name="columns[<?= $n ?>][name]" value="<?= e($name) ?>"
               maxlength="63" pattern="[a-z][a-z0-9_]{0,62}" autocomplete="off" spellcheck="false"></div>
    <div class="col-6 col-md-2"><label class="form-label fs-11 mb-0 d-md-none" for="map-column-<?= $n ?>-type">Type</label>
        <select class="form-select form-select-sm btn-touch" id="map-column-<?= $n ?>-type" name="columns[<?= $n ?>][type]">
            <?php foreach (MAP_TYPES as $v => $w): ?><option value="<?= e($v) ?>"<?= $v === $type ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?>
        </select></div>
    <div class="col-6 col-md-3"><label class="form-label fs-11 mb-0 d-md-none" for="map-column-<?= $n ?>-personal">Personal data</label>
        <select class="form-select form-select-sm btn-touch" id="map-column-<?= $n ?>-personal" name="columns[<?= $n ?>][personal_kind]">
            <option value=""<?= $kind === '' ? ' selected' : '' ?>><?= $proposed !== null ? 'Not personal (clear the proposal)' : 'Not personal' ?></option>
            <?php foreach (PERSONAL_KINDS as $v => $w): ?><option value="<?= e($v) ?>"<?= $v === $kind ? ' selected' : '' ?>><?= e(ucfirst($w)) ?></option><?php endforeach; ?>
        </select>
        <?php if ($proposed !== null): ?>
            <div class="fs-11 text-warning" id="map-column-<?= $n ?>-proposal">Looks like <?= e(PERSONAL_KINDS[$proposed] ?? $proposed) ?> — <?= (int) round(((float) ($c['confidence'] ?? 0)) * 100) ?> %</div>
        <?php endif; ?></div>
    <div class="col-12 col-md-1 d-flex flex-md-column gap-3 gap-md-1 fs-12">
        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="map-column-<?= $n ?>-key" name="dedupe_key[]" value="#<?= $n ?>"<?= $key ? ' checked' : '' ?>>
            <label class="form-check-label" for="map-column-<?= $n ?>-key">Key</label></div>
        <div class="form-check mb-0"><input type="hidden" name="columns[<?= $n ?>][skip]" value="0"><input class="form-check-input" type="checkbox" id="map-column-<?= $n ?>-skip" name="columns[<?= $n ?>][skip]" value="1"<?= $skip ? ' checked' : '' ?>>
            <label class="form-check-label" for="map-column-<?= $n ?>-skip">Skip</label></div>
    </div>
</div>
