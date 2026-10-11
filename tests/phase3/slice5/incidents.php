<?php
/**
 * Slice 5 proof 5 — incidents (contracts-quality.md "Proof" 5): the not_null failure opened ONE incident (a second failure kept it), the owner
 * and the steward told, the Steward dispatched (an error only); acknowledge, assign (the assignee told), note (the Steward's triage first),
 * resolve (people — an agent refused), dismiss; a passing run resolves an incident citing the result; the incident page (the check, the result
 * that opened it, the failing keys at read — none below it —, what it reaches) and the list.
 */
require __DIR__ . '/lib.php';
$cur = ds_id('curated.orders');
$nn = check_id('curated.orders', 'not_null', 'amount');
$av = check_id('curated.orders', 'accepted_values', 'status');
$rg = check_id('curated.orders', 'range', 'amount');
$a0 = (int) pg1('SELECT COALESCE(max(id), 0) FROM activity_log');

echo "1. One incident per failing check\n";
$inc = open_incident($nn);
ok($inc !== null && $inc[1] === 'new' && $inc[2] === 'error', 'the failing not_null opened an incident: new, error');
ok(pg1("SELECT count(*) FROM incidents WHERE check_id = $nn") === '1' && pg1("SELECT count(*) FROM check_results WHERE check_id = $nn AND NOT passed") === '2',
   'two failures, ONE incident (a second failure keeps it — the database\'s rule)');
$id = (int) $inc[0];
ok(pg1("SELECT string_agg(member_id::text, ',' ORDER BY member_id) FROM notifications WHERE kind = 'check_failed' AND record_type = 'incident' AND record_id = $id") === '27,30',
   'the owner (Marco) and the steward (Dana) are told');
ok(pg1("SELECT agent_member_id || '|' || kind || '|' || via FROM agent_dispatches WHERE incident_id = $id") === '42|incident|chat', 'the Steward is dispatched to triage it (one dispatch, a chat turn)');
$warn = open_incident($av);
ok($warn !== null && $warn[2] === 'warn' && pg1('SELECT count(*) FROM agent_dispatches WHERE incident_id = ' . (int) $warn[0]) === '0', 'a warning\'s incident wakes no agent');
ok(pg1("SELECT certification FROM datasets WHERE id = $cur") === 'none', '(not certified — nothing to lose yet)');

echo "2. The incident page\n";
$v = get_json(26, "/incidents/$id")['json']['data']['incident'] ?? [];
ok(($v['check']['words'] ?? '') === 'not null (amount)' && ($v['opened_by']['rows_failed'] ?? 0) === 1 && ($v['opened_by']['failing_keys'] ?? []) === ['SO-004'],
   'Priya (read) sees the check, the result that opened it and its failing key');
$sv = agent_get(42, "/incidents/$id")['json']['data']['incident'] ?? [];
ok(($sv['incident_id'] ?? 0) === $id && ($sv['opened_by']['failing_keys'] ?? ['x']) === [], 'the Steward (discover only) sees the incident but no failing key');
ok(get_json(29, "/incidents/$id")['code'] === 404, 'Ann (external) gets the same 404 as no incident');
ok(($v['may']['ack'] ?? true) === false && ($v['may']['resolve'] ?? true) === false && ($v['may']['note'] ?? false) === true, 'Priya may note, not acknowledge or resolve');
$list = get_json(27, '/incidents')['json']['data']['incidents'] ?? [];
ok(count(array_filter($list, static fn (array $x): bool => $x['incident_id'] === $id)) === 1 && ($list[0]['severity'] ?? '') === 'error', 'the list: open first, errors first');

