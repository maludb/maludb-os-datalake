<?php
/**
 * Slice 4, proofs 2 and 8 — access requests (access-policies.md "Proof" 2) and the worker's expiry pass (8): a request created, a second refused
 * ("a request already waits"), withdrawn; decided by the owner (approved at a LOWER level with an expiry → a grant, the requester told); the
 * requester's own decision refused; a denial without a reason refused; an agent's draft for the person it acts for (and refused for a duty, a
 * person, another person); a request older than 30 days expired, a grant ending within seven days announced ONCE, a past-due grant revoked.
 */
require __DIR__ . '/lib.php';
$payroll = ds_id('curated.payroll');     // the owner's (1)
$deals = ds_id('raw.crm_deals');         // Marco's (27)
$hr = ds_id('curated.hr_reviews');       // the owner's (1): no grant — a stub to Dana and to the Expert
pg("UPDATE access_requests SET status = 'withdrawn' WHERE status = 'pending'");    // a re-run starts clean
pg("UPDATE grants SET revoked_at = now() WHERE revoked_at IS NULL AND access_request_id IS NOT NULL");

echo "1. A request, a second refused, withdrawn\n";
$n0 = last_notice_id();
$a0 = last_activity_id();
$r = post_json(26, '/access/requests/save.php', ['dataset' => $payroll, 'level' => 'read_unmasked', 'reason' => 'I reconcile the commissions against payroll every month.']);
$rid = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $rid > 0 && pg1("SELECT status || '/' || level || '/' || requester_id FROM access_requests WHERE id = $rid") === 'pending/read_unmasked/26',
   'Priya asks to read payroll unmasked — a waiting request, hers');
$told = last_notice(1, 'access_requested', $n0);
ok($told !== null && str_contains($told[0], 'SMOKE Priya asks to read unmasked curated.payroll') && $told[1] === 'access_request' && (int) $told[2] === $rid, 'its owner is told (access_requested, the request linked)');
$log = activity('access.request', $a0)[0] ?? [];
ok(($log['dataset_id'] ?? null) == $payroll && !str_contains((string) ($log['after'] ?? ''), 'commissions') && str_contains((string) ($log['after'] ?? ''), '"reason_length"'),
   'logged access.request with the dataset — the reason by its length, never its words');
$again = post_json(26, '/access/requests/save.php', ['dataset' => $payroll, 'level' => 'read', 'reason' => 'Asking again.']);
ok($again['code'] === 422 && str_contains(message_of($again), 'A request already waits'), 'a second while one waits: refused — "A request already waits for this dataset"');
$form = get_json(26, '/access/requests/new?dataset=' . $payroll)['json']['data'] ?? [];
ok(($form['pending']['request_id'] ?? null) === $rid && ($form['dataset']['my_level'] ?? '') === 'discover', 'the form says a request already waits (and her level now: discover)');
ok(post_json(30, '/access/requests/withdraw.php', ['access_request' => $rid])['code'] === 404, 'Dana cannot withdraw Priya\'s request (not hers: nothing to her)');
$w = post_json(26, '/access/requests/withdraw.php', ['access_request' => $rid]);
ok($w['code'] === 200 && pg1("SELECT status FROM access_requests WHERE id = $rid") === 'withdrawn' && activity('access.withdraw', $a0) !== [], 'Priya withdraws it — withdrawn, logged');
$own = post_json(26, '/access/requests/save.php', ['dataset' => ds_id('product.monthly_revenue'), 'level' => 'read', 'reason' => 'Already mine.']);
ok($own['code'] === 422 && field_of($own) === 'level' && str_contains(message_of($own), 'You already read'), 'asking for a level one already has is refused on the level');
ok(post_json(26, '/access/requests/save.php', ['dataset' => $payroll, 'level' => 'read', 'reason' => 'x'])['code'] === 422, 'a reason under three characters is refused');

echo "2. Decided: the requester's own decision refused; a denial needs a reason; approved at a lower level with an expiry\n";
$r2 = post_json(26, '/access/requests/save.php', ['dataset' => $payroll, 'level' => 'read_unmasked', 'reason' => 'Commissions, monthly — I need the salaries.',
                                                  'wanted_until' => date('Y-m-d', time() + 20 * 86400)]);
