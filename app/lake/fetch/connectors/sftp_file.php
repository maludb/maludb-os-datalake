<?php
declare(strict_types=1);

/**
 * The `sftp_file` connector (sources-pulls.md): a DIRECTORY on an SFTP server. The pull lists it, keeps the files whose name matches the
 * pattern (a glob) and whose identity — name | size | modified — is not among the last 500 seen (policy.state.seen), oldest first, and
 * lands each one as its own blob and load. Inventory's transport ported: the ssh2 extension when present, else `curl sftp://` with a netrc
 * file (the password never on a command line), else the `sftp` command in batch mode (keys only). The password or private key is the
 * credential's alone (sftp_password · sftp_key); the host, port, user and path are settings.
 *
 * THE SEAM: `$source['sftp_runner']` (tests only — dl_source_for_connector() takes it from $GLOBALS['__dl_fetch_test'] outside production)
 * receives the command PLAN — ['op' => list|get, 'tool', 'host', 'port', 'user', 'dir', 'name'?, 'dest'?, 'argv', 'files'] — and answers it
 * without a network: for `list` ['status' => 0, 'listing' => [{name, size, mtime, stamp}…]], for `get` it writes `dest` and answers ['status' => 0].
 *
 * settings: sftp {host, port (22), user, path (the directory), host_key_sha256?}, pattern (default *), format, template_id?.
 * A host key that is not pinned (host_key_sha256, the base64 SHA-256 of the key as `ssh-keyscan | ssh-keygen -lf` prints it) is not checked —
 * probe() says so.
 */
final class DlConnectorSftpFile implements DlConnector
{
    /** Files one pull takes (the rest wait for the next one): a directory with thousands of new files must not hold the worker's pass. */
    public const FILES_PER_PULL = 200;

    public function capabilities(): array
    {
        return dl_capabilities(['label' => 'An SFTP directory', 'icon' => 'feather-server', 'needs_credential' => true, 'credential_kinds' => ['sftp_password', 'sftp_key'],
            'description' => 'Every new file that appears in a directory on an SFTP server — each lands once, oldest first.']);
    }

    public function validate(array $in, array $current = []): array
    {
        $errors = [];
        $cur = (array) ($current['sftp'] ?? []);
        $given = is_array($in['sftp'] ?? null) ? $in['sftp'] : [];
        $pick = static fn (string $k) => $in['sftp_' . $k] ?? ($given[$k] ?? ($cur[$k] ?? null));
        $host = strtolower((string) (dl_str($pick('host'), 253) ?? ''));
        if ($host === '' || !preg_match('~^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$~', $host)) {
            $errors['sftp_host'] = 'Give the server\'s name or address (letters, digits, dots and hyphens).';
        } elseif (dl_fetch_host_refusal($host) !== null) {
            $errors['sftp_host'] = 'That address is this server\'s own or a reserved one — an SFTP source reads a server outside this one.';
        }
        $port = $pick('port');
        $port = $port === null || $port === '' ? 22 : (ctype_digit((string) $port) ? (int) $port : 0);
        if ($port < 1 || $port > 65535) {
            $errors['sftp_port'] = 'The port is from 1 to 65535 (22 is usual).';
        }
        $user = dl_str($pick('user'), 100);
        if ($user === null || !preg_match('~^[A-Za-z0-9._\-@]{1,100}$~', $user)) {
            $errors['sftp_user'] = 'Give the user name (letters, digits and . _ - @).';
        }
        $path = dl_str($pick('path'), 1000);
        if ($path === null || !preg_match('#^[A-Za-z0-9._\-/ ~+@=,]+$#', $path) || preg_match('~(^|/)\.\.(/|$)~', $path)) {
            $errors['sftp_path'] = 'Give the directory to read, such as /outgoing/reports (no "..").';
        }
        $pin = dl_str($in['host_key_sha256'] ?? $pick('host_key_sha256'), 80);
        if ($pin !== null && !preg_match('~^(SHA256:)?[A-Za-z0-9+/]{43}=?$~', $pin)) {
            $errors['host_key_sha256'] = 'The host key is the SHA-256 fingerprint ssh-keygen prints (43 characters).';
        }
        $pattern = dl_str($in['pattern'] ?? ($current['pattern'] ?? null), 200) ?? '*';
        if (!preg_match('~^[A-Za-z0-9._\-*?\[\]]+$~', $pattern)) {
            $errors['pattern'] = 'A pattern is a file name with * and ? (and [abc]) in it, such as sales_*.csv.';
        }
        $format = dl_format_setting($in['format'] ?? ($current['format'] ?? null), $errors);
        $t = dl_template_setting($in['template_id'] ?? ($current['template_id'] ?? null), $errors);
        $settings = ['sftp' => array_filter(['host' => $host, 'port' => $port, 'user' => $user, 'path' => $path, 'host_key_sha256' => $pin === null ? null : preg_replace('~^SHA256:~', '', $pin)],
            static fn ($v) => $v !== null), 'pattern' => $pattern, 'format' => $format];
        if ($t !== null) {
            $settings['template_id'] = $t;
        }
        return [$settings, $errors];
    }

