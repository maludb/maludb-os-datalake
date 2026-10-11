<?php
declare(strict_types=1);
/**
 * /quality?severity=&department= (screen `quality`; contracts-quality.md) — the lake's health: three lists — failing checks (the latest result
 * failed: dataset, check, value, threshold, since), late datasets (dl_freshness_due: key, warn/error, data updated, overdue), open incidents
 * (title, severity, status, assignee, opened, downstream count) — every row a link; at 375 one status list. The walls are the views'
 * (what the caller may discover). JSON: the three lists.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/quality/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/quality/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$f = ['severity' => in_array(request_string('severity'), ['warn', 'error'], true) ? request_string('severity') : null,
      'department' => request_integer('department')];
$h = quality_home($pdo, (int) current_member_id(), $f);
log_screen_view($pdo, 'quality');
if (wants_json()) {
    respond_screen(['filters' => $f, 'failing' => array_map('present_failing', $h['failing']), 'late' => array_map('present_late', $h['late']),
        'incidents' => array_map('present_incident_row', $h['incidents']), 'incidents_total' => $h['incidents_total']]);
}
render_screen('Quality', view('quality/index.php', ['f' => $f, 'h' => $h, 'departments' => find_live_departments($pdo)]), ['activeNav' => 'quality', 'screen' => 'quality']);