$rid2 = (int) ($r2['json']['record_id'] ?? 0);
$imp = get_json(1, "/access/requests?impact=$rid2&level=read")['json']['data']['impact'] ?? [];
ok(($imp['level'] ?? '') === 'read' && ($imp['masked_count'] ?? 0) === 2 && (get_json(1, "/access/requests?impact=$rid2&level=read_unmasked")['json']['data']['impact']['masked_count'] ?? 9) === 0,
   'the impact line: approving read leaves 2 columns masked (the name, the salary); read unmasked none');
$box = get_json(1, '/access/requests?box=to_decide')['json']['data'] ?? [];
$mine = array_values(array_filter($box['requests'] ?? [], static fn ($x) => $x['request_id'] === $rid2))[0] ?? [];
ok(($box['to_decide'] ?? 0) >= 1 && ($mine['may_decide'] ?? false) === true && ($mine['requester_level'] ?? '') === 'discover', 'the owner\'s To decide holds it: may decide, the requester\'s level shown');
$deny = post_json(1, '/access/requests/decide.php', ['access_request' => $rid2, 'approve' => 'no']);
ok($deny['code'] === 422 && field_of($deny) === 'note', 'a denial without a reason: refused on the note — "A denial says why"');
$self = post_json(1, '/access/requests/save.php', ['dataset' => $deals, 'level' => 'read', 'reason' => 'The admin wants to read the deals.']);
$sid = (int) ($self['json']['record_id'] ?? 0);
$sd = post_json(1, '/access/requests/decide.php', ['access_request' => $sid, 'approve' => 'yes']);
ok($sid > 0 && $sd['code'] === 403 && str_contains(message_of($sd), 'someone other than its requester'), 'the admin (grants.manage) may not decide his OWN request: 403');
$md = post_json(27, '/access/requests/decide.php', ['access_request' => $sid, 'approve' => 'no', 'note' => 'Ask Sales for the summary instead.']);
ok($md['code'] === 200 && pg1("SELECT status FROM access_requests WHERE id = $sid") === 'denied' && last_notice(1, 'access_decided', $n0)[0] === 'Denied: raw.crm_deals',
   'Marco (the owner) denies it with a reason — the requester told "Denied: raw.crm_deals"');
ok(post_json(30, '/access/requests/decide.php', ['access_request' => $rid2, 'approve' => 'yes'])['code'] === 404, 'Dana (neither owner nor grants.manage) cannot even see it to decide: nothing to her (404)');
$until = date('Y-m-d', time() + 5 * 86400);
$ap = post_json(1, '/access/requests/decide.php', ['access_request' => $rid2, 'approve' => 'yes', 'level' => 'read', 'until' => $until, 'note' => 'Read, masked — salaries stay hidden.']);
$gid = (int) ($ap['json']['grant_id'] ?? 0);
ok($ap['code'] === 200 && $gid > 0 && pg1("SELECT level || '/' || principal_kind || '/' || principal_id || '/' || (expires_at::date = '$until'::date) FROM grants WHERE id = $gid") === 'read/member/26/true',
   'the owner approves at a LOWER level (read, not unmasked) until a date: a dataset grant to Priya, ending that day');
ok(pg1("SELECT status || '/' || grant_id FROM access_requests WHERE id = $rid2") === "approved/$gid" && last_notice(26, 'access_decided', $n0)[0] === 'Approved: you may read curated.payroll',
   'the request is approved with its grant; Priya is told "Approved: you may read curated.payroll"');
$pv = preview_as(26, $payroll);
ok(($pv['status'] ?? '') === 'ok' && $pv['rows'][0][col_index($pv, 'salary')] === null && in_array('member_name', $pv['masked_columns'] ?? [], true),
   'Priya\'s payroll cell turns to READ: rows, the names masked, the restricted salary empty');
$log = activity('access.decide', $a0);
ok(count($log) === 2 && !str_contains(implode(' ', array_column($log, 'after')), 'Ask Sales'), 'each decision logged access.decide — the note by its length');