    public function probe(array $source): array
    {
        $r = dl_sftp_list($source);
        if (!$r['ok']) {
            return dl_probe_result('misconfigured', 'The directory could not be read: ' . $r['error'] . '.', ['tool' => $r['tool']]);
        }
        $pattern = (string) dl_setting($source, 'pattern', '*');
        $match = array_values(array_filter($r['files'], static fn (array $f): bool => fnmatch($pattern, $f['name'])));
        $pinned = dl_setting($source, 'sftp', [])['host_key_sha256'] ?? null;
        return dl_probe_result('ok', 'The directory answers: ' . count($r['files']) . ' file' . (count($r['files']) === 1 ? '' : 's') . ', ' . count($match) . ' matching ' . $pattern
            . ($pinned === null ? ' (the server\'s host key is not pinned)' : '') . '.', ['tool' => $r['tool'], 'files' => count($r['files']), 'matching' => count($match), 'host_key_pinned' => $pinned !== null]);
    }

    public function pull(array $source, callable $emit): array
    {
        $r = dl_sftp_list($source);
        if (!$r['ok']) {
            return dl_pull_stats(null, 0, ['the directory could not be read: ' . $r['error']], 'failed', ['state' => (array) ($source['state'] ?? [])]);
        }
        $pattern = (string) dl_setting($source, 'pattern', '*');
        $seen = array_values(array_filter((array) (($source['state'] ?? [])['seen'] ?? []), 'is_string'));
        $have = array_flip($seen);
        $new = array_values(array_filter($r['files'], static fn (array $f): bool => fnmatch($pattern, $f['name']) && !isset($have[dl_sftp_file_id($f)])));
        usort($new, static fn (array $a, array $b): int => [$a['mtime'], $a['name']] <=> [$b['mtime'], $b['name']]);
        $matching = count(array_filter($r['files'], static fn (array $f): bool => fnmatch($pattern, $f['name'])));
        $errors = [];
        $landed = 0;
        $took = 0;
        foreach (array_slice($new, 0, self::FILES_PER_PULL) as $f) {
            $dest = (string) $source['work_dir'] . '/sftp-' . bin2hex(random_bytes(5));
            $g = dl_sftp_get($source, $f['name'], $dest);
            if (!$g['ok']) {
                @unlink($dest);
                $errors[] = $f['name'] . ': ' . $g['error'];
                continue;
            }
            $took++;
            $res = $emit(['path' => $dest, 'name' => $f['name'], 'format' => (string) dl_setting($source, 'format', 'auto'), 'mode' => 'append', 'cursor' => mb_substr($f['name'], 0, 200)]);
            if (isset($res['error']) && $res['error'] !== null) {
                $errors[] = $f['name'] . ': ' . $res['error'];
                continue;
            }
            $seen[] = dl_sftp_file_id($f);
            $landed += ($res['landed'] ?? false) ? 1 : 0;
        }
        $stats = dl_pull_stats(null, $matching, $errors, null, ['state' => ['seen' => array_slice($seen, -500)], 'files_new' => count($new), 'files_taken' => $took,
            'files_waiting' => max(0, count($new) - self::FILES_PER_PULL), 'tool' => $r['tool']]);
        if ($errors !== []) {
            $stats['status'] = $took > 0 ? 'partial' : 'failed';      // some files landed and some did not, or none could be read
        }
        return $stats;
    }

    public function search(array $source, string $q, int $limit = 20): array
    {
        throw new DlNotSupported('unsupported');
    }

    public function lookup(array $source, array $identifiers): array
    {
        throw new DlNotSupported('unsupported');
    }
}

