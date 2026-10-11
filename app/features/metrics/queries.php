<?php
declare(strict_types=1);

/**
 * The metrics' and the products' reads (slice 7, transforms-products-lineage.md "Query functions"; replicates slice 1's catalog and slice 3's saved queries): a metric is an aggregate
 * defined ONCE over a PRODUCT (D13) — an expression, a time column, the grains and dimensions it may be cut by — certified by an engineer, asked of the query service by the `metric`
 * kind (query-service §7), never computed in PHP. EVERY row a person sees comes through a walled view — mcp_metrics (shown to whoever discovers the product), mcp_datasets (products) —
 * and a value through lake_metric(): the service compiles the caller's policy for the product, so a masked dimension arrives masked.
 *
 *   find_metrics($pdo, $filters, $page)            [rows, total] — filters: dataset (an id), certified (yes|no), q, owner
 *   find_metric($pdo, $id)                         one metric with its product, owner and the product's live columns (the dimensions' choices)
 *   metrics_for_pick(…)                            the `metric` picker; products_for_pick(…) the `product` picker (the datasets in the product zone I may discover)
 *   products($pdo, $filters, $page)                the gallery: the product-zone datasets I may discover, certified first, with their metrics' count
 *   metric_definition($m)                          the definition the service is sent: {id, dataset (key), expression, time_column, grains, dimensions, filters}
 *   metric_window($grain, $today, $n)              the last n periods at a grain: [from, to (exclusive), periods[Y-m-d]]
 *   metric_series($pdo, $m, $grain, $by, $where)   the chart's twelve points (uncut): [points[{period, label, value}], answer]
 *   metric_table($pdo, $m, $grain, $by, $where)    the cut table over the same window: the service's answer, presented
 *   format_metric_value($v, $format, $unit)        a value as the metric says it ("1,234.50", "$1,234", "25%")
 */

const METRIC_PAGE = 24;
const PRODUCT_PAGE = 24;
const METRIC_GRAINS = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'quarter' => 'Quarter', 'year' => 'Year'];
const METRIC_FORMATS = ['number' => 'Number', 'integer' => 'Whole number', 'currency' => 'Currency', 'percent' => 'Percent (a fraction: 0.25 is 25%)'];

require_once dirname(__DIR__) . '/catalog/queries.php';          // like_pattern, text_array_literal

function metric_filters_from_request(PDO $pdo): array
{
    $f = [];
    if (($d = request_string('dataset')) !== '') {
        $ds = dataset_by_ref($pdo, $d);
        $f['dataset'] = $ds === null ? -1 : (int) $ds['id'];
    }
    $c = request_string('certified');
    if ($c === 'yes' || $c === 'no') {
        $f['certified'] = $c;
    }
    $q = mb_substr(trim(request_string('q')), 0, 120);
    if ($q !== '') {
        $f['q'] = $q;
    }
    if (ctype_digit(request_string('owner')) && strlen(request_string('owner')) <= 18) {
        $f['owner'] = (int) request_string('owner');
    }
    return $f;
}

const METRIC_SELECT = "m.metric_id, m.name, m.title, m.description, m.dataset_id, m.expression, m.time_column, m.grains, m.dimensions, m.filters, m.format, m.unit, m.owner_member_id,
       m.certified, m.certified_at, m.updated_at, d.key AS dataset_key, d.title AS dataset_title, d.certification AS dataset_certification, d.status AS dataset_status,
       o.display_name AS owner_name";
const METRIC_FROM = "mcp_metrics m JOIN mcp_datasets d ON d.dataset_id = m.dataset_id AND NOT d.is_stub LEFT JOIN mcp_members o ON o.member_id = m.owner_member_id";

function metric_unpack(array $r): array
{
    $r['grains'] = pg_text_array((string) $r['grains']);
    $r['dimensions'] = pg_text_array((string) $r['dimensions']);
    $r['certified'] = (bool) $r['certified'];
    return $r;
}

