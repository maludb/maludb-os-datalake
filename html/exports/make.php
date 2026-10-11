<?php
declare(strict_types=1);
/**
 * Action `export_make` (log export.make — export.own and read on every dataset the SQL reads; an agent's pauses, `external_send`): **format**
 * (csv, xlsx or parquet) and one of sql (one SELECT), saved_query (one I may see; p[name] its parameters), dataset (a whole dataset I may read);
 * as_of. The SQL dry-run first; the policy frozen now; the writer makes the file through my views (masked as I see it), capped at the download
 * limit (XLSX also at Excel's sheet) and says so; I am told when it is ready. Lands on /exports.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/editor/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/saved_queries/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/write.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
dl_handler_begin();
require_right('export.own');
$pdo = db();
$me = (int) current_member_id();
$format = strtolower((string) (req_val('format') ?? ''));
$asOf = trim((string) (req_val('as_of') ?? ''));
$asOf = $asOf === '' ? null : (ctype_digit($asOf) ? (int) $asOf : $asOf);
$errors = [];
$what = null;
if ((string) (req_val('dataset') ?? '') !== '') {
    $what = ['dataset' => require_dataset($pdo, (string) req_val('dataset'), 'read')];
} elseif ((string) (req_val('saved_query') ?? '') !== '') {
    $q = find_saved_query($pdo, (int) req_val('saved_query'));
    if ($q === null) {
        respond_not_found('No such saved query.');
    }
    $given = is_array($_POST['p'] ?? null) ? array_map(static fn ($v): string => is_array($v) ? '' : (string) $v, $_POST['p']) : [];
    $what = ['saved_query' => $q, 'params' => saved_query_bind($q['parameters'], $given, $errors)];
} elseif (trim((string) ($_POST['sql'] ?? '')) !== '') {
    $what = ['sql' => (string) $_POST['sql']];
} else {
    $errors['sql'] = 'Name what to export: the SQL, a saved query or a dataset.';
}
if (!isset(EXPORT_FORMATS[$format])) {
    $errors['format'] = 'The format is csv, xlsx or parquet.';
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$out = dl_guard($pdo, static function () use ($pdo, $me, $format, $what, $asOf): array {
    try {
        return export_make($pdo, $me, $format, $what, $asOf);
    } catch (FieldRefused $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        dl_refuse_fields($e->fields);
    }
});
dl_done('Making the ' . strtoupper($format) . ' file', $out['export_id'], dl_land('/exports', 'queued', 'export-row-' . $out['export_id']), 'exportQueued',
    ['job_id' => $out['job_id']]);
