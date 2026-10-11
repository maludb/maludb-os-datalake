<?php
declare(strict_types=1);

/**
 * The `postgres` connector (sources-pulls.md divergence 3): ONE table of an outside PostgreSQL database the business owns — never one of
 * this server's (dl_sources_guard refuses `localhost` by name at the source's saving; dl_fetch_host_refusal() refuses any name or address that is this
 * machine's, at the saving and at every connect). Pulled IN PHP, never in the writer (design §6.9: the writer is
 * network-blocked but for loopback): a READ-ONLY REPEATABLE READ transaction, a server-side cursor (DECLARE … FETCH 50000), each page
 * written as NDJSON (every scalar a string) and landed as one blob and one load. INCREMENTAL by the watermark column (rows after the last
 * pull's highest value, ordered by it); a source with no watermark replaces the dataset each pull (the first page `replace`, the rest
 * `append`) — and so does the WEEKLY RECONCILIATION'S reload, queued when the table's count(*) and the dataset's row count differ.
 * The pull is kind to the server it reads: one connection, one transaction, a statement timeout, nothing written.
 *
 * settings: host, port (5432), dbname, user, sslmode (require · verify-full · prefer), table (schema.table), watermark? (a column), key[] (columns that order the
 *           rows and say what a row is). At least a watermark or a key. credential: database_password (the secret only).
 */
final class DlConnectorPostgres implements DlConnector
{
    /** Rows in a page (a cursor FETCH). */
    public const PAGE_ROWS = 50000;

    /** The page size — DL_PG_PAGE_ROWS lowers it outside production (the proofs read a table of a hundred rows in pages of fifty). */
    public static function pageRows(): int
    {
        $n = !app_is_prod() ? getenv('DL_PG_PAGE_ROWS') : false;
        return $n !== false && ctype_digit((string) $n) && (int) $n > 0 ? (int) $n : self::PAGE_ROWS;
    }

    public function capabilities(): array
    {
        return dl_capabilities(['label' => 'An outside PostgreSQL database', 'icon' => 'feather-database', 'needs_credential' => true, 'credential_kinds' => ['database_password'],
            'description' => 'One table of a PostgreSQL database the business owns elsewhere — read in pages, only what changed since the last pull, checked against the table\'s count every week.']);
    }

    public function validate(array $in, array $current = []): array
    {
        $errors = [];
        $s = [];
        $host = strtolower((string) (dl_str($in['host'] ?? ($current['host'] ?? null), 253) ?? ''));
        if (!preg_match('~^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$~', $host)) {
            $errors['host'] = 'Give the database server\'s name or address (letters, digits, dots and hyphens).';
        } elseif (dl_fetch_host_refusal($host) !== null) {
            $errors['host'] = 'That address is this server\'s own or a reserved one — a postgres source reads a database the business owns elsewhere.';
        }
        $s['host'] = $host;
        $port = $in['port'] ?? ($current['port'] ?? null);
        $port = $port === null || $port === '' ? 5432 : (ctype_digit((string) $port) ? (int) $port : 0);
        if ($port < 1 || $port > 65535) {
            $errors['port'] = 'The port is from 1 to 65535 (5432 is usual).';
            $port = 5432;
        }
        $s['port'] = $port;
        $db = (string) (dl_str($in['dbname'] ?? ($current['dbname'] ?? null), 63) ?? '');
        if (!preg_match('~^[A-Za-z0-9_\-]{1,63}$~', $db)) {
            $errors['dbname'] = 'Give the database\'s name (letters, digits, - and _).';
        }
        $s['dbname'] = $db;
        $user = (string) (dl_str($in['user'] ?? ($current['user'] ?? null), 63) ?? '');
        if (!preg_match('~^[A-Za-z0-9_\-.@]{1,63}$~', $user)) {
            $errors['user'] = 'Give the database user (letters, digits and . _ - @) — a read-only one.';
        }
        $s['user'] = $user;
        $ssl = strtolower((string) ($in['sslmode'] ?? ($current['sslmode'] ?? 'require')));
        if (!in_array($ssl, ['require', 'verify-full', 'prefer'], true)) {
            $errors['sslmode'] = 'The connection is require, verify-full or prefer.';
            $ssl = 'require';
        }
        $s['sslmode'] = $ssl;
        $table = (string) (dl_str($in['table'] ?? ($current['table'] ?? null), 127) ?? '');
        if (!preg_match('~^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$~', $table)) {
            $errors['table'] = 'Name ONE table: schema.table (several tables are several sources).';
        }
        $s['table'] = $table;
        $wm = dl_str($in['watermark'] ?? ($current['watermark'] ?? null), 63);
        if ($wm !== null && !preg_match('~^[A-Za-z_][A-Za-z0-9_]*$~', $wm)) {
            $errors['watermark'] = 'The watermark is one column name — a timestamp or a serial that only grows.';
            $wm = null;
        }
        if ($wm !== null) {
            $s['watermark'] = $wm;
        }
        $keyIn = $in['key'] ?? ($current['key'] ?? []);
        $keys = is_array($keyIn) ? $keyIn : preg_split('~\s*,\s*~', trim((string) $keyIn), -1, PREG_SPLIT_NO_EMPTY);
        $keys = array_values(array_unique(array_map('trim', (array) $keys)));
        foreach ($keys as $k) {
            if (!preg_match('~^[A-Za-z_][A-Za-z0-9_]*$~', (string) $k)) {
                $errors['key'] = 'The key is column names, separated by commas.';
            }
        }
        if (count($keys) > 8) {
            $errors['key'] = 'The key is up to eight columns.';
        }
        if ($wm === null && $keys === []) {
            $errors['key'] = 'Give a watermark column or a key — the rows must come in a fixed order.';
        }
        if ($keys !== []) {
            $s['key'] = $keys;
        }
        return [$s, $errors];
    }

