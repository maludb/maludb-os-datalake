<?php
declare(strict_types=1);

/**
 * Incidents — the writes people (and the Steward) make (contracts-quality.md: incident_ack, incident_assign, incident_note, incident_resolve,
 * incident_dismiss). Opening an incident and resolving it on a pass are the database's (dl_check_result_record); a resolution or a dismissal is
 * a person's decision (`people`); an agent notes and acknowledges.
 */
require_once __DIR__ . '/queries.php';

function incident_ack(PDO $pdo, int $id, int $by): void
{
    $st = $pdo->prepare("UPDATE incidents SET status = 'acknowledged', acknowledged_at = now() WHERE id = :id AND status = 'new'");
    $st->execute(['id' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Only a new incident is acknowledged.');
    }
}

/** Assign an incident to a member (the assignee told — incident_assigned; a new incident is acknowledged by being assigned). */
function incident_assign(PDO $pdo, int $id, int $assignee, int $by): void
{
    $who = one_row($pdo, "SELECT member_id, display_name FROM mcp_members WHERE member_id = :m AND status = 'active'", ['m' => $assignee]);
    if ($who === null) {
        throw new DomainException('Choose an active person or agent to assign it to.');
    }
    $i = one_row($pdo, "SELECT id, dataset_id, title, status FROM incidents WHERE id = :id", ['id' => $id]) ?? throw new DomainException('Not found.');
    if (!in_array($i['status'], INCIDENT_OPEN, true)) {
        throw new DomainException('A ' . $i['status'] . ' incident is not assigned.');
    }
    $pdo->prepare("UPDATE incidents SET assignee_member_id = :a, status = CASE WHEN status = 'new' THEN 'acknowledged' ELSE status END,
                          acknowledged_at = COALESCE(acknowledged_at, now()) WHERE id = :id")->execute(['a' => $assignee, 'id' => $id]);
    if ($assignee !== $by) {
        $pdo->prepare("SELECT dl_notify(:m, 'incident_assigned', :t, :b, 'incident', :id, :ds, :by, :dd)")->execute(['m' => $assignee,
            't' => 'An incident is yours: ' . mb_substr((string) $i['title'], 0, 150), 'b' => 'Assigned to you — acknowledge it, note what you find, resolve it.',
            'id' => $id, 'ds' => (int) $i['dataset_id'], 'by' => $by, 'dd' => 'incident_assigned:' . $id . ':' . $assignee]);
    }
}

/** A note on an incident (kind note or triage — the Steward's); names and counts, never values. Its id. */
function incident_note(PDO $pdo, int $id, string $body, string $kind, int $by): int
{
    $st = $pdo->prepare('INSERT INTO incident_notes (incident_id, member_id, body, kind) VALUES (:i, :m, :b, :k) RETURNING id');
    $st->execute(['i' => $id, 'm' => $by, 'b' => $body, 'k' => $kind]);
    return (int) $st->fetchColumn();
}

function incident_resolve(PDO $pdo, int $id, string $resolution, ?string $rootCause, int $by): void
{
    $st = $pdo->prepare("UPDATE incidents SET status = 'resolved', resolved_at = now(), resolution = :r, root_cause = :c
                          WHERE id = :id AND status IN ('new', 'acknowledged', 'investigating')");
    $st->execute(['r' => $resolution, 'c' => $rootCause, 'id' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Only an open incident is resolved.');
    }
}

function incident_dismiss(PDO $pdo, int $id, string $reason, int $by): void
{
    $st = $pdo->prepare("UPDATE incidents SET status = 'dismissed', resolved_at = now(), resolution = :r
                          WHERE id = :id AND status IN ('new', 'acknowledged', 'investigating')");
    $st->execute(['r' => 'Dismissed: ' . $reason, 'id' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Only an open incident is dismissed.');
    }
}

/**
 * The incident actions' shared start: the POST prelude (dl_handler_begin), the incident named by `incident`, its dataset's gate (discover — a
 * hidden dataset's incident is the same 404 as none) and what the caller may do (incident_may). Returns [pdo, incident, may].
 */
function incident_action_begin(): array
{
    dl_handler_begin();
    $pdo = db();
    $id = (int) (req_val('incident') ?? 0);
    $probe = $id > 0 ? one_value($pdo, 'SELECT dataset_id FROM incidents WHERE id = :id', ['id' => $id]) : null;
    if ($probe === null) {
        respond_not_found('No such incident.');
    }
    require_dataset($pdo, (string) $probe, 'discover');
    $i = find_incident($pdo, $id) ?? respond_not_found('No such incident.');
    return [$pdo, $i, incident_may($i)];
}
