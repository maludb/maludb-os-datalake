<?php
declare(strict_types=1);
/**
 * /glossary (screen `glossary`; catalog.md): the business glossary — terms as cards grouped by category, draft and approved (params q,
 * status: draft, approved or deprecated). Every insider reads it. JSON: present_term_row() — whitelisted.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/glossary/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/glossary/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$q = mb_substr(trim(request_string('q')), 0, 120);
$status = isset(TERM_STATUSES[request_string('status')]) ? request_string('status') : null;
$page = max(1, min(200, request_integer('page') ?? 1));
$result = find_terms($pdo, ['q' => $q, 'status' => $status, 'limit' => 200], $page);
log_screen_view($pdo, 'glossary');
if (wants_json()) {
    respond_screen(['q' => $q, 'status' => $status, 'total' => $result['total'], 'rows' => array_map('present_term_row', $result['rows']), 'may' => ['edit' => has_right('catalog.edit')]]);
}
$notices = ['deprecated' => ['warning', 'Deprecated. Its columns keep the link.']];
render_screen('Glossary', view('glossary/index.php', ['q' => $q, 'status' => $status, 'result' => $result, 'may_edit' => has_right('catalog.edit'),
    'notice' => dl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'glossary', 'screen' => 'glossary']);
