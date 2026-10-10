<?php /** My API keys (key_mint, key_rotate, key_revoke, key_sql_switch). Data: keys, tz, base, may (keys, sql), defaults */
$replaced = array_map('intval', array_filter(array_column($keys, 'rotated_from'), static fn ($v) => $v !== null));   // keys a rotation replaced (they end after the overlap)
?>
<div class="card mb-0 h-100" id="keys-card"><div class="card-header"><h5 class="card-title mb-0">API keys</h5></div><div class="card-body fs-12">
    <p>A script or an outside system calls the lake's API <code class="text-break"><?= e($base) ?>/api/v1/</code> with an API key, acting as you: what you may read, masked as you are, at most so many calls a minute and a day. Ad-hoc SQL over the API needs the SQL switch, and the SQL editor.</p>
    <?php if ($may['keys']): ?>
    <form method="post" action="/settings/tokens/key-mint.php" id="key-form" class="row g-2 align-items-end mb-3" hx-post="/settings/tokens/key-mint.php" hx-target="#page-content" hx-swap="innerHTML">
        <?= csrf_field() ?>
        <div class="col-12"><label class="form-label fs-12 text-muted" for="key-form-field-label">Label</label><input type="text" name="label" id="key-form-field-label" class="form-control btn-touch" required maxlength="80" placeholder="Nightly report script"></div>
        <div class="col-6 col-md-4"><label class="form-label fs-12 text-muted" for="key-form-field-minute">Calls a minute</label><input type="number" name="rate_per_minute" id="key-form-field-minute" class="form-control btn-touch" min="1" max="100000" placeholder="<?= (int) $defaults['rate_per_minute'] ?>"></div>
        <div class="col-6 col-md-4"><label class="form-label fs-12 text-muted" for="key-form-field-day">Calls a day</label><input type="number" name="rate_per_day" id="key-form-field-day" class="form-control btn-touch" min="1" max="10000000" placeholder="<?= (int) $defaults['rate_per_day'] ?>"></div>
        <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="key-form-field-expires">Expires in (days)</label><input type="number" name="expires_in_days" id="key-form-field-expires" class="form-control btn-touch" min="1" max="3650" placeholder="never"></div>
        <?php if ($may['sql']): ?>
        <div class="col-12"><input type="hidden" name="allow_sql" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="key-form-field-sql"><input type="checkbox" class="form-check-input mt-0" name="allow_sql" value="yes" id="key-form-field-sql"> This key may send ad-hoc SQL</label></div>
        <?php endif; ?>
        <div class="col-12"><button type="submit" class="btn btn-primary btn-touch w-100" id="key-form-save-btn"><i class="feather-key me-1"></i>Mint a key</button></div>
    </form>
    <?php endif; ?>
    <div class="list-group" id="key-list">
        <?php if ($keys === []): ?><div class="list-group-item text-muted" id="keys-empty">No API keys yet.</div><?php endif; ?>
        <?php foreach ($keys as $k): $id = (int) $k['id']; $live = token_live($k); $old = in_array($id, $replaced, true); ?>
            <div class="list-group-item<?= $live ? '' : ' text-muted' ?>" id="key-<?= $id ?>">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="min-w-0">
                        <div class="fw-semibold text-truncate"><?= e($k['label']) ?>
                            <?= $k['revoked_at'] !== null ? ' <span class="badge bg-soft-secondary text-secondary">revoked</span>' : ($live ? ($old ? ' <span class="badge bg-soft-warning text-warning" id="key-' . $id . '-replaced">replaced — ends ' . e(format_ts($k['expires_at'], $tz, 'M j, g:i A')) . '</span>' : '') : ' <span class="badge bg-soft-secondary text-secondary">expired</span>') ?>
                            <?= $k['allow_sql'] ? ' <span class="badge bg-soft-info text-info" id="key-' . $id . '-sql-badge">SQL</span>' : '' ?></div>
                        <div class="fs-11 text-muted"><?= (int) $k['rate_per_minute'] ?> a minute · <?= (int) $k['rate_per_day'] ?> a day · <?= (int) $k['calls_today'] ?> today · last used <?= e(format_ts($k['last_used_at'], $tz) ?: 'never') ?> · expires <?= e(format_ts($k['expires_at'], $tz, 'M j, Y g:i A') ?: 'never') ?><?= $k['rotated_from'] !== null ? ' · replaces key #' . (int) $k['rotated_from'] : '' ?></div>
                    </div>
                </div>
                <?php if ($live): ?>
                <div class="d-flex flex-wrap gap-1 mt-2">
                    <?php if ($may['sql']): ?>
                    <form method="post" action="/settings/tokens/key-sql.php" hx-post="/settings/tokens/key-sql.php" hx-target="#page-content" hx-swap="innerHTML" hx-confirm="<?= $k['allow_sql'] ? 'Stop this key sending SQL?' : 'Let this key send ad-hoc SQL as you?' ?>"><?= csrf_field() ?><input type="hidden" name="api_key" value="<?= $id ?>"><input type="hidden" name="allow_sql" value="<?= $k['allow_sql'] ? 'no' : 'yes' ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="key-<?= $id ?>-sql"><?= $k['allow_sql'] ? 'Turn SQL off' : 'Allow SQL' ?></button></form>
                    <?php endif; ?>
                    <?php if (!$old): ?>
                    <form method="post" action="/settings/tokens/key-rotate.php" hx-post="/settings/tokens/key-rotate.php" hx-target="#page-content" hx-swap="innerHTML" hx-confirm="Rotate the key <?= e($k['label']) ?>? A new key is made; this one keeps working for 24 hours."><?= csrf_field() ?><input type="hidden" name="api_key" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="key-<?= $id ?>-rotate-btn">Rotate</button></form>
                    <?php endif; ?>
                    <form method="post" action="/settings/tokens/key-revoke.php" hx-post="/settings/tokens/key-revoke.php" hx-target="#page-content" hx-swap="innerHTML" hx-confirm="Revoke the key <?= e($k['label']) ?>? Anything using it stops working at once."><?= csrf_field() ?><input type="hidden" name="api_key" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="key-<?= $id ?>-revoke-btn">Revoke</button></form>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div></div>