    /** "schema"."table" quoted; the name was validated. */
    private static function ident(string $name): string
    {
        return implode('.', array_map(static fn (string $p): string => '"' . $p . '"', explode('.', $name)));
    }

    /** The one connection (a read-only transaction by default, a statement timeout); throws DlMisconfigured with a sentence on failure. */
    public static function connect(array $source): PDO
    {
        $c = $source['credential'] ?? null;
        if (!is_array($c) || ($c['kind'] ?? '') !== 'database_password') {
            throw new DlMisconfigured('credential: the database password is not set');
        }
        $s = [];
        foreach (['host', 'port', 'dbname', 'user', 'sslmode'] as $k) {
            $s[$k] = dl_setting($source, $k, $k === 'port' ? 5432 : ($k === 'sslmode' ? 'require' : ''));
        }
        if (dl_fetch_host_refusal((string) $s['host']) !== null) {          // judged again at every pull: a name may come to resolve to this server
            throw new DlMisconfigured('host: the address is this server\'s own or a reserved one — a postgres source reads a database the business owns elsewhere');
        }
        try {
            $pdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=10;application_name=datalake-pull', $s['host'], (int) $s['port'], $s['dbname'], $s['sslmode']),
                (string) $s['user'], (string) ($c['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
            $pdo->exec("SET default_transaction_read_only = on");
            $pdo->exec("SET statement_timeout = '900s'");
            $pdo->exec("SET idle_in_transaction_session_timeout = '1800s'");
            return $pdo;
        } catch (PDOException $e) {
            throw new DlMisconfigured(self::sentence($e));
        }
    }

    /** A database error in one sentence of at most 160 characters — never a password (libpq prints none). */
    public static function sentence(Throwable $e): string
    {
        $m = (string) preg_replace('~^SQLSTATE\[[^\]]*\](\s*\[\d+\])?:?\s*~', '', $e->getMessage());
        $m = (string) preg_replace('~^[A-Za-z ]{3,40}:\s*\d+\s+(ERROR|FATAL):\s*~', '', $m);                  // "Undefined table: 7 ERROR:  relation …"
        $m = (string) preg_replace('~\s*LINE \d+:.*$~s', '', $m);                                              // the statement's own echo
        $m = (string) preg_replace('~^(connection to server.*?)\s+connection to server.*$~s', '$1', $m);        // libpq says it once for each address it tried
        $m = (string) preg_replace('~password=\S+~i', 'password=…', $m);
        return mb_substr(trim((string) preg_replace('~\s+~', ' ', $m)), 0, 160);
    }

    public function probe(array $source): array
    {
        try {
            $pdo = self::connect($source);
            $table = (string) dl_setting($source, 'table', '');
            $reg = $pdo->prepare('SELECT to_regclass(:t)::oid');
            $reg->execute(['t' => self::ident($table)]);
            $oid = $reg->fetchColumn();
            if ($oid === false || $oid === null) {
                return dl_probe_result('misconfigured', 'Connected, but there is no table ' . $table . ' (or this user may not see it).', ['connected' => true]);
            }
            $cols = $pdo->prepare('SELECT attname FROM pg_attribute WHERE attrelid = :o AND attnum > 0 AND NOT attisdropped ORDER BY attnum');
            $cols->execute(['o' => $oid]);
            $names = $cols->fetchAll(PDO::FETCH_COLUMN);
            $need = array_filter(array_merge([(string) dl_setting($source, 'watermark', '')], (array) dl_setting($source, 'key', [])));
            $missing = array_values(array_diff($need, $names));
            $version = (string) $pdo->query('SHOW server_version')->fetchColumn();
            $est = $pdo->prepare('SELECT reltuples::bigint FROM pg_class WHERE oid = :o');
            $est->execute(['o' => $oid]);
            $facts = ['connected' => true, 'server_version' => $version, 'columns' => count($names), 'estimated_rows' => max(0, (int) $est->fetchColumn())];
            if ($missing !== []) {
                return dl_probe_result('misconfigured', 'Connected, but the table has no column ' . implode(', ', $missing) . '.', $facts);
            }
            return dl_probe_result('ok', 'Connected (PostgreSQL ' . $version . '): ' . $table . ' has ' . count($names) . ' columns, about ' . number_format($facts['estimated_rows']) . ' rows.', $facts);
        } catch (DlMisconfigured $e) {
            return dl_probe_result('misconfigured', 'Could not connect: ' . $e->getMessage() . '.');
        } catch (Throwable $e) {
            return dl_probe_result('misconfigured', 'The database answered an error: ' . self::sentence($e) . '.');
        }
    }

    /** count(*) of the table — the weekly reconciliation's question. */
    public static function count(array $source): int
    {
        $pdo = self::connect($source);
        return (int) $pdo->query('SELECT count(*) FROM ' . self::ident((string) dl_setting($source, 'table', '')))->fetchColumn();
    }

    public function pull(array $source, callable $emit): array
    {
        try {
            $pdo = self::connect($source);
        } catch (DlMisconfigured $e) {
            $m = $e->getMessage();
            $stats = dl_pull_stats(null, 0, [$m], str_starts_with($m, 'credential:') ? 'blocked' : 'failed', ['state' => (array) ($source['state'] ?? [])]);
            if (str_starts_with($m, 'credential:')) {
                $stats['pause'] = $m;
            }
            return $stats;
        }
        $table = self::ident((string) dl_setting($source, 'table', ''));
        $wmCol = dl_setting($source, 'watermark');
        $keys = array_values((array) dl_setting($source, 'key', []));
        $reload = !empty($source['reload']);
        $state = (array) ($source['state'] ?? []);
        $last = $reload || $wmCol === null ? null : ($state['watermark'] ?? null);
        $replace = $reload || $wmCol === null;
        $where = $wmCol !== null && $last !== null ? ' WHERE "' . $wmCol . '" > ' . $pdo->quote((string) $last) : '';
        $order = array_merge($wmCol !== null ? ['"' . $wmCol . '"'] : [], array_map(static fn (string $k): string => '"' . $k . '"', $keys));
        $sql = 'SELECT * FROM ' . $table . $where . ' ORDER BY ' . implode(', ', $order);
        $seen = 0;
        $rowsTotal = 0;
        $bytes = 0;
        $fetches = 0;
        $errors = [];
        $newMark = $state['watermark'] ?? null;
        try {
            $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $pdo->exec('DECLARE dl_pull NO SCROLL CURSOR FOR ' . $sql);
            while (true) {
                $rows = $pdo->query('FETCH FORWARD ' . self::pageRows() . ' FROM dl_pull')->fetchAll();
                $fetches++;
                if ($rows === []) {
                    break;
                }
                $first = $seen === 0;
                if ($wmCol !== null) {
                    $v = $rows[array_key_last($rows)][$wmCol] ?? null;
                    $newMark = $v === null ? $newMark : (string) $v;
                }
                $file = dl_landing_ndjson(array_map('dl_row_as_text', $rows), [], (string) $source['work_dir'], $replace ? dl_pull_marker((int) $source['pull_id']) : '');
                $bytes += (int) filesize($file);
                $res = $emit(['path' => $file, 'name' => (string) dl_setting($source, 'table', '') . '-p' . ($seen + 1) . '.ndjson', 'format' => 'json',
                              'mode' => $first && $replace ? 'replace' : 'append', 'cursor' => 'p' . ($seen + 1), 'rows' => count($rows)]);
                $seen++;
                $rowsTotal += count($rows);
                if (isset($res['error']) && $res['error'] !== null) {
                    $errors[] = (string) $res['error'];
                    break;
                }
                if (count($rows) < self::pageRows()) {
                    break;
                }
            }
            $pdo->exec('CLOSE dl_pull');
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            $errors[] = 'the table could not be read: ' . self::sentence($e);
        }
        $stats = dl_pull_stats(null, $seen, $errors, $errors === [] ? 'ok' : ($seen > 0 ? 'partial' : 'failed'),
            ['state' => $wmCol !== null && $errors === [] ? ['watermark' => $newMark] : ($errors === [] ? [] : $state), 'rows' => $rowsTotal, 'reload' => $reload]);
        $stats['http_requests'] = $fetches;
        $stats['bytes'] = $bytes;
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
