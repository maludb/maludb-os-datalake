<?php
declare(strict_types=1);

/**
 * The one funnel (memory.md §2). Every state change and every screen entry writes a row; the
 * ingest bridge ships it to the tenant's MaluDB with "application": "datalake".
 * $opts: actor_member_id, source, screen, route, before, after, request_id, agent_run_id, department_id, location_id, kernel_request_id,
 *        token_id (the key an API call ran under), dataset_id, query_run_id (the audit keys of design §6.8 — a dataset's TIMELINE is one
 *        index). A ROW VALUE, A PROFILE'S TOP VALUES, SQL TEXT, A FILTER, A CREDENTIAL, A TOKEN OR A FILE PATH IS NEVER IN A PAYLOAD
 *        (design §6.8 — a WHERE clause can hold an email address): ids, keys, names, counts, kinds, statuses and byte sizes are; a key is
 *        named by its LABEL in `after`.
 */
function log_activity(PDO $pdo, string $action, ?string $entityType = null, int|string|null $entityId = null, array $opts = []): void
{
    try {
        $eid = null;
        if (is_int($entityId) || (is_string($entityId) && ctype_digit($entityId))) {
            $eid = (int) $entityId;
        }
        $stmt = $pdo->prepare(<<<'SQL'
            INSERT INTO activity_log
                (actor_member_id, source, action, screen, route, entity_type, entity_id,
                 before, after, request_id, session_id, ip_address, agent_run_id, department_id, location_id, kernel_request_id,
                 token_id, dataset_id, query_run_id)
            VALUES
                (:actor, :source, :action, :screen, :route, :etype, :eid,
                 :before, :after, :rid, :sid, :ip, :run, :dept, :loc, :krid, :tok, :ds, :qr)
        SQL);
        $stmt->execute([
            'actor'  => array_key_exists('actor_member_id', $opts) ? $opts['actor_member_id'] : ($_SESSION['member_id'] ?? null),
            'source' => $opts['source'] ?? default_activity_source(),
            'action' => $action,
            'screen' => $opts['screen'] ?? null,
            'route'  => $opts['route'] ?? request_route(),
            'etype'  => $entityType,
            'eid'    => $eid,
            'before' => isset($opts['before']) ? json_encode($opts['before'], JSON_THROW_ON_ERROR) : null,
            'after'  => isset($opts['after']) ? json_encode($opts['after'], JSON_THROW_ON_ERROR) : null,
            'rid'    => $opts['request_id'] ?? agent_run_request_id() ?? request_id(),
            'sid'    => PHP_SAPI === 'cli' ? null : (session_id() ?: null),
            'ip'     => PHP_SAPI === 'cli' ? null : client_ip(),
            'run'    => $opts['agent_run_id'] ?? current_agent_run_id(),
            'dept'   => $opts['department_id'] ?? null,
            'loc'    => $opts['location_id'] ?? ($entityType === 'location' ? $eid : null),
            'krid'   => $opts['kernel_request_id'] ?? null,
            'tok'    => isset($opts['token_id']) ? (int) $opts['token_id'] : (isset($GLOBALS['__api_token_id']) ? (int) $GLOBALS['__api_token_id'] : null),
            'ds'     => isset($opts['dataset_id']) ? (int) $opts['dataset_id'] : ($entityType === 'dataset' ? $eid : null),
            'qr'     => isset($opts['query_run_id']) ? (int) $opts['query_run_id'] : ($entityType === 'query_run' ? $eid : null),
        ]);
    } catch (Throwable $e) {
        error_log('activity_log insert failed: ' . $e->getMessage());
    }
}

/** 'web' for the UI; 'agent' under a run token; 'assistant' under a person's action token; 'cron' under CLI; 'api' under a key. ('system' is the engine's — services/.) */
function default_activity_source(): string
{
    if (is_action_authed()) {
        return current_agent_run_id() !== null ? 'agent' : 'assistant';
    }
    if (!empty($GLOBALS['__public_door'])) {
        return (string) $GLOBALS['__public_door'];      // 'api' (a key's call) — the lake has no door for a link holder
    }
    return PHP_SAPI === 'cli' ? 'cron' : 'web';
}

function log_screen_view(PDO $pdo, string $screen): void
{
    if (wants_json() && ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') !== '1') {
        return;
    }
    log_activity($pdo, 'screen.view', null, null, ['screen' => $screen]);
}

/**
 * Under a run token every row of the run carries the run's own request id (the join to the
 * kernel's prompt ledger). The Data Lake cannot read the kernel's database: it asks the run-facts call once
 * per request (agents.md, A7 b) — never a header the agent could set.
 */
function agent_run_request_id(): ?string
{
    static $resolved = false, $id = null;
    if (!$resolved) {
        $resolved = true;
        if (current_agent_run_id() !== null) {
            $facts = run_facts((string) ($GLOBALS['__action_token'] ?? ''));
            $id = $facts !== null && !empty($facts['valid']) ? ($facts['request_id'] ?? null) : null;
        }
    }
    return $id;
}

function request_id(): string
{
    static $id = null;
    if ($id === null) {
        $given = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        $id = $given !== '' && preg_match('/^[A-Za-z0-9._:-]{4,80}$/', $given) ? $given : bin2hex(random_bytes(8));
    }
    return $id;
}

function request_route(): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH) ?: '';
    return trim($method . ' ' . $path);
}
