<?php
declare(strict_types=1);
/**
 * Action `source_credential_set` (log source.credential_set — sources.manage; PEOPLE; pauses for an agent: other): **source**, **kind** (what the
 * connector takes: bearer, api_key, basic · sftp_password, sftp_key · database_password), **secret** (sealed at once under DL_SECRETS_KEY — never
 * shown again, never logged, never in a URL), label. The old credential is replaced; a source paused for want of one is resumed. The form is on the
 * source's page (#source-credential-form). Without a secrets key the answer is `secrets_key_missing` (503).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/write.php';
[$pdo, $s, $me, $target] = source_action_begin();
require_human();
$kind = (string) (req_val('kind') ?? '');
$secret = (string) ($_POST['secret'] ?? '');
if ($secret === '' && isset($_POST['secret_key']) && is_string($_POST['secret_key'])) {
    $secret = $_POST['secret_key'];                            // the private key field of an sftp_key credential
}
unset($_POST['secret'], $_POST['secret_key']);                 // from here on nothing can echo it
$errors = [];
$kinds = dl_fetch_connector((string) $s['connector'])->capabilities()['credential_kinds'];
if ($kinds === []) {
    $errors['kind'] = 'The ' . $s['connector'] . ' connector takes no credential.';
} elseif (!in_array($kind, $kinds, true)) {
    $errors['kind'] = 'The ' . $s['connector'] . ' connector takes ' . implode(' or ', $kinds) . ', not ' . ($kind === '' ? 'nothing' : $kind) . '.';
}
if (trim($secret) === '') {
    $errors['secret'] = 'Give the secret.';
}
$label = dl_text('label', null, 120, 'The label', $errors);
if ($errors !== []) {
    dl_refuse_fields($errors);
}
if (!dl_secrets_key_present()) {
    emit_action_status(false, ['error' => 'secrets_key_missing']);
    $msg = 'secrets_key_missing: this installation has no secrets key (DL_SECRETS_KEY), so a credential cannot be kept until the owner sets one.';
    wants_json() ? json_error('secrets_key_missing', $msg, 503) : refuse(503, $msg);
}
$was = $s['credential'] !== null;
dl_guard($pdo, static function () use ($pdo, $s, $kind, $secret, $label, $me, $target, $was): void {
    $pdo->beginTransaction();
    source_credential_set($pdo, (int) $s['source_id'], $kind, $secret, $label, $me);
    $c = one_row($pdo, 'SELECT id, label FROM source_credentials WHERE source_id = :s ORDER BY id DESC LIMIT 1', ['s' => (int) $s['source_id']]);
    log_activity($pdo, 'source.credential_set', 'source', (int) $s['source_id'], ['dataset_id' => $target, 'after' => ['credential_id' => (int) $c['id'], 'kind' => $kind, 'label' => $c['label'], 'rotated' => $was]]);
    $pdo->commit();
});
dl_done('Sealed the credential for ' . $s['name'], (int) $s['source_id'], dl_land(return_path('/sources/' . (int) $s['source_id']), 'credential', 'source-credential-form'), 'sourceChanged');
