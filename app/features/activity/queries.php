<?php
declare(strict_types=1);

/**
 * The activity trail the caller may see (mcp_activity_log, db/016: admitted members; a row about a dataset only when they may discover it;
 * a row about a query run only their own, or anyone's for audit.read). The screen `activity` shows the person's own rows; a dataset's
 * History tab (slice 1) reads the same function by `dataset`. The actor's name comes from the directory mirror beside it.
 */
const ACTIVITY_PAGE = 50;

/**
 * Rows newest first, one page; `more` says whether another page exists. $filters: own (bool, default true), action (a prefix), since
 * (days), dataset (int: that dataset's history — the audit key, not only the caller's rows).
 */
function find_my_activity(PDO $pdo, int $memberId, int $page, array $filters = []): array
{
    $where = [];
    $args = [];
    if (($filters['dataset'] ?? null) !== null) {
        $where[] = 'l.dataset_id = :dataset';
        $args['dataset'] = (int) $filters['dataset'];
    } elseif ($filters['own'] ?? true) {
        $where[] = 'l.actor_member_id = :member';
        $args['member'] = $memberId;
    }
    if (($filters['action'] ?? '') !== '') {
        $where[] = 'l.action LIKE :action';
        $args['action'] = str_replace(['%', '_'], ['\\%', '\\_'], rtrim((string) $filters['action'], '.')) . '%';
    }
    if (($filters['since'] ?? null) !== null) {
        $where[] = 'l.occurred_at > now() - make_interval(days => :since)';
        $args['since'] = (int) $filters['since'];
    }
    $page = max(1, $page);
    $sql = 'SELECT l.log_id AS activity_id, l.occurred_at, l.actor_member_id, m.display_name AS actor_name, (m.member_kind = \'agent\') AS actor_is_agent,
                   l.source, l.action, l.screen, l.entity_type, l.entity_id, l.after, l.dataset_id, l.query_run_id, l.token_id, l.agent_run_id
              FROM mcp_activity_log l LEFT JOIN members m ON m.id = l.actor_member_id' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY l.occurred_at DESC, l.log_id DESC LIMIT ' . (ACTIVITY_PAGE + 1) . ' OFFSET ' . (($page - 1) * ACTIVITY_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['sentence'] = activity_sentence($r);
    }
    unset($r);
    return ['rows' => array_slice($rows, 0, ACTIVITY_PAGE), 'more' => count($rows) > ACTIVITY_PAGE];
}

/** A row's JSON: the actor, the sentence, the keys. */
function present_activity_row(array $r): array
{
    $id = static fn ($v): ?int => $v === null ? null : (int) $v;
    return ['activity_id' => (int) $r['activity_id'], 'occurred_at' => json_ts($r['occurred_at']),
            'actor' => ['member_id' => $id($r['actor_member_id']), 'display_name' => $r['actor_name'], 'is_agent' => (bool) $r['actor_is_agent']],
            'source' => $r['source'], 'action' => $r['action'], 'sentence' => $r['sentence'] ?? activity_sentence($r), 'entity_type' => $r['entity_type'],
            'entity_id' => $id($r['entity_id']), 'dataset_id' => $id($r['dataset_id']), 'query_run_id' => $id($r['query_run_id']), 'agent_run_id' => $id($r['agent_run_id'])];
}

/** The record a row concerns, as a link: [url, label] or null — the dataset first, then the query run. */
function activity_record_link(array $r): ?array
{
    if (($r['dataset_id'] ?? null) !== null) {
        return ['/datasets/' . (int) $r['dataset_id'], 'the dataset'];
    }
    if (($r['query_run_id'] ?? null) !== null) {
        return ['/runs/' . (int) $r['query_run_id'], 'the query run'];
    }
    return null;
}

