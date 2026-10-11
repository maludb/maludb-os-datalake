<?php
declare(strict_types=1);
/**
 * /datasets/{id}/preview — the Preview tab's door (uploads-loads.md; query-service §7 `preview`; the dataset_preview tool): 100 rows of the
 * dataset through the QUERY SERVICE, masked as the caller may see them — a column masked for them is marked "masked for you" (the mask's kind
 * on hover); as_of = a snapshot (engineers pick from the dataset's last ten), else the latest; prov=1 shows the _dl_* provenance columns; a
 * refusal renders its sentence and Request access; the engine away renders "The engine is not answering". Every call is a query_runs row
 * (source preview) and a query.run activity row (its id, the dataset, the counts — never the SQL, never a value). Discover on the dataset: the
 * service answers below read in its own words.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/preview/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/preview/present.php';
require_once dirname(__DIR__, 2) . '/app/features/loads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$d = require_dataset($pdo, request_string('id') !== '' ? request_string('id') : request_string('dataset'), 'discover');
$asOf = request_string('as_of');
$asOf = $asOf === '' ? null : (ctype_digit($asOf) ? (int) $asOf : $asOf);
$p = preview($pdo, (int) current_member_id(), (int) $d['id'], $asOf);
if (wants_json()) {
    respond_screen(['preview' => present_preview($p, request_bool('prov'))]);
}
echo view('datasets/partials/preview-body.php', ['d' => $d, 'p' => $p, 'prov' => request_bool('prov'), 'as_of' => $asOf,
    'snapshots' => has_right('loads.manage') ? dataset_snapshots($pdo, (int) $d['id'], 10) : [],
    'mask_kinds' => preview_mask_kinds($pdo, (int) $d['id'], (string) $d['my_level'])]);