echo "3. An agent drafts a request for the person it acts for\n";
$dr = agent_post(40, '/access/requests/draft.php', ['dataset' => $hr, 'level' => 'read', 'reason' => 'Dana asked me how the reviews went this year.'], 30);
$did = (int) ($dr['json']['record_id'] ?? 0);
ok($dr['code'] === 200 && pg1("SELECT requester_id || '/' || drafted_by_agent || '/' || status FROM access_requests WHERE id = $did") === '30/40/pending',
   'the Expert in Dana\'s chat turn drafts it: the request is Dana\'s, marked drafted by the Expert');
$dm = get_json(30, '/access/requests?box=mine')['json']['data']['requests'] ?? [];
$x = array_values(array_filter($dm, static fn ($q) => $q['request_id'] === $did))[0] ?? [];
ok(($x['drafted_by']['name'] ?? '') === pg1('SELECT display_name FROM members WHERE id = 40') && str_contains(html_as(30, '/access/requests?box=mine')['body'], 'id="request-card-' . $did . '-drafted"'),
   'Dana sees it under Mine, "Drafted by" the Expert');
ok(agent_post(40, '/access/requests/draft.php', ['dataset' => $deals, 'level' => 'read', 'reason' => 'A duty has no person.'])['code'] === 403, 'a duty (no person asked) drafts nothing: 403');
$other = agent_post(40, '/access/requests/draft.php', ['dataset' => $deals, 'level' => 'read', 'reason' => 'For someone else.', 'for' => 26], 30);
ok($other['code'] === 422 && field_of($other) === 'for', 'an agent drafts only for the person it acts for (`for` another: refused)');
ok(post_json(30, '/access/requests/draft.php', ['dataset' => $deals, 'level' => 'read', 'reason' => 'A person drafting.'])['code'] === 403, 'a person asks with the form — draft is the agent\'s: 403');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['access_decide']['approval'] ?? '') === 'external_send' && ($reg['actions']['access_request_draft']['agent'] ?? false) === true
   && ($reg['actions']['access_request_create']['agent'] ?? true) === false, 'an agent\'s decision pauses (external_send, the kernel\'s approval); create is people-only, draft the agent\'s');

echo "4. The worker's expiry pass\n";
pg("UPDATE access_requests SET created_at = now() - interval '31 days' WHERE id = $did");
pg("INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, expires_at, reason, granted_by) SELECT 'dataset', $deals, 'member', 30, 'discover', now() - interval '2 hours', 'SMOKE past due', 27");
$past = (int) pg1("SELECT max(id) FROM grants WHERE reason = 'SMOKE past due'");
$n1 = last_notice_id();
$a1 = last_activity_id();
$run = static fn (): array => json_decode((string) shell_exec('php bin/worker.php --passes=expiry 2>/dev/null'), true) ?: [];
$out = $run();
ok(($out['ran']['expiry']['expired_requests'] ?? 0) >= 1 && pg1("SELECT status FROM access_requests WHERE id = $did") === 'expired'
   && last_notice(30, 'access_decided', $n1)[0] === 'Your request for curated.hr_reviews expired', 'a request nobody decided in 30 days: expired, Dana told');
$ex = activity('access.expire', $a1);
ok(count($ex) >= 1 && ($ex[0]['source'] ?? '') === 'cron' && $ex[0]['actor_member_id'] === null, 'logged access.expire by the worker (source cron, no actor)');
ok(pg1("SELECT (revoked_at = expires_at)::text FROM grants WHERE id = $past") === 'true' && (int) pg1("SELECT count(*) FROM access_changes WHERE grant_id = $past AND new_level IS NULL") === 1,
   'a past-due grant is revoked at its expiry — the trail says so');
ok(last_notice(26, 'grant_expiring', $n1) !== null && last_notice(1, 'grant_expiring', $n1) !== null, 'Priya\'s grant ending in five days: she and the dataset\'s owner are told (grant_expiring)');
$run();
ok((int) pg1("SELECT count(*) FROM notifications WHERE kind = 'grant_expiring' AND record_id = $gid") === 2, '…ONCE: the next pass tells nobody again');
finish();