/** One line for a row, in words: "Priya certified a dataset: Sales orders". Falls back to the event name. The slices' events are named here once. */
function activity_sentence(array $r): string
{
    $who = $r['actor_name'] ?? app_name();
    if (!empty($r['actor_is_agent'])) {
        $who .= ' (agent)';
    }
    $after = is_array($r['after'] ?? null) ? $r['after'] : (json_decode((string) ($r['after'] ?? ''), true) ?: []);
    $named = $after['title'] ?? $after['key'] ?? $after['name'] ?? $after['label'] ?? null;
    $title = is_string($named) ? ': ' . mb_substr($named, 0, 80) : '';
    $words = [
        'member.sign_on' => 'signed on', 'member.sign_out' => 'signed out', 'member.refused' => 'was refused', 'member.sign_on.refused' => 'was refused at sign-on',
        'member.session_ended' => 'was signed out', 'directory.sync' => 'refreshed the directory', 'settings.prefs' => 'changed how they are told',
        'notification.read' => 'read a notification', 'notification.read_all' => 'read every notification', 'assistant.ask' => 'asked the command bar',
        'token.mint' => 'made an MCP token', 'token.revoke' => 'revoked an MCP token', 'key.mint' => 'minted an API key', 'key.rotate' => 'rotated an API key',
        'key.revoke' => 'revoked an API key', 'key.sql_switch' => 'changed an API key\'s SQL switch',
        'dataset.create' => 'added a dataset', 'dataset.update' => 'described a dataset', 'dataset.tag' => 'tagged a dataset', 'dataset.untag' => 'untagged a dataset',
        'dataset.deprecate' => 'deprecated a dataset', 'dataset.retire' => 'retired a dataset', 'dataset.follow' => 'followed a dataset', 'dataset.unfollow' => 'stopped following a dataset',
        'column.update' => 'described a column', 'note.add' => 'added a note', 'note.delete' => 'deleted a note', 'term.create' => 'added a glossary term',
        'term.update' => 'changed a glossary term', 'term.approve' => 'approved a glossary term', 'term.deprecate' => 'deprecated a glossary term',
        'tag.create' => 'made a tag', 'tag.update' => 'changed a tag', 'tag.archive' => 'archived a tag',
        'upload.map' => 'mapped an upload', 'upload.discard' => 'discarded an upload', 'load.queue' => 'queued a load', 'load.rollback' => 'rolled back a load',
        'template.save' => 'saved a mapping template', 'template.delete' => 'deleted a mapping template',
        'query.run' => 'ran a query', 'saved_query.save' => 'saved a query', 'saved_query.delete' => 'deleted a saved query', 'query.verify' => 'verified a query',
        'sandbox.save' => 'saved a result to their sandbox', 'export.make' => 'asked for an export', 'export.delete' => 'deleted an export',
        'access.request' => 'asked for access', 'access.draft' => 'drafted an access request', 'access.withdraw' => 'withdrew an access request',
        'access.decide' => 'decided an access request', 'grant.set' => 'granted access', 'grant.revoke' => 'revoked access',
        'group.create' => 'made a group', 'group.update' => 'changed a group', 'group.archive' => 'archived a group', 'group.member_add' => 'added someone to a group',
        'group.member_remove' => 'removed someone from a group', 'policy.set' => 'changed a masking policy', 'column.classify' => 'classified a column',
        'column.confirm' => 'confirmed personal data', 'dataset.admin_read' => 'opened a dataset with the break-glass',
        'contract.draft' => 'drafted a contract', 'contract.publish' => 'published a contract', 'promote.run' => 'promoted a dataset', 'promote.rebuild' => 'rebuilt a dataset',
        'drift.decide' => 'decided a schema change', 'check.create' => 'added a check', 'check.update' => 'changed a check', 'check.disable' => 'disabled a check',
        'check.delete' => 'deleted a check', 'check.run' => 'ran a check', 'incident.ack' => 'acknowledged an incident', 'incident.assign' => 'assigned an incident',
        'incident.note' => 'noted on an incident', 'incident.resolve' => 'resolved an incident', 'incident.dismiss' => 'dismissed an incident',
        'certify.set' => 'certified a dataset', 'certify.remove' => 'removed a certification',
        'source.create' => 'added a source', 'source.update' => 'changed a source', 'source.credential_set' => 'set a source\'s credential', 'source.test' => 'tested a source',
        'source.pull' => 'pulled a source', 'source.pause' => 'paused a source', 'source.resume' => 'resumed a source', 'source.delete' => 'deleted a source',
        'transform.save' => 'saved a transform', 'transform.run' => 'ran a transform', 'transform.enable' => 'switched a transform', 'transform.delete' => 'deleted a transform',
        'metric.save' => 'saved a metric', 'metric.certify' => 'certified a metric', 'metric.retire' => 'retired a metric',
        'retention.set' => 'set a retention rule', 'retention.remove' => 'removed a retention rule', 'erasure.request' => 'asked for an erasure', 'erasure.scan' => 'scanned for an erasure',
        'erasure.approve' => 'approved an erasure', 'erasure.cancel' => 'cancelled an erasure', 'maintenance.run' => 'ran maintenance', 'snapshot.expire' => 'expired old snapshots',
        'landing.purge' => 'purged a landing file', 'landing.pin' => 'pinned a landing file', 'backup.drill' => 'ran a restore drill',
        'share.grant' => 'shared a product with an application', 'share.revoke' => 'stopped sharing with an application', 'share.read' => 'read a share',
        'steward.propose' => 'proposed a change', 'steward.decide' => 'decided a proposal', 'settings.save' => 'changed the lake\'s settings',
        'query_limits.set' => 'changed the query limits', 'agents_settings.save' => 'changed the agents\' settings', 'agent.dispatch_retry' => 'retried a dispatch',
        'worker.pass' => 'the worker ran', 'notification.send' => 'sent a notice',
    ];
    if ($r['action'] === 'search.catalog' && isset($after['q'])) {
        return $who . ' searched for “' . mb_substr((string) $after['q'], 0, 60) . '”' . (isset($after['count']) ? ' (' . (int) $after['count'] . ' found)' : '');
    }
    if ($r['action'] === 'screen.view') {
        return $who . ' opened a screen' . (($r['screen'] ?? '') !== '' ? ' (' . $r['screen'] . ')' : '');
    }
    return $who . ' ' . ($words[$r['action']] ?? str_replace(['.', '_'], [' ', ' '], (string) $r['action'])) . $title;
}
