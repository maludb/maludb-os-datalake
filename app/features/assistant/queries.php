<?php
declare(strict_types=1);

/**
 * The command bar (chat-actions), run by the kernel (sso-shell.md): the utterance goes to the kernel's chat endpoint as the acting person
 * (ask_assistant(), app/api/kernel.php); ONE turn of the Data Lake's expert answers. Nothing here calls a model, and nothing of the words
 * is kept: the conversation lives in the PHP session (its id only), the log holds the length, the screen and the run.
 */

/** The screen context the bar sends with every utterance — the page's own stamps (data-screen, data-entity, data-record-id). */
function assistant_context(): array
{
    $recordId = request_string('record_id');
    $_SESSION['assistant_conversation'] ??= 'dl-' . bin2hex(random_bytes(8));
    return ['screen' => mb_substr(request_string('screen'), 0, 60), 'entity' => mb_substr(request_string('entity'), 0, 60),
            'record_id' => $recordId !== '' ? mb_substr($recordId, 0, 80) : null, 'conversation_id' => (string) $_SESSION['assistant_conversation']];
}

/**
 * One turn: ask_assistant()'s answer and what to log about it (never the words). ['answer' => …, 'log' => [length, screen, record_id, kernel, …]].
 */
function assistant_turn(PDO $pdo, int $memberId, string $utterance, array $context): array
{
    $a = ask_assistant($pdo, $memberId, $utterance, $context);
    $log = ['length' => mb_strlen($utterance), 'screen' => $context['screen'], 'record_id' => $context['record_id'], 'kernel' => $a['http']];
    if ($a['error'] === null) {
        $log += ['run_id' => $a['run_id'], 'status' => $a['status'], 'actions' => count($a['actions']), 'navigate' => $a['navigate'], 'cost' => $a['cost']];
    }
    return ['answer' => $a, 'log' => $log];
}
