<?php
declare(strict_types=1);
/**
 * Action `metric_save` (no `metric`: log metric.save — creates; with `metric`: changes it) — metrics.manage: **name** (lower case, digits, underscores), **title**, **dataset** (a product: an id or a key),
 * **expression** (ONE aggregate over the product's columns: sum(amount), count(DISTINCT customer_id)), time_column, grains[] (day, week, month, quarter, year — the form's checkboxes or an
 * agent's comma list), dimensions[] (columns of the product), filters (a fixed condition on each row), format (number | integer | currency | percent), unit, description. A field left out stays as it was.
 * The definition is validated through the query service as the saver (a `metric` request, dry run); changing the expression, the filter, the time column, the dimensions or the product removes
 * the Certified badge BY ITSELF (db/011's trigger). Logged with the names and the counts — never the expression's text.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/metrics/write.php';
require_once dirname(__DIR__, 2) . '/app/features/metrics/present.php';
dl_handler_begin();
require_right('metrics.manage');
$pdo = db();
$me = (int) current_member_id();
$id = req_has('metric') && (string) req_val('metric') !== '' ? (int) req_val('metric') : null;
$cur = null;
if ($id !== null) {
    $cur = find_metric($pdo, $id) ?? respond_not_found('No such metric.');
}
$errors = [];
$name = req_has('name') ? strtolower((string) req_val('name')) : (string) ($cur['name'] ?? '');
if ($name === '') {
    $errors['name'] = 'The name is required.';
} elseif (!preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name)) {
    $errors['name'] = 'The name is lower-case letters, digits and underscores, starting with a letter (at most 63).';
}
$title = dl_text('title', $cur['title'] ?? null, 200, 'The title', $errors, true);
if ($title === null && !isset($errors['title'])) {
    $errors['title'] = 'The title is required.';
}
$dataset = req_has('dataset') ? (string) req_val('dataset') : ($cur === null ? '' : (string) $cur['dataset_id']);
if ($dataset === '') {
    $errors['dataset'] = 'Choose the product the metric is over.';
}
$expression = dl_text('expression', $cur['expression'] ?? null, 1000, 'The expression', $errors, true);
if ($expression === null && !isset($errors['expression'])) {
    $errors['expression'] = 'The expression is required — one aggregate such as sum(amount).';
}
$timeColumn = req_has('time_column') ? ((string) req_val('time_column') === '' ? null : (string) req_val('time_column')) : ($cur['time_column'] ?? null);
if ($timeColumn !== null && !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $timeColumn)) {
    $errors['time_column'] = 'The time column is a column\'s name.';
}
$grains = request_list('grains') ?? ($cur['grains'] ?? ['month']);
if ($grains === []) {
    $grains = ['month'];
}
foreach ($grains as $g) {
    if (!isset(METRIC_GRAINS[$g])) {
        $errors['grains'] = 'The grains are day, week, month, quarter or year.';
    }
}
$dimensions = request_list('dimensions') ?? ($cur['dimensions'] ?? []);
foreach ($dimensions as $dm) {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $dm)) {
        $errors['dimensions'] = 'A dimension is a column\'s name.';
    }
}
if (count($dimensions) > 20) {
    $errors['dimensions'] = 'A metric has at most 20 dimensions.';
}
$f = ['name' => $name, 'title' => $title, 'dataset' => $dataset, 'expression' => $expression, 'time_column' => $timeColumn, 'grains' => array_values($grains), 'dimensions' => array_values($dimensions),
      'filters' => dl_text('filters', $cur['filters'] ?? null, 1000, 'The filter', $errors), 'format' => dl_choice('format', $cur['format'] ?? 'number', array_keys(METRIC_FORMATS), 'The format', $errors, false) ?? 'number',
      'unit' => dl_text('unit', $cur['unit'] ?? null, 40, 'The unit', $errors), 'description' => dl_text('description', $cur['description'] ?? null, 4000, 'The description', $errors)];
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$out = dl_guard($pdo, static function () use ($pdo, $id, $f, $me, $cur): array {
    $pdo->beginTransaction();
    try {
        $out = metric_save($pdo, $id, $f, $me);
    } catch (FieldRefused $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        dl_refuse_fields($e->fields);
    }
    $dsId = (int) one_value($pdo, 'SELECT id FROM datasets WHERE key = :k', ['k' => $out['dataset']]);
    log_activity($pdo, 'metric.save', 'metric', $out['id'], ['dataset_id' => $dsId, 'after' => ['metric_id' => $out['id'], 'dataset' => $out['dataset'], 'grains' => $f['grains'], 'dimensions' => $f['dimensions'],
        'time_column' => $f['time_column'], 'format' => $f['format'], 'certification_dropped' => $out['dropped']], 'before' => $cur === null ? null : ['dataset' => $cur['dataset_key'], 'certified' => (bool) $cur['certified']]]);
    $pdo->commit();
    return $out;
});
dl_done('Saved ' . $f['title'], $out['id'], dl_land('/metrics/' . $out['id'], $out['created'] ? 'created' : ($out['dropped'] ? 'dropped' : 'saved')), 'metricChanged',
    ['certification_dropped' => $out['dropped']]);
