<?php
declare(strict_types=1);
/**
 * /uploads/{id}/sniff — the polling door of the mapping screen (uploads-loads.md; not a screen, not an action). The uploader's own only.
 * GET: while the writer reads the file, the "Reading the file…" partial again (it polls every 2 s); once read (or failed), HTMX is sent to
 * the mapping (HX-Location) and JSON gets {status, sniff_status}. POST (CSRF): read it again — header_row (1–50) and, for a CSV, the delimiter
 * and the encoding a person corrected — queues a new sniff (the old proposal and its sample are dropped) and answers the partial.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/uploads/write.php';
$pdo = db();
$id = request_integer('id') ?? 0;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    dl_handler_begin();
    require_right('sandbox.upload');
    $u = $id > 0 ? find_upload($pdo, $id) : null;
    if ($u === null || $u['status'] !== 'done') {
        respond_not_found('No such upload.');
    }
    $errors = [];
    $header = dl_int('header_row', 1, 1, 50, 'The header row', $errors) ?? 1;
    $delim = dl_choice('delimiter', null, [',', ';', "\t", '|'], 'The delimiter', $errors);
    $enc = dl_choice('encoding', null, ['utf-8', 'utf-16', 'cp1252', 'latin-1'], 'The encoding', $errors);
    if ($errors !== []) {
        dl_refuse_fields($errors);
    }
    $job = dl_guard($pdo, static function () use ($pdo, $id, $header, $delim, $enc): int {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE upload_sessions SET sniff_status = 'queued', sniff = NULL WHERE id = :id")->execute(['id' => $id]);
        $job = lake_enqueue($pdo, 'sniff', null, $id, array_filter(['header_row' => $header, 'delimiter' => $delim, 'encoding' => $enc], static fn ($v) => $v !== null), 2);
        log_activity($pdo, 'upload.reread', 'upload', $id, ['after' => ['header_row' => $header, 'delimiter' => $delim === "\t" ? 'tab' : $delim, 'encoding' => $enc]]);
        $pdo->commit();
        return $job;
    });
    if (wants_json()) {
        respond_saved(['did' => 'Reading it again', 'record_id' => $id, 'job_id' => $job, 'location' => '/uploads/' . $id . '/map']);
    }
    if (!is_htmx_request()) {
        redirect('/uploads/' . $id . '/map');
    }
    echo view('upload/partials/sniffing.php', ['u' => ['id' => $id]]);
    exit;
}
require_login();
require_right('sandbox.upload');
$u = $id > 0 ? find_upload($pdo, $id) : null;
if ($u === null) {
    respond_not_found('No such upload.');
}
$state = (string) $u['sniff_status'];
if (wants_json()) {
    respond_screen(['upload_id' => $id, 'status' => $u['status'], 'sniff_status' => $state, 'error' => $state === 'failed' ? ($u['sniff']['error'] ?? $u['sniff_error']) : null]);
}
if (in_array($state, ['done', 'failed'], true)) {
    if (is_htmx_request()) {
        hx_location('/uploads/' . $id . '/map');
        http_response_code(200);
        exit;
    }
    redirect('/uploads/' . $id . '/map');
}
echo view('upload/partials/sniffing.php', ['u' => $u]);
