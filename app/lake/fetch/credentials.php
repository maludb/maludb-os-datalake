<?php
declare(strict_types=1);

/**
 * A source's credential, sealed and opened — THE ONLY FILE THAT DECRYPTS (Inventory's app/sources/credentials.php ported, inv_ → dl_,
 * slice 6). libsodium secretbox under DL_SECRETS_KEY (64 hex characters = 32 bytes) from config/.env. Nothing here logs, prints or keeps a
 * copy; an exception's message never carries the secret or the key.
 *
 *   dl_seal(['kind' => 'bearer', 'token' => '…'])  → "v1.<base64url nonce+box>"  (what source_credentials.ciphertext holds)
 *   dl_open($ciphertext)                            → the array, or DlCredentialError when it was tampered with or the key is wrong
 *   dl_credential_summary($cred)                    → ['kind', 'label', 'last4'] — what a screen may show
 *
 * The lake's kinds are the table's subset the five v1 connectors take: api_key and bearer and basic for a file at a URL, sftp_password and
 * sftp_key for an SFTP directory, database_password for an outside PostgreSQL database. The secret alone is stored here; the user name
 * is a setting of the source (it is not secret).
 */

final class DlCredentialError extends RuntimeException
{
}

/** The credential kinds of source_credentials.kind that a v1 connector takes. */
const DL_CREDENTIAL_KINDS = ['api_key', 'bearer', 'basic', 'sftp_password', 'sftp_key', 'database_password'];

/** The 32-byte key from DL_SECRETS_KEY (getenv, the kit's env() when loaded, $_ENV) — never cached in a global. */
function dl_secrets_key(): string
{
    $hex = getenv('DL_SECRETS_KEY');
    if (($hex === false || $hex === '') && function_exists('env')) {
        $hex = env('DL_SECRETS_KEY');
    }
    if (($hex === false || $hex === null || $hex === '') && isset($_ENV['DL_SECRETS_KEY'])) {
        $hex = $_ENV['DL_SECRETS_KEY'];
    }
    if (!is_string($hex) || !preg_match('~^[0-9a-fA-F]{64}$~', trim($hex))) {
        throw new DlCredentialError('secrets_key_missing: DL_SECRETS_KEY must be 64 hex characters in config/.env');
    }
    $key = sodium_hex2bin(trim($hex));
    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new DlCredentialError('secrets_key_invalid');
    }
    return $key;
}

/** Is there a usable key? (a screen asks before offering the credential form). */
function dl_secrets_key_present(): bool
{
    try {
        $k = dl_secrets_key();
        sodium_memzero($k);
        return true;
    } catch (DlCredentialError) {
        return false;
    }
}

/** A fresh key for the installer (hex). */
function dl_secrets_key_generate(): string
{
    return sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
}

/** Seal a credential array; the result is text safe for a bytea or text column. */
function dl_seal(array $cred): string
{
    if (!isset($cred['kind']) || !in_array($cred['kind'], DL_CREDENTIAL_KINDS, true)) {
        throw new DlCredentialError('credential_kind_invalid');
    }
    $key = dl_secrets_key();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = json_encode($cred, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $box = sodium_crypto_secretbox($plain, $nonce, $key);
    sodium_memzero($plain);
    sodium_memzero($key);
    return 'v1.' . rtrim(strtr(base64_encode($nonce . $box), '+/', '-_'), '=');
}

/** Open a sealed credential; DlCredentialError on a wrong key, a tampered box or an unknown version. */
function dl_open(string $ciphertext): array
{
    $ciphertext = trim($ciphertext);
    if (!str_starts_with($ciphertext, 'v1.')) {
        throw new DlCredentialError('credential_unreadable: unknown version');
    }
    $bin = base64_decode(strtr(substr($ciphertext, 3), '-_', '+/'), true);
    if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        throw new DlCredentialError('credential_unreadable: malformed');
    }
    $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $box = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key = dl_secrets_key();
    $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
    sodium_memzero($key);
    if ($plain === false) {
        throw new DlCredentialError('credential_unreadable: the seal does not open (tampered, or sealed under another key)');
    }
    $cred = json_decode($plain, true);
    sodium_memzero($plain);
    if (!is_array($cred) || !isset($cred['kind'])) {
        throw new DlCredentialError('credential_unreadable: not a credential');
    }
    return $cred;
}

/** The secret-bearing field of a credential, by kind. */
function dl_credential_secret_field(string $kind): string
{
    return match ($kind) {
        'api_key' => 'api_key',
        'basic', 'sftp_password', 'database_password' => 'password',
        'sftp_key' => 'private_key',
        'bearer' => 'token',
        default => 'secret',
    };
}

/** What a screen shows: the kind, the label and the last four characters of the secret — never more (a key's fingerprint prefix, not its tail). */
function dl_credential_summary(array $cred): array
{
    $kind = (string) ($cred['kind'] ?? '');
    $secret = (string) ($cred[dl_credential_secret_field($kind)] ?? '');
    $last4 = $secret === '' ? '' : ($kind === 'sftp_key' ? substr(hash('sha256', $secret), 0, 4) : substr($secret, -4));
    return ['kind' => $kind, 'label' => (string) ($cred['label'] ?? ''), 'last4' => $last4];
}