/** A file's identity for the "seen" list: name | size | the modified stamp as the server printed it. */
function dl_sftp_file_id(array $f): string
{
    return mb_substr($f['name'], 0, 200) . '|' . (int) $f['size'] . '|' . (string) ($f['stamp'] ?? $f['mtime']);
}

/** Which SFTP tool this server has: ssh2, curl (built with sftp), sftp, or none. */
function dl_sftp_tool(): string
{
    static $tool = null;
    if ($tool !== null) {
        return $tool;
    }
    if (function_exists('ssh2_connect')) {
        return $tool = 'ssh2';
    }
    $curl = trim((string) shell_exec('command -v curl 2>/dev/null'));
    if ($curl !== '' && preg_match('~\bsftp\b~', (string) shell_exec(escapeshellarg($curl) . ' -V 2>/dev/null'))) {
        return $tool = 'curl';
    }
    if (trim((string) shell_exec('command -v sftp 2>/dev/null')) !== '') {
        return $tool = 'sftp';
    }
    return $tool = 'none';
}

/** The settings and credential of a source as one connection description, or an error sentence. */
function dl_sftp_target(array $source): array
{
    $s = (array) dl_setting($source, 'sftp', []);
    $cred = $source['credential'] ?? null;
    $host = (string) ($s['host'] ?? '');
    $user = (string) ($s['user'] ?? '');
    $dir = (string) ($s['path'] ?? '');
    if ($host === '' || $user === '' || $dir === '') {
        return ['error' => 'sftp needs host, user and path'];
    }
    if (dl_fetch_host_refusal($host) !== null) {                            // judged again at every pull: a name may come to resolve to this server
        return ['error' => 'the address is this server\'s own or a reserved one'];
    }
    if (!is_array($cred) || !in_array($cred['kind'] ?? '', ['sftp_password', 'sftp_key'], true)) {
        return ['error' => 'sftp needs a credential of kind sftp_password or sftp_key'];
    }
    return ['host' => $host, 'port' => (int) ($s['port'] ?? 22) ?: 22, 'user' => $user, 'dir' => rtrim($dir, '/') . '/', 'pin' => $s['host_key_sha256'] ?? null, 'cred' => $cred];
}

/**
 * The command plan for one operation. ['op', 'tool', 'host', 'port', 'user', 'dir', 'name'?, 'dest'?, 'argv' => [...], 'files' => [path => content] (private
 * files the plan needs: a netrc, a key — written 0600, removed after), 'dir_tmp']. The password goes in a netrc file, never on argv.
 */
function dl_sftp_plan(string $tool, array $t, string $op, ?string $name = null, ?string $dest = null): array
{
    $tmp = sys_get_temp_dir() . '/dl-sftp-' . bin2hex(random_bytes(6));
    $plan = ['op' => $op, 'tool' => $tool, 'host' => $t['host'], 'port' => $t['port'], 'user' => $t['user'], 'dir' => $t['dir'], 'name' => $name, 'dest' => $dest,
             'argv' => [], 'files' => [], 'dir_tmp' => $tmp];
    $kind = $t['cred']['kind'];
    $url = 'sftp://' . $t['host'] . ':' . $t['port'] . implode('/', array_map('rawurlencode', explode('/', $t['dir'] . ($name ?? ''))));
    if ($tool === 'curl') {
        $argv = ['curl', '--silent', '--show-error', '--fail', '--max-time', $op === 'get' ? '600' : '120'];
        $argv[] = $t['pin'] !== null ? '--hostpubsha256' : '--insecure';
        if ($t['pin'] !== null) {
            $argv[] = (string) $t['pin'];
        }
        if ($kind === 'sftp_password') {
            $plan['files'][$tmp . '/netrc'] = 'machine ' . $t['host'] . ' login ' . $t['user'] . ' password ' . str_replace(["\n", "\r"], '', (string) ($t['cred']['password'] ?? '')) . "\n";
            array_push($argv, '--netrc-file', $tmp . '/netrc');
        } else {
            $plan['files'][$tmp . '/key'] = rtrim((string) ($t['cred']['private_key'] ?? '')) . "\n";
            array_push($argv, '--user', $t['user'] . ':', '--key', $tmp . '/key', '--pubkey', '');
        }
        if ($op === 'get') {
            array_push($argv, '--output', (string) $dest);
        }
        $argv[] = $url;
        $plan['argv'] = $argv;
    } elseif ($tool === 'sftp') {
        if ($kind !== 'sftp_key') {
            $plan['error'] = 'the sftp command takes a key, not a password; give an sftp_key credential or install curl';
            return $plan;
        }
        $plan['files'][$tmp . '/key'] = rtrim((string) ($t['cred']['private_key'] ?? '')) . "\n";
        $remote = $t['dir'] . ($name ?? '');
        $plan['files'][$tmp . '/batch'] = $op === 'list' ? "ls -l " . escapeshellarg(str_replace(["\n", "\r"], '', $t['dir'])) . "\nquit\n"
            : 'get ' . escapeshellarg(str_replace(["\n", "\r"], '', $remote)) . ' ' . escapeshellarg((string) $dest) . "\nquit\n";
        $plan['argv'] = ['sftp', '-q', '-P', (string) $t['port'], '-i', $tmp . '/key', '-o', 'BatchMode=yes', '-o', $t['pin'] !== null ? 'StrictHostKeyChecking=yes' : 'StrictHostKeyChecking=accept-new',
                         '-b', $tmp . '/batch', $t['user'] . '@' . $t['host']];
    }
    return $plan;
}

