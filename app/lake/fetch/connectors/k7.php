<?php
declare(strict_types=1);

/**
 * The `k7` connector (sources-pulls.md): a SHARE of a sibling application, read through the kernel (`POST /api/v1/apps/read.php` — K7;
 * kernel_read() in app/api/kernel.php) over a connection a super-admin approved. The lake holds no key to the sibling and no connection to
 * its database; the kernel calls the provider's tool and answers at most 256 kB. ONE source reads ONE share into ONE raw dataset.
 *
 * A page lands as NDJSON of its rows (the `rows_path` list, one object a line, byte-exact from the decoded JSON), each row given the
 * `inject` fields; the document's other top-level keys (counts, totals, generated_at) go to the pull's policy.document. Pages follow
 * `paging` (offset: the arguments offset and limit, until a page shorter than page_size) and `window` (date: the arguments from and to — the
 * first pull reaches 92 days back, every later one starts the day after the last pull's `to`, kept in policy.state.window_to).
 * `no_connection` (or not_shared) is not a failure: the source PAUSES with that reason and the pull is `blocked` — resuming retries.
 *
 * settings: provider, tool, arguments (an object; the words <yesterday> and <today> are replaced by the date at the pull), rows_path (a dotted
 *           path to the list in the document), paging (none|offset), page_size (1–500), window (none|date),
 *           inject ({field: "document" | "document:<key>" | "pull_date" | "literal:<text>"}).
 */
final class DlConnectorK7 implements DlConnector
{
    /** Pages one pull may read (a runaway share must not hold the worker's pass). */
    public const MAX_PAGES = 400;
    /** The days a first windowed pull reaches back: the shares refuse more than 92 days at once (Inventory's sales_closed), and 92 days inclusive is exactly that. */
    public const FIRST_WINDOW_DAYS = 92;
    private const RESERVED_ARGUMENTS = ['consumer', 'consumer_agent_id', 'as_agent', 'scope_id'];

    public function capabilities(): array
    {
        return dl_capabilities(['label' => 'A sibling application\'s share', 'icon' => 'feather-share-2', 'needs_credential' => false,
            'description' => 'A read another Business OS application shares — through the kernel, over a connection a super-admin approved. Pages land as rows.']);
    }

    public function validate(array $in, array $current = []): array
    {
        $errors = [];
        $s = [];
        foreach (['provider' => 'The provider', 'tool' => 'The tool'] as $k => $label) {
            $v = strtolower((string) (dl_str($in[$k] ?? ($current[$k] ?? null), 64) ?? ''));
            if (!preg_match('~^[a-z][a-z0-9_]{0,62}$~', $v)) {
                $errors[$k] = $label . ' is the catalog key of the application (gl, inventory, sales_crm) / the name of its shared tool (account_balances).';
            }
            $s[$k] = $v;
        }
        $args = $this->objectOf($in['arguments'] ?? ($current['arguments'] ?? []), 'arguments', $errors);
        foreach (self::RESERVED_ARGUMENTS as $r) {
            if (array_key_exists($r, $args)) {
                $errors['arguments'] = 'The kernel sets "' . $r . '" itself — leave it out.';
            }
        }
        if (strlen((string) json_encode($args)) > 2000) {
            $errors['arguments'] = 'The arguments are up to 2,000 characters.';
        }
        $s['arguments'] = (object) $args;
        $path = (string) (dl_str($in['rows_path'] ?? ($current['rows_path'] ?? null), 200) ?? '');
        if (!preg_match('~^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$~', $path)) {
            $errors['rows_path'] = 'Name the list in the answer that holds the rows, such as accounts (a dotted path reaches deeper).';
        }
        $s['rows_path'] = $path;
        $paging = strtolower((string) ($in['paging'] ?? ($current['paging'] ?? 'none')));
        if (!in_array($paging, ['none', 'offset'], true)) {
            $errors['paging'] = 'Paging is none or offset.';
            $paging = 'none';
        }
        $s['paging'] = $paging;
        $size = $in['page_size'] ?? ($current['page_size'] ?? null);
        if ($paging === 'offset') {
            $n = $size === null || $size === '' ? 200 : (ctype_digit((string) $size) ? (int) $size : 0);
            if ($n < 1 || $n > 500) {
                $errors['page_size'] = 'A page is from 1 to 500 rows.';
                $n = 200;
            }
            $s['page_size'] = $n;
        }
        $window = strtolower((string) ($in['window'] ?? ($current['window'] ?? 'none')));
        if (!in_array($window, ['none', 'date'], true)) {
            $errors['window'] = 'The window is none or date.';
            $window = 'none';
        }
        $s['window'] = $window;
        $inject = $this->objectOf($in['inject'] ?? ($current['inject'] ?? []), 'inject', $errors);
        foreach ($inject as $name => $spec) {
            if (!preg_match('~^[a-z][a-z0-9_]{0,62}$~', (string) $name)) {
                $errors['inject'] = 'An injected field is named in lower case, letters digits and underscores (as_of).';
            } elseif (!is_string($spec) || !preg_match('~^(document|document:[A-Za-z_][A-Za-z0-9_]*|pull_date|literal:.{0,100})$~', $spec)) {
                $errors['inject'] = 'Say where "' . $name . '" comes from: document, document:<key>, pull_date or literal:<text>.';
            }
        }
        $s['inject'] = (object) $inject;
        return [$s, $errors];
    }

