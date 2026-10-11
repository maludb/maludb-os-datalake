<?php
declare(strict_types=1);
/**
 * The upload doors' prelude (not a screen, not an action — the browser's uploader, DocCloud's protocol): POST, a signed-in person (or a
 * relayed token) holding sandbox.upload, the CSRF token in X-CSRF-Token (a raw part has no form fields), and the refusal shape every door
 * answers: {error: {code, message, …}} with the UploadRefused's status — a browser without JavaScript gets the sentence on a page.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/write.php';

function upload_door_begin(): void
{
    require_post();
    require_login();
    verify_csrf();
    require_right('sandbox.upload');
}

function upload_door_refused(UploadRefused $e): never
{
    emit_action_status(false, ['error' => $e->getMessage(), 'code' => $e->reason]);
    if (wants_json() || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')) {
        json_error($e->reason, $e->getMessage(), $e->status, $e->extra);
    }
    refuse($e->status, $e->getMessage());
}
