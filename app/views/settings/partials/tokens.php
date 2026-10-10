<?php /** My MCP tokens (token_mint, token_revoke). Data: tokens, tz, base */ ?>
<div class="card mb-0 h-100" id="tokens-card"><div class="card-header"><h5 class="card-title mb-0">MCP tokens</h5></div><div class="card-body fs-12">
    <p>Your own AI tools (Claude Desktop, Claude Code, an agent of yours) read the Data Lake's two MCP servers with an MCP token as a Bearer token. They see exactly what you see: the datasets you may discover, your levels, your masks.</p>
    <div class="mb-1"><span class="text-muted">Records:</span> <code id="tokens-url-records" class="text-break"><?= e($base) ?>/mcp/records</code></div>
    <div class="mb-3"><span class="text-muted">Activity:</span> <code id="tokens-url-activity" class="text-break"><?= e($base) ?>/mcp/activity</code></div>
    <form method="post" action="/settings/tokens/mint.php" id="token-form" class="row g-2 align-items-end mb-3" hx-post="/settings/tokens/mint.php" hx-target="#page-content" hx-swap="innerHTML">
        <?= csrf_field() ?>
        <div class="col-12 col-md-7"><label class="form-label fs-12 text-muted" for="token-form-field-label">Label</label><input type="text" name="label" id="token-form-field-label" class="form-control btn-touch" required maxlength="80" placeholder="Claude Desktop"></div>
        <div class="col-9 col-md-3"><label class="form-label fs-12 text-muted" for="token-form-field-expires">Expires in (days)</label><input type="number" name="expires_in_days" id="token-form-field-expires" class="form-control btn-touch" min="1" max="3650" placeholder="never"></div>
        <div class="col-3 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="token-form-save-btn" aria-label="Mint a token"><i class="feather-key"></i></button></div>
    </form>
    <div class="list-group" id="token-list">
        <?php if ($tokens === []): ?><div class="list-group-item text-muted" id="tokens-empty">No MCP tokens yet.</div><?php endif; ?>
        <?php foreach ($tokens as $t): $id = (int) $t['id']; $live = token_live($t); ?>
            <div class="list-group-item d-flex justify-content-between align-items-center gap-2<?= $live ? '' : ' text-muted' ?>" id="token-<?= $id ?>">
                <div class="min-w-0">
                    <div class="fw-semibold text-truncate"><?= e($t['label']) ?><?= $t['revoked_at'] !== null ? ' <span class="badge bg-soft-secondary text-secondary">revoked</span>' : ($live ? '' : ' <span class="badge bg-soft-secondary text-secondary">expired</span>') ?></div>
                    <div class="fs-11 text-muted">made <?= e(format_ts($t['created_at'], $tz, 'M j, Y')) ?> · last used <?= e(format_ts($t['last_used_at'], $tz) ?: 'never') ?> · expires <?= e(format_ts($t['expires_at'], $tz, 'M j, Y') ?: 'never') ?></div>
                </div>
                <?php if ($t['revoked_at'] === null): ?><form method="post" action="/settings/tokens/revoke.php" hx-post="/settings/tokens/revoke.php" hx-confirm="Revoke the token <?= e($t['label']) ?>? Anything using it stops working." hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="token" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" id="token-<?= $id ?>-revoke-btn" aria-label="Revoke <?= e($t['label']) ?>"><i class="feather-x"></i></button></form><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div></div>