function find_metrics(PDO $pdo, array $f, int $page): array
{
    $w = ['true'];
    $a = [];
    if (($f['dataset'] ?? null) !== null) {
        $w[] = 'm.dataset_id = :ds';
        $a['ds'] = (int) $f['dataset'];
    }
    if (($f['certified'] ?? null) === 'yes') {
        $w[] = 'm.certified';
    } elseif (($f['certified'] ?? null) === 'no') {
        $w[] = 'NOT m.certified';
    }
    if (($f['q'] ?? '') !== '') {
        $w[] = '(m.name ILIKE :q OR m.title ILIKE :q OR m.description ILIKE :q OR d.key ILIKE :q)';
        $a['q'] = like_pattern((string) $f['q']);
    }
    if (($f['owner'] ?? null) !== null) {
        $w[] = 'm.owner_member_id = :owner';
        $a['owner'] = (int) $f['owner'];
    }
    $where = implode(' AND ', $w);
    $limit = max(1, min(100, (int) ($f['limit'] ?? METRIC_PAGE)));
    $total = (int) one_value($pdo, 'SELECT count(*) FROM ' . METRIC_FROM . " WHERE $where", $a);
    $st = $pdo->prepare('SELECT ' . METRIC_SELECT . ' FROM ' . METRIC_FROM . " WHERE $where ORDER BY (NOT m.certified), m.title, m.metric_id LIMIT $limit OFFSET " . (max(0, $page - 1) * $limit));
    $st->execute($a);
    return ['rows' => array_map('metric_unpack', $st->fetchAll()), 'total' => $total];
}

/** One metric (null when the view does not show it) + the product's live columns {name, logical_type, masked? for me} (the dimensions' and the time column's choices). */
function find_metric(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . METRIC_SELECT . ' FROM ' . METRIC_FROM . ' WHERE m.metric_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r = metric_unpack($r);
    $r['columns'] = metric_columns($pdo, (int) $r['dataset_id']);
    return $r;
}

/** A product's live columns (not the _dl_ provenance): {name, logical_type, personal}. */
function metric_columns(PDO $pdo, int $datasetId): array
{
    $st = $pdo->prepare('SELECT name, logical_type, physical_type, personal_kind FROM mcp_dataset_columns WHERE dataset_id = :d AND removed_snapshot_id IS NULL AND NOT is_system ORDER BY ordinal, name');
    $st->execute(['d' => $datasetId]);
    return array_map(static fn (array $c): array => ['name' => $c['name'], 'logical_type' => $c['logical_type'] ?? $c['physical_type'], 'personal' => $c['personal_kind'] !== null], $st->fetchAll());
}

/** The definition the query service is sent (it reads no application table): the caller read the metric under its own wall. */
function metric_definition(array $m): array
{
    return ['id' => (int) $m['metric_id'], 'dataset' => $m['dataset_key'], 'expression' => $m['expression'], 'time_column' => $m['time_column'],
            'grains' => array_values($m['grains']), 'dimensions' => array_values($m['dimensions']), 'filters' => $m['filters']];
}