/** Run a plan: with a runner (tests) it answers; with ssh2 the extension; else the command, the private files removed after. ['status', 'out', 'err', 'listing'?]. */
function dl_sftp_exec(array $plan, ?callable $runner, array $t): array
{
    if ($runner !== null) {
        return $runner($plan) + ['out' => '', 'err' => ''];
    }
    if (isset($plan['error'])) {
        return ['status' => 1, 'out' => '', 'err' => $plan['error']];
    }
    if ($plan['tool'] === 'ssh2') {
        return dl_sftp_ssh2($plan, $t);
    }
    $tmp = $plan['dir_tmp'];
    if (!@mkdir($tmp, 0700, true) && !is_dir($tmp)) {
        return ['status' => 1, 'out' => '', 'err' => 'cannot make a private temp dir'];
    }
    try {
        foreach ($plan['files'] as $f => $content) {
            file_put_contents($f, $content);
            chmod($f, 0600);
        }
        $proc = proc_open($plan['argv'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tmp);
        if (!is_resource($proc)) {
            return ['status' => 1, 'out' => '', 'err' => 'cannot start ' . $plan['tool']];
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['status' => proc_close($proc), 'out' => $out, 'err' => mb_substr(preg_replace('~\s+~', ' ', trim($err)) ?? '', 0, 160)];
    } finally {
        foreach (array_keys($plan['files']) as $f) {
            @unlink($f);
        }
        @rmdir($tmp);
    }
}

/** The ssh2 extension's list / get. */
function dl_sftp_ssh2(array $plan, array $t): array
{
    $conn = @ssh2_connect($t['host'], $t['port']);
    if ($conn === false) {
        return ['status' => 1, 'out' => '', 'err' => 'cannot connect to ' . $t['host'] . ':' . $t['port']];
    }
    $cred = $t['cred'];
    if ($cred['kind'] === 'sftp_password') {
        $okAuth = @ssh2_auth_password($conn, $t['user'], (string) ($cred['password'] ?? ''));
    } else {
        $dir = sys_get_temp_dir() . '/dl-sftp-' . bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);
        file_put_contents($dir . '/key', rtrim((string) ($cred['private_key'] ?? '')) . "\n");
        chmod($dir . '/key', 0600);
        file_put_contents($dir . '/key.pub', (string) ($cred['public_key'] ?? ''));
        $okAuth = @ssh2_auth_pubkey_file($conn, $t['user'], $dir . '/key.pub', $dir . '/key', (string) ($cred['passphrase'] ?? ''));
        @unlink($dir . '/key');
        @unlink($dir . '/key.pub');
        @rmdir($dir);
    }
    if (!$okAuth) {
        return ['status' => 1, 'out' => '', 'err' => 'sftp authentication refused'];
    }
    $sftp = @ssh2_sftp($conn);
    if ($sftp === false) {
        return ['status' => 1, 'out' => '', 'err' => 'the sftp subsystem is not available'];
    }
    $base = 'ssh2.sftp://' . intval($sftp) . $t['dir'];
    if ($plan['op'] === 'get') {
        $in = @fopen($base . $plan['name'], 'rb');
        $out = $in === false ? false : @fopen((string) $plan['dest'], 'wb');
        if ($in === false || $out === false) {
            return ['status' => 1, 'out' => '', 'err' => 'cannot read ' . $plan['name']];
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        return ['status' => 0, 'out' => '', 'err' => ''];
    }
    $h = @opendir($base);
    if ($h === false) {
        return ['status' => 1, 'out' => '', 'err' => 'cannot read ' . $t['dir']];
    }
    $listing = [];
    while (($n = readdir($h)) !== false) {
        $st = @ssh2_sftp_stat($sftp, $t['dir'] . $n);
        if ($n === '.' || $n === '..' || $st === false || (($st['mode'] ?? 0) & 0170000) !== 0100000) {
            continue;
        }
        $listing[] = ['name' => $n, 'size' => (int) $st['size'], 'mtime' => (int) $st['mtime'], 'stamp' => (string) $st['mtime']];
    }
    closedir($h);
    return ['status' => 0, 'out' => '', 'err' => '', 'listing' => $listing];
}

/** An `ls -l` listing (curl's sftp:// directory listing, the sftp command's) → [{name, size, mtime, stamp}] for regular files. Pure. */
function dl_sftp_parse_listing(string $text, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $files = [];
    foreach (preg_split('~\r\n|\r|\n~', $text) ?: [] as $line) {
        if (!preg_match('~^-[rwxsStT\-]{9}[.+@]?\s+\d+\s+\S+\s+\S+\s+(\d+)\s+([A-Za-z]{3})\s+(\d{1,2})\s+(\d{2}:\d{2}|\d{4})\s+(.+?)\s*$~', $line, $m)) {
            continue;                                                    // a directory, a link, the sftp> echo
        }
        [, $size, $mon, $day, $when, $name] = $m;
        $stamp = "$mon $day $when";
        if (str_contains($when, ':')) {
            $t = strtotime("$mon $day " . $now->format('Y') . " $when UTC");
            if ($t !== false && $t > $now->getTimestamp() + 86400) {
                $t = strtotime("$mon $day " . ((int) $now->format('Y') - 1) . " $when UTC");
            }
        } else {
            $t = strtotime("$mon $day $when 00:00 UTC");
        }
        $files[] = ['name' => $name, 'size' => (int) $size, 'mtime' => (int) ($t ?: 0), 'stamp' => $stamp];
    }
    return $files;
}

/** The directory's regular files: ['ok', 'files' => [{name, size, mtime, stamp}], 'error', 'tool']. */
function dl_sftp_list(array $source): array
{
    $t = dl_sftp_target($source);
    if (isset($t['error'])) {
        return ['ok' => false, 'files' => [], 'error' => $t['error'], 'tool' => null];
    }
    $runner = $source['sftp_runner'] ?? null;
    $tool = $runner !== null ? 'curl' : dl_sftp_tool();
    if ($tool === 'none') {
        return ['ok' => false, 'files' => [], 'error' => 'no SFTP tool on this server (the ssh2 extension, curl with sftp, or sftp)', 'tool' => 'none'];
    }
    $r = dl_sftp_exec(dl_sftp_plan($tool, $t, 'list'), $runner, $t);
    if ((int) ($r['status'] ?? 1) !== 0) {
        return ['ok' => false, 'files' => [], 'error' => $tool . ' exit ' . (int) ($r['status'] ?? 1) . ($r['err'] !== '' ? ': ' . $r['err'] : ''), 'tool' => $tool];
    }
    $files = isset($r['listing']) ? (array) $r['listing'] : dl_sftp_parse_listing((string) $r['out']);
    return ['ok' => true, 'files' => array_values($files), 'error' => null, 'tool' => $tool];
}

/** One file of the directory into $dest: ['ok', 'error']. */
function dl_sftp_get(array $source, string $name, string $dest): array
{
    $t = dl_sftp_target($source);
    if (isset($t['error'])) {
        return ['ok' => false, 'error' => $t['error']];
    }
    if (str_contains($name, '/') || $name === '..' || $name === '.') {
        return ['ok' => false, 'error' => 'a file name with a slash is not fetched'];
    }
    $runner = $source['sftp_runner'] ?? null;
    $tool = $runner !== null ? 'curl' : dl_sftp_tool();
    $r = dl_sftp_exec(dl_sftp_plan($tool, $t, 'get', $name, $dest), $runner, $t);
    if ((int) ($r['status'] ?? 1) !== 0 || !is_file($dest)) {
        return ['ok' => false, 'error' => $tool . ' exit ' . (int) ($r['status'] ?? 1) . ($r['err'] !== '' ? ': ' . $r['err'] : '')];
    }
    return ['ok' => true, 'error' => null];
}
