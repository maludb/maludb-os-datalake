<?php
declare(strict_types=1);

/**
 * The dataset gate (access-and-policies §1; sso-shell.md "The dataset gate") — every later slice's dataset page and handler calls it and
 * none re-implements it. The walls are the database's (db/008 and db/018: dl_level(), dl_can_see_stub(), the intersection for an agent's
 * run a person asked for); PHP asks, renders and refuses in their words. A right says what KIND of thing a person may do (require_right(),
 * first); the level says whether they may do it to THIS dataset (require_level(), second).
 *
 * K38 lives here too: lake_apply_run_requester() reads the kernel's run facts once per relayed request and names the person an agent's
 * run acts for (app.on_behalf_of, set by db_apply_context()), or — on a kernel that does not say who asked a chat turn — the fail-closed
 * ceiling `internal_unpersonal` (access-and-policies §6; query-service §3).
 */

/** The levels in order (db/008 dl_level_rank). */
const DL_LEVELS = ['none' => 0, 'discover' => 1, 'read' => 2, 'read_unmasked' => 3, 'write' => 4, 'own' => 5];

/** A dataset's key (db/007's CHECK): <zone>.<name>. */
const DATASET_KEY_RE = '/^(raw|curated|product|sandbox)\.[a-z][a-z0-9_]{0,62}$/';

/** What require_level()'s refusal says the person may not do, by the level the screen or action needs. */
const DL_LEVEL_VERBS = ['discover' => 'see', 'read' => 'read', 'read_unmasked' => 'read unmasked', 'write' => 'change', 'own' => 'manage'];

/** The fail-closed ceiling (query-service §3): an agent's chat turn the kernel did not say who asked for. */
const DL_CEILING = 'internal_unpersonal';

/**
 * A dataset by its id (digits) or its key (`curated.sales_orders`), from the catalog's base row — the gate judges it next; null when
 * the reference is neither or names nothing. The engine's own columns (the DuckLake table id) are not read: PHP never needs them.
 */