    /** An object from a JSON string or an array; a sentence on the field when it is neither. */
    private function objectOf(mixed $v, string $field, array &$errors): array
    {
        if (is_string($v)) {
            $t = trim($v);
            if ($t === '') {
                return [];
            }
            $v = json_decode($t, true);
            if (!is_array($v)) {
                $errors[$field] = 'This is a JSON object, such as {"as_of": "<yesterday>"}.';
                return [];
            }
        }
        if (is_object($v)) {
            $v = (array) $v;
        }
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            $errors[$field] = 'This is a JSON object, such as {"as_of": "<yesterday>"}.';
            return [];
        }
        return $v;
    }

    /** The business's today at midnight. */
    private function today(array $source): DateTimeImmutable
    {
        $tz = new DateTimeZone((string) ($source['tz'] ?? 'UTC'));
        return ($source['now'] ?? new DateTimeImmutable('now'))->setTimezone($tz)->setTime(0, 0);
    }

    /** <yesterday> and <today> replaced, to any depth. */
    private function resolve(mixed $v, DateTimeImmutable $today): mixed
    {
        if (is_string($v)) {
            return match ($v) { '<yesterday>' => $today->modify('-1 day')->format('Y-m-d'), '<today>' => $today->format('Y-m-d'), default => $v };
        }
        if (is_array($v)) {
            return array_map(fn ($x) => $this->resolve($x, $today), $v);
        }
        return $v;
    }

    /** The reader: kernel_read() unless a test put its own in the source. */
    private function read(array $source, array $arguments): array
    {
        $reader = $source['kernel_read'] ?? 'kernel_read';
        return $reader($source['pdo'], (string) dl_setting($source, 'provider'), (string) dl_setting($source, 'tool'), $arguments);
    }

    /** The list at a dotted path of the document (null when it is not there or not a list). */
    private function rowsAt(mixed $doc, string $path): ?array
    {
        foreach (explode('.', $path) as $seg) {
            if (!is_array($doc) || !array_key_exists($seg, $doc)) {
                return null;
            }
            $doc = $doc[$seg];
        }
        return is_array($doc) && ($doc === [] || array_is_list($doc)) ? array_values($doc) : null;
    }

    /** The share's refusal or failure as [status, sentence, pause?] for a kernel answer that is not 200. */
    private function refusal(array $r): array
    {
        $code = (string) ($r['error'] ?? 'unreachable');
        return match (true) {
            in_array($code, ['no_connection', 'not_shared', 'not_at_location'], true) => ['blocked', $code === 'no_connection'
                ? 'the connection to this application is not approved yet (no_connection) — a super-admin approves it in the kernel'
                : 'the application does not share this tool (' . $code . ')', $code],
            $code === 'unreachable' => ['failed', 'the kernel did not answer', null],
            $code === 'provider_failed' || $r['status'] >= 500 => ['failed', 'the application failed to answer (' . $code . ')', null],
            default => ['failed', 'the kernel refused the read (' . $code . ')', null],
        };
    }

    public function probe(array $source): array
    {
        $args = (array) dl_setting($source, 'arguments', []);
        $today = $this->today($source);
        $args = (array) $this->resolve($args, $today);
        if (dl_setting($source, 'window', 'none') === 'date') {
            $to = $today->modify('-1 day');
            $args['from'] = $to->modify('-6 days')->format('Y-m-d');
            $args['to'] = $to->format('Y-m-d');
        }
        if (dl_setting($source, 'paging', 'none') === 'offset') {
            $args['offset'] = 0;
            $args['limit'] = 1;
        }
        $r = $this->read($source, $args);
        $name = dl_setting($source, 'provider') . ' · ' . dl_setting($source, 'tool');
        if ($r['status'] !== 200) {
            [$st, $why] = $this->refusal($r);
            return dl_probe_result($st === 'blocked' ? 'blocked' : 'misconfigured', ucfirst($why) . '.', ['share' => $name, 'code' => $r['error']]);
        }
        $rows = $this->rowsAt($r['result'], (string) dl_setting($source, 'rows_path', ''));
        if ($rows === null) {
            return dl_probe_result('misconfigured', 'The share answers, but there is no list at "' . dl_setting($source, 'rows_path', '') . '" in its answer.', ['share' => $name, 'keys' => is_array($r['result']) ? array_slice(array_keys($r['result']), 0, 12) : []]);
        }
        return dl_probe_result('ok', 'The share answers (' . count($rows) . ' row' . (count($rows) === 1 ? '' : 's') . ' in the first look).', ['share' => $name, 'rows' => count($rows)]);
    }

    public function pull(array $source, callable $emit): array
    {
        $today = $this->today($source);
        $state = (array) ($source['state'] ?? []);
        $args = (array) $this->resolve((array) dl_setting($source, 'arguments', []), $today);
        $stateOut = [];
        $window = dl_setting($source, 'window', 'none') === 'date';
        if ($window) {
            $to = $today->modify('-1 day');
            $from = isset($state['window_to']) && preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) $state['window_to'])
                ? (new DateTimeImmutable((string) $state['window_to'], $today->getTimezone()))->modify('+1 day')
                : $today->modify('-' . self::FIRST_WINDOW_DAYS . ' days');
            if ($from > $to) {
                return dl_pull_stats(null, 0, [], 'ok', ['state' => ['window_to' => (string) $state['window_to']], 'note' => 'up to date through ' . $to->format('Y-m-d')]);
            }
            $args['from'] = $from->format('Y-m-d');
            $args['to'] = $to->format('Y-m-d');
            $stateOut['window_to'] = $to->format('Y-m-d');
        }
        $paged = dl_setting($source, 'paging', 'none') === 'offset';
        $size = (int) dl_setting($source, 'page_size', 200);
        $path = (string) dl_setting($source, 'rows_path', '');
        $inject = (array) dl_setting($source, 'inject', []);
        $offset = 0;
        $seen = 0;
        $requests = 0;
        $doc1 = null;
        $name = dl_setting($source, 'provider') . '-' . dl_setting($source, 'tool');
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $a = $args + ($paged ? ['offset' => $offset, 'limit' => $size] : []);
            $r = $this->read($source, $a);
            $requests++;
            if ($r['status'] !== 200) {
                [$st, $why, $pause] = $this->refusal($r);
                $extra = ['state' => $state, 'http_requests' => $requests];
                if ($pause !== null) {
                    $extra['pause'] = $pause;
                }
                $stats = dl_pull_stats(null, $seen, [$why], $seen > 0 && $st !== 'blocked' ? 'partial' : $st, $extra);
                $stats['http_requests'] = $requests;
                return $stats;
            }
            $doc = is_array($r['result']) ? $r['result'] : [];
            $rows = $this->rowsAt($doc, $path);
            if ($rows === null) {
                $stats = dl_pull_stats(null, $seen, ['the share answered without a list at "' . $path . '"'], 'failed', ['state' => $state]);
                $stats['http_requests'] = $requests;
                return $stats;
            }
            if ($doc1 === null) {
                $doc1 = $doc;
                unset($doc1[explode('.', $path)[0]]);
            }
            if ($rows === []) {
                break;
            }
            $add = [];
            foreach ($inject as $field => $spec) {
                $spec = (string) $spec;
                if ($spec === 'pull_date') {
                    $add[$field] = $today->format('Y-m-d');
                } elseif (str_starts_with($spec, 'literal:')) {
                    $add[$field] = substr($spec, 8);
                } else {
                    $key = $spec === 'document' ? (string) $field : substr($spec, 9);
                    if (!array_key_exists($key, $doc)) {
                        $stats = dl_pull_stats(null, $seen, ['the share\'s answer has no "' . $key . '" to put in "' . $field . '"'], 'failed', ['state' => $state]);
                        $stats['http_requests'] = $requests;
                        return $stats;
                    }
                    $add[$field] = $doc[$key];
                }
            }
            $file = dl_landing_ndjson($rows, $add, (string) $source['work_dir']);
            $res = $emit(['path' => $file, 'name' => $name . '-' . ($paged ? 'offset-' . $offset : 'page') . '.ndjson', 'format' => 'json', 'mode' => 'append',
                          'cursor' => $paged ? 'offset:' . $offset : ($window ? $args['from'] . '..' . $args['to'] : null), 'rows' => count($rows)]);
            $seen++;
            if (isset($res['error']) && $res['error'] !== null) {
                $stats = dl_pull_stats(null, $seen, [(string) $res['error']], $seen > 1 ? 'partial' : 'failed', ['state' => $state]);
                $stats['http_requests'] = $requests;
                return $stats;
            }
            $offset += count($rows);
            if (!$paged || count($rows) < $size) {
                break;
            }
        }
        $stats = dl_pull_stats(null, $seen, [], 'ok', ['state' => $stateOut, 'document' => $doc1 ?? [], 'rows' => $offset]);
        $stats['http_requests'] = $requests;
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
