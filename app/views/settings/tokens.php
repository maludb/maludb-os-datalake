<?php
/**
 * My tokens and keys (screen `settings-tokens`): MCP tokens (my own AI reads the lake's two MCP servers as me) and API keys (a script or an
 * outside system calls the lake's API as me — rate-limited, the SQL switch for sql.editor). A secret is shown ONCE, here, after the action
 * that made it. Data: tokens, keys, minted (once), tz, may (sql), defaults, notice
 */
$base = rtrim((string) env('APP_URL', ''), '/');
?>
<?= view('shared/page-header.php', ['id' => 'tokens', 'title' => 'Tokens and keys', 'crumbs' => [['Home', '/'], ['My settings', '/settings/'], ['Tokens and keys', null]]]) ?>
<div class="main-content" id="tokens-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($minted): ?>
    <div class="alert alert-success" id="tokens-minted">
        <div class="fw-bold mb-1">Your new <?= $minted['kind'] === 'key' ? 'API key' : 'MCP token' ?> "<?= e($minted['label']) ?>" — copy it now; it is shown once.<?= !empty($minted['rotated_from']) ? ' The key it replaces keeps working for 24 hours.' : '' ?></div>
        <code class="user-select-all key-secret" id="tokens-minted-value"><?= e($minted['secret']) ?></code>
    </div>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="settings-tabs">
        <?= hx_link('/settings/', 'How I am told', 'btn btn-touch btn-light', 'id="settings-tab-notify"') ?>
        <?= hx_link('/settings/tokens/', 'Tokens and keys', 'btn btn-touch btn-primary', 'id="settings-tab-tokens"') ?>
    </div>
    <div class="row g-3">
        <div class="col-xl-6"><?= view('settings/partials/tokens.php', ['tokens' => $tokens, 'tz' => $tz, 'base' => $base]) ?></div>
        <div class="col-xl-6"><?= view('settings/partials/keys.php', ['keys' => $keys, 'tz' => $tz, 'base' => $base, 'may' => $may, 'defaults' => $defaults]) ?></div>
    </div>
</div>