function dataset_by_ref(PDO $pdo, string $ref): ?array
{
    $ref = trim($ref);
    if ($ref !== '' && ctype_digit($ref) && strlen($ref) <= 18) {
        $where = 'd.id = :r';
        $arg = (int) $ref;
    } elseif (preg_match(DATASET_KEY_RE, $ref)) {
        $where = 'd.key = :r';
        $arg = $ref;
    } else {
        return null;
    }
    $st = $pdo->prepare("SELECT d.id, d.key, d.zone, d.name, d.title, d.description, d.status, d.certification, d.classification, d.has_personal_data,
                                d.owner_member_id, d.steward_member_id, d.department_id, d.tier, d.row_count, d.byte_size, d.data_updated_at,
                                d.metadata_updated_at, d.created_at
                           FROM datasets d WHERE $where");
    $st->execute(['r' => $arg]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** The caller's level on a dataset — `none`, `discover`, `read`, `read_unmasked`, `write` or `own` — as the database answers it (the intersection under K38). */
function dataset_level(PDO $pdo, int $datasetId): string
{
    return (string) (one_value($pdo, 'SELECT dl_level(:id)', ['id' => $datasetId]) ?? 'none');
}

/** The rank of a level (0 none … 5 own). */
function dl_level_rank(string $level): int
{
    return DL_LEVELS[$level] ?? 0;
}

/**
 * The gate. $need ∈ discover | read | read_unmasked | write | own.
 *   - no such dataset, or below `discover` → 404, the same nothing as a dataset that does not exist (a hidden dataset never confirms
 *     itself) — except a stub (dl_can_see_stub: an insider holding a role, not external, the dataset not sandbox nor retired), which
 *     passes `discover` as ['is_stub' => true, …the stub's own fields] for the dataset page's stub render only;
 *   - below $need → 403 "You may not {verb} {key} — request access", and in JSON {"error": {"code": "not_allowed", "need", "request_access"}};
 *   - an agent's chat turn under the fail-closed ceiling may not read or change a confidential, restricted or personal dataset → 403 k38_pending;
 *   - else the dataset row with `my_level` added.
 */
function require_level(PDO $pdo, string $ref, string $need): array
{
    if (!isset(DL_LEVEL_VERBS[$need])) {
        throw new InvalidArgumentException('require_level: unknown level ' . $need);
    }
    $d = dataset_by_ref($pdo, $ref);
    if ($d === null) {
        respond_not_found('No such dataset.');
    }
    $level = dataset_level($pdo, (int) $d['id']);
    if (dl_level_rank($level) < 1) {
        if ($need === 'discover' && db_bool($pdo, 'SELECT dl_can_see_stub(:id)', ['id' => (int) $d['id']])) {
            return ['is_stub' => true, 'id' => (int) $d['id'], 'key' => $d['key'], 'zone' => $d['zone'], 'title' => $d['title'],
                    'owner_member_id' => $d['owner_member_id'], 'department_id' => $d['department_id'], 'certification' => $d['certification'],
                    'my_level' => 'none'];
        }
        respond_not_found('No such dataset.');
    }
    if (dl_level_rank($level) < dl_level_rank($need)) {
        $request = '/access/requests/new?' . http_build_query(['dataset' => (int) $d['id'], 'level' => $need]);
        $message = 'You may not ' . DL_LEVEL_VERBS[$need] . ' ' . $d['key'] . ' — request access';
        lake_refuse_level(403, 'not_allowed', $message, ['need' => $need, 'request_access' => $request], $request);
    }
    if (lake_ceiling() === DL_CEILING && dl_level_rank($need) >= DL_LEVELS['read']
        && (in_array($d['classification'], ['confidential', 'restricted'], true) || !empty($d['has_personal_data']))) {
        lake_refuse_level(403, 'k38_pending', $d['key'] . ' is readable once the kernel says who asked (K38)', ['need' => $need], null);
    }
    return $d + ['my_level' => $level, 'is_stub' => false];
}

/** A level refusal: JSON {"error": {code, message, …extra}}; HTMX a flash; a page the sentence with the Request access button. */
function lake_refuse_level(int $status, string $code, string $message, array $extra, ?string $requestUrl): never
{
    emit_action_status(false, ['error' => $message] + $extra);
    if (wants_json()) {
        json_error($code, $message, $status, $extra);
    }
    http_response_code($status);
    if (is_htmx_request()) {
        header('HX-Retarget: #flash');
        header('HX-Reswap: innerHTML');
        echo view('shared/flash.php', ['kind' => 'warning', 'message' => $message, 'link' => $requestUrl === null ? null : [$requestUrl, 'Request access']]);
        exit;
    }
    echo view('shared/message.php', ['title' => 'Not yours to open', 'message' => $message,
        'action' => $requestUrl === null ? null : [$requestUrl, 'Request access', 'message-request-access-btn']]);
    exit;
}

/**
 * K38 (access-and-policies §6): from the kernel's run facts for an agent's run token —
 *   requested_by_member_id present → the run acts for that person: app.on_behalf_of (the intersection; a requester who does not hold this
 *     application leaves the agent nothing of theirs, which the database's intersection already answers);
 *   the key absent (a kernel from before K38) on a `chat` run, or no facts at all → the fail-closed ceiling;
 *   a duty, an evaluation, a message, a run asked of the agent itself → the agent alone, its own grants.
 * Called by the bootstrap before db_apply_context(); remembered for the request (lake_caller() reads it for the query service).
 */
function lake_apply_run_requester(?array $facts): void
{
    $GLOBALS['__run_facts'] = $facts;
    unset($GLOBALS['__on_behalf_of'], $GLOBALS['__lake_ceiling']);
    if ($facts === null || empty($facts['valid'])) {
        $GLOBALS['__lake_ceiling'] = DL_CEILING;               // the kernel could not say: fail closed
        return;
    }
    if (array_key_exists('requested_by_member_id', $facts)) {
        if ($facts['requested_by_member_id'] !== null && (int) $facts['requested_by_member_id'] > 0) {
            $GLOBALS['__on_behalf_of'] = (int) $facts['requested_by_member_id'];
        }
        return;
    }
    if (($facts['trigger'] ?? '') === 'chat') {
        $GLOBALS['__lake_ceiling'] = DL_CEILING;
    }
}

/** The person an agent's run acts for this request (K38), or null. */
function lake_on_behalf_of(): ?int
{
    return isset($GLOBALS['__on_behalf_of']) ? (int) $GLOBALS['__on_behalf_of'] : null;
}

/** The ceiling on this request's reads ('internal_unpersonal'), or null. */
function lake_ceiling(): ?string
{
    return $GLOBALS['__lake_ceiling'] ?? null;
}
