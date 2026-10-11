<?php
/**
 * The Settings tab (#source-settings): the connector's settings as a definition list (a URL without its query string), the credential's kind, label and
 * LAST FOUR — never more — and the form that seals a new one (#source-credential-form; people only; without a secrets key it says so). Data: s, caps, may, here
 */
$id = (int) $s['source_id'];
$tz = member_timezone();
$set = present_settings((array) $s['settings']);
unset($set['target_dataset_id']);
$row = static fn (string $label, string $html, string $dd = ''): string => '<dt class="col-sm-3 fs-12 text-muted">' . e($label) . '</dt><dd class="col-sm-9"' . ($dd === '' ? '' : ' id="' . e($dd) . '"') . '>' . $html . '</dd>';
$code = static fn (mixed $v): string => '<code class="text-break">' . e(is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</code>';
$pre = static fn (mixed $v): string => '<pre class="fs-12 mb-0 text-break" style="white-space: pre-wrap">' . e((string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
$kindWords = ['bearer' => 'A bearer token', 'api_key' => 'An API key', 'basic' => 'A password (with the user name)', 'sftp_password' => 'An SFTP password', 'sftp_key' => 'An SFTP private key', 'database_password' => 'The database password'];
$c = $s['credential'];
?>
<div id="source-settings">
<div class="card mb-3"><div class="card-body">
    <dl class="row g-1 mb-0" id="source-settings-dl">
        <?= $row('Connector', e($caps['label'])) ?>
        <?php switch ($s['connector']):
            case 'http_file': ?>
                <?= $row('Address', $code($set['url'] ?? '—'), 'source-setting-url') ?>
                <?= $row('Format', e(DL_FILE_FORMATS[$set['format'] ?? 'auto'] ?? (string) ($set['format'] ?? 'auto'))) ?>
                <?php if (isset($set['username'])): ?><?= $row('User name', $code($set['username'])) ?><?php endif; ?>
                <?php if (isset($set['api_key_header'])): ?><?= $row('API key header', $code($set['api_key_header'])) ?><?php endif; ?>
                <?= $row('Rate', e(rtrim(rtrim(number_format((float) $s['rate_per_second'], 2), '0'), '.')) . ' request a second at most') ?>
                <?= $row('User-agent', $s['user_agent'] !== null ? $code($s['user_agent']) : '<span class="text-muted">the lake\'s own (MaluDB-DataLake/1.0 and this address)</span>') ?>
                <?php break;
            case 'sftp_file': $sf = (array) ($set['sftp'] ?? []); ?>
                <?= $row('Server', $code(($sf['host'] ?? '?') . ':' . ($sf['port'] ?? 22)), 'source-setting-sftp-host') ?>
                <?= $row('User', $code($sf['user'] ?? '—')) ?>
                <?= $row('Directory', $code($sf['path'] ?? '—')) ?>
                <?= $row('Files', $code($set['pattern'] ?? '*')) ?>
                <?= $row('Format', e(DL_FILE_FORMATS[$set['format'] ?? 'auto'] ?? (string) ($set['format'] ?? 'auto'))) ?>
                <?= $row('Host key', isset($sf['host_key_sha256']) ? 'pinned' : '<span class="text-muted">not pinned</span>') ?>
                <?php break;
            case 'k7': ?>
                <?= $row('Share', $code(($set['provider'] ?? '?') . ' · ' . ($set['tool'] ?? '?')), 'source-setting-share') ?>
                <?= $row('Arguments', $pre($set['arguments'] ?? new stdClass())) ?>
                <?= $row('Rows are in', $code($set['rows_path'] ?? '—')) ?>
                <?= $row('Paging', e(($set['paging'] ?? 'none') === 'offset' ? 'page by page, ' . (int) ($set['page_size'] ?? 200) . ' rows a page' : 'one answer')) ?>
                <?= $row('Window', e(($set['window'] ?? 'none') === 'date' ? 'by date: from the day after the last pull (the first reaches 92 days back) to yesterday' : 'none')) ?>
                <?php if (!empty($set['inject'])): ?><?= $row('Added to every row', $pre($set['inject'])) ?><?php endif; ?>
                <?php break;
            case 'postgres': ?>
                <?= $row('Server', $code(($set['host'] ?? '?') . ':' . ($set['port'] ?? 5432)), 'source-setting-pg-host') ?>
                <?= $row('Database', $code($set['dbname'] ?? '—')) ?>
                <?= $row('User', $code($set['user'] ?? '—')) ?>
                <?= $row('Connection', $code($set['sslmode'] ?? 'require')) ?>
                <?= $row('Table', $code($set['table'] ?? '—')) ?>
                <?= $row('Watermark', isset($set['watermark']) ? $code($set['watermark']) . ' <span class="fs-12 text-muted">— only rows after the last pull</span>' : '<span class="text-muted">none — the dataset is replaced each pull</span>') ?>
                <?= $row('Key', isset($set['key']) ? $code(implode(', ', (array) $set['key'])) : '<span class="text-muted">none</span>') ?>
                <?php break;
            default: ?>
                <?= $row('Saved mapping', isset($set['template_id']) ? e('#' . (int) $set['template_id']) : '<span class="text-muted">none — people map each file</span>') ?>
        <?php endswitch; ?>
        <?php if (isset($set['template_id']) && $s['connector'] !== 'upload'): ?><?= $row('Saved mapping', e('#' . (int) $set['template_id'])) ?><?php endif; ?>
        <?= $row('Schedule', e(schedule_words((int) $s['schedule_minutes']))) ?>
    </dl>
</div></div>
<div class="card mb-3" id="source-credential"><div class="card-body">
    <h6 class="fs-12 text-uppercase text-muted">Credential</h6>
    <?php if ($caps['credential_kinds'] === []): ?>
        <p class="fs-12 text-muted mb-0" id="source-credential-none"><?= $s['connector'] === 'k7' ? 'None — the kernel makes the read for the lake; the lake holds no key to the other application.' : 'This connector takes no credential.' ?></p>
    <?php else: ?>
        <?php if ($c !== null): ?>
            <div class="mb-2" id="source-credential-summary"><span class="badge bg-soft-success text-success">Sealed</span>
                <span class="ms-1"><?= e($kindWords[$c['kind']] ?? $c['kind']) ?></span> — <span class="fw-semibold"><?= e((string) $c['label']) ?></span>,
                ending <code id="source-credential-last4"><?= e((string) $c['last4']) ?></code>
                <span class="fs-12 text-muted">· set <?= e(format_ts($c['created_at'], $tz, 'M j, Y')) ?><?= $c['rotated_at'] !== null ? ' · replaced ' . e(format_ts($c['rotated_at'], $tz, 'M j, Y')) : '' ?></span></div>
        <?php else: ?>
            <p class="fs-12 text-muted" id="source-credential-missing"><?= $s['connector'] === 'http_file' ? 'None — a public file needs none.' : 'None yet — it cannot be pulled without one.' ?></p>
        <?php endif; ?>
        <?php if ($may['credential']): $hasKey = dl_secrets_key_present(); ?>
            <?php if (!$hasKey): ?><div class="alert alert-warning fs-12" id="source-credential-nokey">This installation has no secrets key (DL_SECRETS_KEY), so a credential cannot be kept until the owner sets one.</div><?php endif; ?>
            <form method="post" action="/sources/credential.php" hx-post="/sources/credential.php" hx-target="#flash" id="source-credential-form" autocomplete="off">
                <?= csrf_field() ?><input type="hidden" name="source" value="<?= $id ?>">
                <div class="row g-2">
                    <div class="col-md-4"><label class="form-label" for="source-credential-field-kind">Kind</label>
                        <select class="form-select btn-touch" id="source-credential-field-kind" name="kind">
                            <?php foreach ($caps['credential_kinds'] as $k): ?><option value="<?= e($k) ?>"<?= $c !== null && $c['kind'] === $k ? ' selected' : '' ?>><?= e($kindWords[$k] ?? $k) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-md-4"><label class="form-label" for="source-credential-field-label">Label</label>
                        <input type="text" class="form-control btn-touch" id="source-credential-field-label" name="label" maxlength="120" placeholder="Nightly export login" autocomplete="off"></div>
                    <div class="col-md-4"><label class="form-label" for="source-credential-field-secret">Secret</label>
                        <input type="password" class="form-control btn-touch" id="source-credential-field-secret" name="secret" autocomplete="new-password" spellcheck="false"></div>
                    <?php if (in_array('sftp_key', $caps['credential_kinds'], true)): ?>
                        <div class="col-12"><label class="form-label" for="source-credential-field-secret_key">Private key <span class="text-muted">(for an SFTP private key — paste it whole)</span></label>
                            <textarea class="form-control" id="source-credential-field-secret_key" name="secret_key" rows="3" autocomplete="off" spellcheck="false"></textarea></div>
                    <?php endif; ?>
                </div>
                <div class="form-text mb-2">It is sealed at once and never shown again — only its last four characters. A new one replaces the old.</div>
                <button type="submit" class="btn btn-primary btn-touch" id="source-credential-save-btn"<?= $hasKey ? '' : ' disabled' ?>><i class="feather-lock me-1"></i><?= $c === null ? 'Seal the credential' : 'Replace the credential' ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div></div>
</div>
