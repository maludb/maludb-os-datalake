<?php
declare(strict_types=1);

/**
 * The command bar (chat-actions), run by the kernel (sso-shell.md): the utterance goes to the kernel's chat endpoint as the acting person
 * (assistant_turn() → ask_assistant()); ONE turn of the Data Lake's expert answers. Renders the reply and the actions (a paused one as "waits
 * for approval"), follows a `navigate` answer by HX-Location (a local path only), fires HX-Trigger per entity changed, says in words when the
 * kernel is down. Not an action of the manifest — the kernel runs the agent. People only. Logs `assistant.ask` with the utterance's length,
 * the screen and the run id — never the words.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/assistant/queries.php';

dl_handler_begin();
require_human();
$pdo = db();
$me = (int) current_member_id();

$utterance = trim((string) ($_POST['utterance'] ?? $_POST['message'] ?? ''));
if ($utterance === '' || mb_strlen($utterance) > 2000) {
    http_response_code(422);
    echo view('shared/assistant-reply.php', ['error' => 'Say what you want in a sentence or two.']);
    exit;
}
$context = assistant_context();
$t = assistant_turn($pdo, $me, $utterance, $context);
$a = $t['answer'];
log_activity($pdo, 'assistant.ask', null, null, ['screen' => $context['screen'], 'after' => $t['log']]);
if ($a['error'] !== null) {
    http_response_code($a['http'] >= 400 && $a['http'] < 600 ? $a['http'] : 503);
    echo view('shared/assistant-reply.php', ['error' => $a['error']]);
    exit;
}
$events = assistant_refresh_events($a['actions']);
if ($events !== []) {
    hx_trigger(implode(', ', $events));
}
if ($a['navigate'] !== null) {
    hx_location($a['navigate']);
    header('HX-Push-Url: ' . $a['navigate']);
}
echo view('shared/assistant-reply.php', ['reply' => $a['reply'], 'status' => (string) $a['status'], 'actions' => $a['actions'], 'approval' => $a['approval'], 'finished' => $a['finished'], 'navigate' => $a['navigate']]);