/** The `metric` picker: a metric I may see, by title or name; the certified ones badged. */
function metrics_for_pick(PDO $pdo, string $q, array $params, int $page = 1): array
{
    $where = $q === '' ? 'true' : '(m.title ILIKE :q OR m.name ILIKE :q)';
    $args = $q === '' ? [] : ['q' => picker_like($q)];
    return picker_page($pdo, 'SELECT count(*) FROM ' . METRIC_FROM . " WHERE $where",
        "SELECT m.metric_id AS id, m.title AS label, m.name || ' · ' || d.key AS detail, CASE WHEN m.certified THEN 'Certified' END AS badge, CASE WHEN m.certified THEN 'success' END AS color
           FROM " . METRIC_FROM . " WHERE $where ORDER BY (NOT m.certified), m.title, m.metric_id", $args, $page);
}

/** The `product` picker: a dataset of the product zone I may discover (a stub is never offered), certified first. */
function products_for_pick(PDO $pdo, string $q, array $params, int $page = 1): array
{
    $where = "NOT is_stub AND zone = 'product' AND status <> 'retired' AND (:q = '' OR key ILIKE :like OR title ILIKE :like)";
    $args = ['q' => $q, 'like' => like_pattern($q)];
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_datasets WHERE $where",
        "SELECT dataset_id, key, title, certification, status FROM mcp_datasets WHERE $where
          ORDER BY CASE certification WHEN 'certified' THEN 0 WHEN 'promoted' THEN 1 ELSE 2 END, title, dataset_id", $args, $page);
    $r['rows'] = array_map(static fn (array $d): array => ['id' => (int) $d['dataset_id'], 'label' => (string) $d['title'], 'detail' => (string) $d['key'],
        'badge' => $d['certification'] === 'certified' ? 'Certified' : null, 'color' => 'success'], $r['rows']);
    return $r;
}

/**
 * The product gallery: the datasets of the product zone the caller may discover — certified first, then promoted, then by usage — with their owner, freshness and how many metrics
 * (the ones the caller may see) are defined over them. Filters: q, certified (yes). Returns ['rows', 'total'].
 */
function products(PDO $pdo, array $f, int $page): array
{
    $w = ["NOT d.is_stub", "d.zone = 'product'", "d.status <> 'retired'"];
    $a = [];
    if (($f['q'] ?? '') !== '') {
        $w[] = '(d.key ILIKE :q OR d.title ILIKE :q OR d.description ILIKE :q)';
        $a['q'] = like_pattern((string) $f['q']);
    }
    if (($f['certified'] ?? '') === 'yes') {
        $w[] = "d.certification = 'certified'";
    }
    $where = implode(' AND ', $w);
    $limit = max(1, min(100, (int) ($f['limit'] ?? PRODUCT_PAGE)));
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_datasets d WHERE $where", $a);
    $st = $pdo->prepare("SELECT d.dataset_id, d.key, d.title, d.description, d.certification, d.status, d.owner_member_id, d.row_count, d.data_updated_at, d.usage_30d, o.display_name AS owner_name,
                                (SELECT count(*) FROM mcp_metrics m WHERE m.dataset_id = d.dataset_id) AS metric_count,
                                (SELECT count(*) FROM mcp_metrics m WHERE m.dataset_id = d.dataset_id AND m.certified) AS certified_metric_count,
                                COALESCE(dl_dataset_health(d.dataset_id) ->> 'health', 'green') AS health
                           FROM mcp_datasets d LEFT JOIN mcp_members o ON o.member_id = d.owner_member_id WHERE $where
                          ORDER BY CASE d.certification WHEN 'certified' THEN 0 WHEN 'promoted' THEN 1 ELSE 2 END, (d.status = 'deprecated'), d.usage_30d DESC, d.title, d.dataset_id
                          LIMIT $limit OFFSET " . (max(0, $page - 1) * $limit));
    $st->execute($a);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}

/** The certified products for the home's tile (the same read, six of them). */
function certified_products(PDO $pdo, int $limit = 6): array
{
    return products($pdo, ['certified' => 'yes', 'limit' => $limit], 1)['rows'];
}

// ---- values: the query service's, never PHP's --------------------------------------------------------------------------------------------------------------------

/** The last $n periods at a grain, ending with the one that holds $today: [from, to (exclusive), periods[Y-m-d starts]]. */
function metric_window(string $grain, DateTimeImmutable $today, int $n = 12): array
{
    $today = $today->setTime(0, 0, 0);
    $start = match ($grain) {
        'day'     => $today,
        'week'    => $today->modify('monday this week'),
        'quarter' => $today->setDate((int) $today->format('Y'), intdiv((int) $today->format('n') - 1, 3) * 3 + 1, 1),
        'year'    => $today->setDate((int) $today->format('Y'), 1, 1),
        default   => $today->setDate((int) $today->format('Y'), (int) $today->format('n'), 1),
    };
    $back = static fn (int $i): string => match ($grain) { 'day' => "-$i days", 'week' => "-$i weeks", 'quarter' => '-' . ($i * 3) . ' months', 'year' => "-$i years", default => "-$i months" };
    $next = match ($grain) { 'day' => '+1 day', 'week' => '+1 week', 'quarter' => '+3 months', 'year' => '+1 year', default => '+1 month' };
    $periods = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $periods[] = $start->modify($back($i))->format('Y-m-d');
    }
    return ['from' => $periods[0], 'to' => $start->modify($next)->format('Y-m-d'), 'periods' => $periods];
}

/** A period's label for an axis: "Oct", "Q4 '26", "2026", "Oct 5". */
function metric_period_label(string $period, string $grain): string
{
    $d = new DateTimeImmutable($period);
    return match ($grain) {
        'day', 'week' => $d->format('M j'),
        'quarter'     => 'Q' . (intdiv((int) $d->format('n') - 1, 3) + 1) . " '" . $d->format('y'),
        'year'        => $d->format('Y'),
        default       => $d->format('M') . ($d->format('n') === '1' ? " '" . $d->format('y') : ''),
    };
}

/** The metric's grain for the page: the one asked for if the metric allows it, else its first, else month. */
function metric_pick_grain(array $m, string $asked): string
{
    if ($asked !== '' && in_array($asked, $m['grains'], true)) {
        return $asked;
    }
    return $m['grains'][0] ?? 'month';
}

/** A service answer as the page's: {status, message, rule, columns[], rows[][], truncated, masked_columns[], query_run_id, ms}. */
function metric_answer(array $a): array
{
    $ok = ($a['status'] ?? 'error') === 'ok';
    return ['status' => (string) ($a['status'] ?? 'error'), 'ok' => $ok, 'rule' => $a['refusal_rule'] ?? null, 'field' => $a['field'] ?? null,
            'message' => $ok ? null : lake_refusal_message($a), 'request_access' => $ok ? null : lake_request_access($a),
            'columns' => array_map(static fn ($c) => is_array($c) ? (string) $c['name'] : (string) $c, (array) ($a['columns'] ?? [])),
            'rows' => (array) ($a['rows'] ?? []), 'truncated' => (bool) ($a['truncated'] ?? false),
            'masked_columns' => array_values(array_filter((array) ($a['masked_columns'] ?? []), 'is_string')),
            'query_run_id' => isset($a['query_run_id']) ? (int) $a['query_run_id'] : null, 'ms' => (int) ($a['ms'] ?? 0), 'datasets' => (array) ($a['datasets'] ?? [])];
}

/** One `metric` request as the caller (logged `metric.value` with the run's id — never the expression's text or a value). */
function metric_ask(PDO $pdo, array $m, ?string $grain, ?string $from, ?string $to, array $by, array $where, array $opts = []): array
{
    $a = metric_answer(lake_metric(metric_definition($m), $grain, $from, $to, $by, $where, $opts));
    if ($a['query_run_id'] !== null) {
        log_activity($pdo, 'metric.value', 'metric', (int) $m['metric_id'], ['dataset_id' => (int) $m['dataset_id'], 'query_run_id' => $a['query_run_id'],
            'after' => ['metric_id' => (int) $m['metric_id'], 'grain' => $grain, 'by' => count($by), 'where' => count($where), 'rows' => count($a['rows']), 'status' => $a['status'],
                        'truncated' => $a['truncated'], 'masked_columns' => count($a['masked_columns']), 'dry_run' => !empty($opts['dry_run'])]]);
    }
    return $a;
}

/** The chart: twelve points at the grain, uncut — a period with no row is a gap (null), not a zero. Returns [points[{period, label, value}], answer, window]. */
function metric_series(PDO $pdo, array $m, string $grain, array $where = [], ?DateTimeImmutable $today = null): array
{
    $tz = new DateTimeZone((string) (one_value($pdo, 'SELECT timezone FROM dl_settings WHERE id = 1') ?: 'UTC'));
    $win = metric_window($grain, $today ?? new DateTimeImmutable('now', $tz));
    $a = metric_ask($pdo, $m, $grain, $win['from'], $win['to'], [], $where);
    $byPeriod = [];
    if ($a['ok'] && $m['time_column'] !== null) {
        foreach ($a['rows'] as $row) {
            $byPeriod[substr((string) $row[0], 0, 10)] = $row[1] === null ? null : (float) $row[1];
        }
    } elseif ($a['ok'] && $a['rows'] !== []) {
        $byPeriod['all'] = $a['rows'][0][0] === null ? null : (float) $a['rows'][0][0];
    }
    $points = [];
    foreach ($m['time_column'] === null ? ['all'] : $win['periods'] as $p) {
        $points[] = ['period' => $p, 'label' => $p === 'all' ? 'All' : metric_period_label($p, $grain), 'value' => $byPeriod[$p] ?? null];
    }
    return [$points, $a, $win];
}

/** The cut table over the same window: the answer as the service gave it (period first, the cut dimensions, value). */
function metric_table(PDO $pdo, array $m, string $grain, array $by, array $where = [], ?array $win = null): array
{
    if ($win === null) {
        $tz = new DateTimeZone((string) (one_value($pdo, 'SELECT timezone FROM dl_settings WHERE id = 1') ?: 'UTC'));
        $win = metric_window($grain, new DateTimeImmutable('now', $tz));
    }
    return metric_ask($pdo, $m, $grain, $win['from'], $win['to'], $by, $where);
}

/** A value as the metric says it: number "1,234.57", integer "1,235", currency "$1,234.57" (the unit when it is a symbol or code), percent "25%" (a fraction × 100). */
function format_metric_value(float|int|string|null $v, string $format, ?string $unit = null): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    $n = (float) $v;
    $u = $unit !== null && $unit !== '' ? $unit : null;
    return match ($format) {
        'integer'  => number_format($n, 0) . ($u !== null ? ' ' . $u : ''),
        'currency' => ($u !== null && mb_strlen($u) === 1 ? $u : '$') . number_format($n, abs($n) >= 1000 ? 0 : 2) . ($u !== null && mb_strlen($u) > 1 ? ' ' . $u : ''),
        'percent'  => rtrim(rtrim(number_format($n * 100, 1), '0'), '.') . '%',
        default    => number_format($n, abs($n) >= 1000 ? 0 : 2) . ($u !== null ? ' ' . $u : ''),
    };
}
