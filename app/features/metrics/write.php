<?php
declare(strict_types=1);

/**
 * Metrics' writes (slice 7, transforms-products-lineage.md; replicates slice 1's CRUD and slice 5's certification): save (create, or change — the certification drops BY ITSELF when the
 * definition changes: db/011's dl_metrics_before), certify / uncertify, retire.
 *   metric_save($pdo, $id, $f, $by) → [id, created, dropped]   the definition is validated through the query service first: a `metric` request with dry_run — the grain, the dimensions,
 *       one aggregate expression, a predicate with none, the columns bound against the product — as the saver, so someone who cannot read the product cannot define a metric over it;
 *       the dimensions and the time column must be columns the product has now.
 *   metric_certify($pdo, $id, $on, $by)   certify: the metric must run for the certifier now; uncertify clears the badge
 *   metric_retire($pdo, $id, $by)         the metric leaves every list, the picker and the search; its history stays
 */
require_once dirname(__DIR__) . '/uploads/write.php';          // FieldRefused (slice 2's)
require_once dirname(__DIR__) . '/editor/queries.php';         // editor_request_id
require_once __DIR__ . '/queries.php';

function metric_save(PDO $pdo, ?int $id, array $f, int $by): array
{
    $cur = $id === null ? null : one_row($pdo, 'SELECT id, name, dataset_id, certified FROM metrics WHERE id = :id AND retired_at IS NULL FOR UPDATE', ['id' => $id]);
    if ($id !== null && $cur === null) {
        throw new DomainException('Not found.');
    }
    $ds = dataset_by_ref($pdo, (string) $f['dataset']);
    if ($ds === null || $ds['status'] === 'retired' || dl_level_rank(dataset_level($pdo, (int) $ds['id'])) < DL_LEVELS['discover']) {
        throw new FieldRefused(['dataset' => 'That product is not here.']);
    }
    if ($ds['zone'] !== 'product') {
        throw new FieldRefused(['dataset' => 'A metric is defined over a product dataset — ' . $ds['key'] . ' is ' . $ds['zone'] . '. Make a product with a transform first.']);
    }
    if (dl_level_rank(dataset_level($pdo, (int) $ds['id'])) < DL_LEVELS['read']) {
        throw new FieldRefused(['dataset' => 'You may not read ' . $ds['key'] . ' — request access to define a metric over it.']);
    }
    if (one_value($pdo, 'SELECT id FROM metrics WHERE name = :n AND id IS DISTINCT FROM :self', ['n' => $f['name'], 'self' => $id]) !== null) {
        throw new FieldRefused(['name' => 'There is a metric called ' . $f['name'] . ' already — choose another name.']);
    }
    $cols = array_column(metric_columns($pdo, (int) $ds['id']), 'name');
    if ($cols === []) {
        throw new FieldRefused(['dataset' => $ds['key'] . ' has no data yet — run the transform that makes it first, then define the metric.']);
    }
    $lower = array_map('strtolower', $cols);
    $has = static fn (string $c): bool => in_array(strtolower($c), $lower, true);
    if ($f['time_column'] !== null && !$has($f['time_column'])) {
        throw new FieldRefused(['time_column' => $ds['key'] . ' has no column ' . $f['time_column'] . '.']);
    }
    foreach ($f['dimensions'] as $dim) {
        if (!$has($dim)) {
            throw new FieldRefused(['dimensions' => $ds['key'] . ' has no column ' . $dim . '.']);
        }
        if ($dim === $f['time_column']) {
            throw new FieldRefused(['dimensions' => 'The time column is the metric\'s period — it is not also a dimension.']);
        }
    }
    // the definition through the one door, as the saver: a dry run binds the SELECT (the expression's columns, the filter's) and runs nothing
    $def = ['id' => $id, 'dataset' => $ds['key'], 'expression' => $f['expression'], 'time_column' => $f['time_column'], 'grains' => $f['grains'], 'dimensions' => $f['dimensions'], 'filters' => $f['filters']];
    $a = metric_answer(lake_metric($def, $f['grains'][0] ?? null, null, null, array_slice($f['dimensions'], 0, 1), [], ['dry_run' => true, 'request_id' => editor_request_id()]));
    if (!$a['ok']) {
        $field = $a['rule'] === 'invalid_metric' && in_array($a['field'], ['expression', 'filters', 'time_column', 'dimensions', 'grain'], true) ? ($a['field'] === 'grain' ? 'grains' : $a['field'])
            : (in_array($a['rule'], ['engine', 'memory'], true) ? 'expression' : ($a['rule'] === 'invalid_metric' ? 'expression' : 'dataset'));
        $msg = (string) $a['message'];
        if (in_array($a['rule'], ['engine', 'memory'], true)) {
            $msg = 'The expression or the filter does not fit ' . $ds['key'] . ' (' . mb_substr(preg_replace('/\s+/', ' ', $msg) ?? $msg, 0, 160) . ').';
        }
        throw new FieldRefused([$field => ucfirst($msg)]);
    }
    $vals = ['n' => $f['name'], 't' => $f['title'], 'd' => $f['description'], 'ds' => (int) $ds['id'], 'e' => $f['expression'], 'tc' => $f['time_column'],
             'g' => pg_array_literal($f['grains']), 'dm' => pg_array_literal($f['dimensions']), 'f' => $f['filters'], 'fm' => $f['format'], 'u' => $f['unit']];
    if ($cur === null) {
        $st = $pdo->prepare('INSERT INTO metrics (name, title, description, dataset_id, expression, time_column, grains, dimensions, filters, format, unit, owner_member_id, created_by)
                             VALUES (:n, :t, :d, :ds, :e, :tc, CAST(:g AS text[]), CAST(:dm AS text[]), :f, :fm, :u, :by, :by2) RETURNING id');
        $st->execute($vals + ['by' => $by, 'by2' => $by]);
        return ['id' => (int) $st->fetchColumn(), 'created' => true, 'dropped' => false, 'dataset' => $ds['key']];
    }
    $st = $pdo->prepare('UPDATE metrics SET name = :n, title = :t, description = :d, dataset_id = :ds, expression = :e, time_column = :tc, grains = CAST(:g AS text[]), dimensions = CAST(:dm AS text[]),
                                filters = :f, format = :fm, unit = :u WHERE id = :id');
    $st->execute($vals + ['id' => (int) $cur['id']]);
    $still = (bool) one_value($pdo, 'SELECT certified FROM metrics WHERE id = :id', ['id' => (int) $cur['id']]);
    return ['id' => (int) $cur['id'], 'created' => false, 'dropped' => (bool) $cur['certified'] && !$still, 'dataset' => $ds['key']];
}

function metric_certify(PDO $pdo, int $id, bool $on, int $by): void
{
    if ($on) {
        $m = find_metric($pdo, $id);
        if ($m === null) {
            throw new DomainException('Not found.');
        }
        $a = metric_ask($pdo, $m, $m['time_column'] === null ? null : metric_pick_grain($m, ''), null, null, [], [], ['dry_run' => true, 'request_id' => editor_request_id()]);
        if (!$a['ok']) {
            throw new DomainException('It does not run for you yet, so it cannot be certified: ' . lcfirst((string) $a['message']));
        }
        $st = $pdo->prepare('UPDATE metrics SET certified = true, certified_by = :by, certified_at = now() WHERE id = :id AND retired_at IS NULL');
        $st->execute(['by' => $by, 'id' => $id]);
    } else {
        $st = $pdo->prepare('UPDATE metrics SET certified = false, certified_by = NULL, certified_at = NULL WHERE id = :id AND retired_at IS NULL');
        $st->execute(['id' => $id]);
    }
    if ($st->rowCount() === 0) {
        throw new DomainException('Not found.');
    }
}

function metric_retire(PDO $pdo, int $id, int $by): void
{
    $st = $pdo->prepare('UPDATE metrics SET retired_at = now(), certified = false, certified_by = NULL, certified_at = NULL WHERE id = :id AND retired_at IS NULL');
    $st->execute(['id' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Not found.');
    }
}