echo "3. Acknowledge, assign, note, resolve, dismiss\n";
ok(post_json(26, '/incidents/ack.php', ['incident' => $id])['code'] === 403, 'a Member may not acknowledge: 403');
$ack = post_json(27, '/incidents/ack.php', ['incident' => $id]);
ok($ack['code'] === 200 && pg1("SELECT status || '|' || (acknowledged_at IS NOT NULL) FROM incidents WHERE id = $id") === 'acknowledged|true', 'the owner acknowledges it');
ok(post_json(27, '/incidents/ack.php', ['incident' => $id])['code'] === 422, 'acknowledging twice: refused');
$n0 = last_notice_id();
$as = post_json(27, '/incidents/assign.php', ['incident' => $id, 'assignee' => 30]);
ok($as['code'] === 200 && pg1("SELECT assignee_member_id FROM incidents WHERE id = $id") === '30' && last_notice(30, 'incident_assigned', $n0) !== null, 'assigned to Dana — she is told');
ok(post_json(30, '/incidents/assign.php', ['incident' => $id, 'assignee' => 26])['code'] === 403, 'Dana (an Analyst) may not reassign: 403');
$tr = agent_post(42, '/incidents/note.php', ['incident' => $id, 'body' => 'SO-004 arrived without an amount on load 1; one row held back.', 'kind' => 'triage']);
$pn = post_json(26, '/incidents/note.php', ['incident' => $id, 'body' => 'Seen it on my report too.']);
ok($tr['code'] === 200 && $pn['code'] === 200, 'the Steward notes its triage; Priya notes too');
$notes = get_json(27, "/incidents/$id")['json']['data']['incident']['notes'] ?? [];
ok(($notes[0]['kind'] ?? '') === 'triage' && ($notes[0]['by_agent'] ?? false) === true && ($notes[1]['body'] ?? '') === 'Seen it on my report too.', 'the Steward\'s triage first, then the notes in order');
$ag = agent_post(42, '/incidents/resolve.php', ['incident' => $id, 'resolution' => 'fixed']);
ok($ag['code'] === 403, 'an agent may not resolve (people): 403');
$rid = (int) $warn[0];
ok(post_json(27, '/incidents/dismiss.php', ['incident' => $rid])['code'] === 422, 'a dismissal says why: refused without a reason');
$dm = post_json(27, '/incidents/dismiss.php', ['incident' => $rid, 'reason' => 'Returned orders are a status we keep; the check is too tight.']);
ok($dm['code'] === 200 && pg1("SELECT status || '|' || (resolved_at IS NOT NULL) FROM incidents WHERE id = $rid") === 'dismissed|true', 'an engineer dismisses the warning\'s incident, with the reason');
$rinc = open_incident($rg);
$rs = post_json(27, '/incidents/resolve.php', ['incident' => (int) $rinc[0], 'resolution' => 'The 9,999,999 order was a test; the source removes it.', 'root_cause' => 'A test order in production']);
ok($rs['code'] === 200 && pg1('SELECT status || \'|\' || root_cause FROM incidents WHERE id = ' . (int) $rinc[0]) === 'resolved|A test order in production', 'the owner resolves the range\'s incident with the root cause');

echo "4. A passing run resolves it, citing the result\n";
load_orders('orders_fix.csv');
[, $pj] = promote_as(27, 'curated.orders');
$res = pg1("SELECT status || '|' || (resolved_by_result_id IS NOT NULL) || '|' || resolution FROM incidents WHERE id = $id");
ok(str_starts_with((string) $res, 'resolved|true|A passing run resolved it (result '), 'SO-004 arrives with its amount — the promotion\'s not_null passes and resolves the incident, citing the result');
ok(isset(curated_rows()['SO-004']) && curated_rows()['SO-004']['amount'] === '61.00', 'SO-004 is in curated now (61.00)');
$auto = array_values(array_filter(activity('incident.resolve', $a0), static fn (array $x): bool => (int) $x['entity_id'] === $id));
ok(count($auto) === 1 && $auto[0]['source'] === 'system', 'incident.resolve logged by the writer (the database\'s resolution mirrored)');
$page = get_json(27, "/incidents/$id")['json']['data']['incident'] ?? [];
ok(($page['status'] ?? '') === 'resolved' && ($page['resolved_by']['passed'] ?? false) === true, 'the page names the passing result');
ok(count(activity('incident.ack', $a0)) === 1 && count(activity('incident.assign', $a0)) === 1 && count(activity('incident.note', $a0)) === 2
   && count(activity('incident.dismiss', $a0)) === 1, 'incident.ack / assign / note / dismiss logged');
$noteLog = json_decode((string) activity('incident.note', $a0)[0]['after'], true) ?: [];
ok(isset($noteLog['length']) && !isset($noteLog['body']), '…a note by its length, never its words');
finish();
