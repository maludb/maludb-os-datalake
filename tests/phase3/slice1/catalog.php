<?php
/*
 * Proof: the catalog's datasets (catalog.md "Proof" 1, 3, 5, 6, 8, 10) — create from the form with the key's sentence on the field, raw and
 * sandbox refused, a retired key refused for ever; edit as the owner, the steward and an engineer (a Member who is neither: 403 in words),
 * "Metadata updated" moves and "Data updated" does not, a typed row count impossible; deprecate with a note and a successor (the card greyed,
 * "use {successor}", the followers told — the bell); retire a sandbox dataset as its owner; follow and unfollow; notes added and deleted by
 * their author (another Member 403, an engineer may); the worker's search_catch_up re-indexes a changed dataset and the search finds it.
 */
require __DIR__ . '/lib.php';
$ids = seed_catalog();
$t = run_tag();

echo "1. Create — the key, its sentence, raw and sandbox refused, a retired key for ever\n";
$name = 'slice1_orders_' . $t;
$r = post_json(27, '/datasets/save.php', ['zone' => 'curated', 'name' => $name, 'title' => 'SMOKE Slice 1 orders ' . $t, 'description' => 'Orders as the shop took them.',
    'owner' => 26, 'steward' => 30, 'department' => 8, 'tier' => '1', 'row_count' => '999']);
$id = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $r['json']['ok'] === true && $id > 0 && ($r['json']['location'] ?? '') === '/datasets/' . $id . '?notice=created', "an engineer registers curated.$name from the form: 200, record_id $id, location /datasets/$id");
$row = q('SELECT key, status, owner_member_id, steward_member_id, tier, row_count, created_by FROM datasets WHERE id = :i', ['i' => $id])[0] ?? [];
ok(($row['key'] ?? '') === "curated.$name" && $row['status'] === 'draft' && (int) $row['owner_member_id'] === 26 && (int) $row['steward_member_id'] === 30 && $row['tier'] === '1'
    && (int) $row['created_by'] === 27, 'its key is zone.name, a draft, owned by Priya, stewarded by Dana, tier 1, made by Marco');
ok($row['row_count'] === null, 'a forged row_count on the create is ignored (the form has no field; the guard nulls it on insert)');
$a = activity('dataset.create');
$last = end($a);
ok($last !== false && (int) $last['dataset_id'] === $id && json_decode((string) $last['after'], true)['key'] === "curated.$name", 'logged dataset.create with dataset_id and the key');
$r = post_json(27, '/datasets/save.php', ['zone' => 'curated', 'name' => $name, 'title' => 'Again']);
ok($r['code'] === 422 && field_of($r) === 'name' && str_contains(message_of($r), "curated.$name is taken — choose another name"), 'the same key again: 422 on the name field, the database\'s sentence — "' . message_of($r) . '"');
foreach (['raw', 'sandbox'] as $z) {
    $r = post_json(27, '/datasets/save.php', ['zone' => $z, 'name' => 'nope_' . $t, 'title' => 'Nope']);
    ok($r['code'] === 422 && field_of($r) === 'zone' && message_of($r) === 'A raw dataset is made by a load; a sandbox one by its owner', "$z refused on the zone field with the divergence's sentence");
}
$r = post_json(27, '/datasets/save.php', ['zone' => 'curated', 'name' => 'Bad-Name', 'title' => 'x']);
ok($r['code'] === 422 && field_of($r) === 'name', 'a name that is not lowercase letters, digits and underscores: 422 on name');
$r = post_json(26, '/datasets/save.php', ['zone' => 'curated', 'name' => 'mine_' . $t, 'title' => 'x']);
ok($r['code'] === 403 && message_of($r) === 'You may not edit the catalog.', 'a Member may not register a dataset: 403 "You may not edit the catalog."');
$gone = 'slice1_gone_' . $t;
$g = (int) (post_json(27, '/datasets/save.php', ['zone' => 'product', 'name' => $gone, 'title' => 'SMOKE gone ' . $t])['json']['record_id'] ?? 0);
$r = post_json(27, '/datasets/retire.php', ['dataset' => $g]);
ok($r['code'] === 200 && pg1("SELECT status FROM datasets WHERE id = $g") === 'retired', 'an engineer retires a product dataset');
$r = post_json(27, '/datasets/save.php', ['zone' => 'product', 'name' => $gone, 'title' => 'Again']);
ok($r['code'] === 422 && field_of($r) === 'name' && str_contains(message_of($r), 'belonged to a retired dataset — a key is never reused'), 'a retired key is refused for ever: "' . message_of($r) . '"');
$h = html_as(27, "/datasets/new?key_preview=1&zone=product&name=$gone", ['HX-Request: true'])['body'];
ok(str_contains($h, 'id="dataset-form-key-preview"') && str_contains($h, 'never reused'), 'the form\'s key preview says so as the name is typed');
ok(str_contains(html_as(27, "/datasets/new?key_preview=1&zone=curated&name=fresh_$t", ['HX-Request: true'])['body'], "curated.fresh_$t</span> — free"), '…and that a fresh key is free');
ok(html_as(27, "/datasets/$g")['code'] === 404, 'a retired dataset is nothing to anyone (404)');

echo "2. The page by id and by key\n";
$byId = html_as(26, "/datasets/$id");
$byKey = html_as(26, "/datasets/curated.$name");
ok($byId['code'] === 200 && $byKey['code'] === 200 && str_contains($byKey['body'], 'id="dataset-header"') && str_contains($byKey['body'], 'data-dataset-key="curated.' . $name . '"'), 'the page answers by id and by key, the key stamped on the header');
$tabs = ['about' => true, 'columns' => true, 'preview' => 'slice 2', 'quality' => 'slice 5', 'lineage' => 'slice 7', 'usage' => true, 'access' => 'slice 4', 'history' => true];
foreach ($tabs as $tab => $pending) {             // a later slice that built its tab replaced tab-pending.php: the suite follows what is still owed
    if ($pending !== true && !str_contains((string) file_get_contents("app/views/datasets/partials/tab-$tab.php"), "'datasets/partials/tab-pending.php'")) {
        $tabs[$tab] = true;
    }
}
$bad = [];
foreach ($tabs as $tab => $pending) {
    if (!str_contains($byId['body'], 'id="dataset-tab-' . $tab . '"') || ($pending !== true && !str_contains($byId['body'], 'id="dataset-tab-' . $tab . '-pending"'))
        || ($pending !== true && !preg_match('/id="dataset-tab-' . $tab . '-pending".*?Built by ' . $pending . '/s', $byId['body'])) || ($pending === true && str_contains($byId['body'], 'id="dataset-tab-' . $tab . '-pending"'))) {
        $bad[] = $tab;
    }
}
ok($bad === [], 'the eight tabs render — the built ones whole, those still owed saying "Built by slice N" (' . implode(', ', array_keys(array_filter($tabs, static fn ($p) => $p !== true))) . ')' . ($bad ? ' — wrong: ' . implode(', ', $bad) : ''));
ok(preg_match('/class="tab-pane fade show active" id="dataset-tab-about"/', $byId['body']) === 1 && preg_match('/class="tab-pane fade show active" id="dataset-tab-history"/', html_as(26, "/datasets/$id?tab=history")['body']) === 1
    && preg_match('/class="tab-pane fade show active" id="dataset-tab-quality"/', html_as(26, "/datasets/curated.$name/quality")['body']) === 1, '?tab= chooses the pane (About by default; /datasets/{key}/{tab} too)');
pg("UPDATE datasets SET certification_lost_reason = 'A blocking check failed', certification_lost_at = now() - interval '1 day' WHERE id = $id");
$lost = html_as(26, "/datasets/$id")['body'];
ok(str_contains($lost, 'id="dataset-header-certification-lost"') && str_contains($lost, 'Certification lost: A blocking check failed')
    && (get_json(26, "/datasets/$id")['json']['data']['dataset']['certification_lost']['reason'] ?? '') === 'A blocking check failed', 'a certification lost a day ago: the header says "Certification lost: {reason}" (db/020 carries the date)');
pg("UPDATE datasets SET certification_lost_at = now() - interval '31 days' WHERE id = $id");
ok(!str_contains(html_as(26, "/datasets/$id")['body'], 'dataset-header-certification-lost'), '…and stops saying it after 30 days');
$v = (int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen = 'dataset-view' AND dataset_id = :i", ['i' => $id]);
ok($v >= 6, "every view of the page logs screen.view with its dataset_id ($v)");

echo "3. Edit — the owner, the steward, an engineer; a Member who is neither\n";
pg("UPDATE datasets SET data_updated_at = '2026-01-02 03:04:05+00' WHERE id = $id");
pg("UPDATE datasets SET metadata_updated_at = now() - interval '2 days' WHERE id = $id");
$before = q('SELECT data_updated_at, metadata_updated_at FROM datasets WHERE id = :i', ['i' => $id])[0];
$r = post_json(26, '/datasets/save.php', ['dataset' => $id, 'purpose' => 'For the monthly close.', 'row_count' => '42', 'data_updated_at' => '2020-01-01']);
$after = q('SELECT data_updated_at, metadata_updated_at, purpose, row_count FROM datasets WHERE id = :i', ['i' => $id])[0];
ok($r['code'] === 200 && $after['purpose'] === 'For the monthly close.', 'the owner (a Member) describes it: 200');
ok($after['metadata_updated_at'] > $before['metadata_updated_at'] && $after['data_updated_at'] === $before['data_updated_at'], '"Metadata updated" moved; "Data updated" did not');
ok($after['row_count'] === null, 'a forged row_count and data_updated_at are ignored by the handler');
$h = html_as(26, "/datasets/$id/edit")['body'];
ok(!str_contains($h, 'name="row_count"') && !str_contains($h, 'name="byte_size"') && !str_contains($h, 'name="data_updated_at"'), 'the edit form has no field for technical metadata');
try {
    as_member(26);
    pdo()->exec("UPDATE datasets SET row_count = 5 WHERE id = $id");
    ok(false, 'the database refuses a typed row count (it did NOT)');
} catch (PDOException $e) {
    ok($e->getCode() === '42501' && str_contains($e->getMessage(), 'Technical metadata is the engine'), 'the database refuses a typed row count from the application\'s role: "Technical metadata is the engine\'s — …"');
}
$r = post_json(30, '/datasets/save.php', ['dataset' => "curated.$name", 'usage' => 'Join on order_id.', 'freshness_warn_after' => '6 hours', 'freshness_error_after' => '2 days', 'evolution_mode' => 'add']);
$row = q("SELECT usage, EXTRACT(epoch FROM freshness_warn_after) AS w, EXTRACT(epoch FROM freshness_error_after) AS e, evolution_mode FROM datasets WHERE id = :i", ['i' => $id])[0];
ok($r['code'] === 200 && $row['usage'] === 'Join on order_id.' && (int) $row['w'] === 21600 && (int) $row['e'] === 172800 && $row['evolution_mode'] === 'add',
    'the steward (an Analyst) by key: usage, freshness 6 hours / 2 days, evolution add');
$r = post_json(30, '/datasets/save.php', ['dataset' => $id, 'freshness_warn_after' => '3 days', 'freshness_error_after' => '1 day']);
ok($r['code'] === 422 && field_of($r) === 'freshness_error_after', 'fail-after shorter than warn-after: 422 on the field');
$r = post_json(27, '/datasets/save.php', ['dataset' => $id, 'freshness_warn_after_n' => '12', 'freshness_warn_after_unit' => 'hours', 'tier' => '2', 'status' => 'active']);
$row = q('SELECT EXTRACT(epoch FROM freshness_warn_after) AS w, tier, status FROM datasets WHERE id = :i', ['i' => $id])[0];
ok($r['code'] === 200 && (int) $row['w'] === 43200 && $row['tier'] === '2' && $row['status'] === 'active', 'an engineer (neither owner nor steward): the form\'s number + unit, tier 2, active');
$l = activity('dataset.update');
$lu = json_decode((string) end($l)['after'], true);
ok(isset($lu['freshness_warn_after'], $lu['tier'], $lu['status']) && !isset($lu['title']) && (int) end($l)['dataset_id'] === $id, 'logged dataset.update with only the changed fields and the dataset_id');
$r = post_json(31, '/datasets/save.php', ['dataset' => $id, 'title' => 'Mine now']);
ok($r['code'] === 403 && message_of($r) === "Only the owner of curated.$name, its steward or a data engineer may change its description.", 'a Member who is neither: 403 — "' . message_of($r) . '"');
ok(html_as(31, "/datasets/$id/edit")['code'] === 403 && html_as(31, "/datasets/$id")['code'] === 200, '…the edit form 403 to them, the page itself 200');
$long = str_repeat('Long words. ', 40);
post_json(27, '/datasets/save.php', ['dataset' => $id, 'description' => $long]);
$l = activity('dataset.update');
$lu = json_decode((string) end($l)['after'], true);
ok(($lu['description']['length'] ?? 0) === mb_strlen(trim($long)) && !str_contains((string) end($l)['after'], 'Long words. Long words. Long words. Long words. Long words. Long words. Long words. Long words. Long words. Long words. Long words. Long words. Long words. Long words. Long words. Long words. Long words.'),
    'a description over 200 characters is logged by its length, never its text');
ok(pg1("SELECT description_source FROM datasets WHERE id = $id") === 'human', 'a description a person writes is theirs (description_source human)');

echo "5. Deprecate, retire, and an agent's retire\n";
$succ = $ids['product.monthly_revenue'];
post_json(26, '/datasets/follow.php', ['dataset' => $id]);
$bell = static fn (): int => preg_match('/id="bell-count">(\d+)</', html_as(26, '/')['body'], $m) ? (int) $m[1] : 0;
$b0 = $bell();
$r = post_json(31, '/datasets/deprecate.php', ['dataset' => $id, 'note' => 'Use the monthly revenue.']);
ok($r['code'] === 403, 'a Member who is not the owner may not deprecate: 403');
$r = post_json(27, '/datasets/deprecate.php', ['dataset' => $id, 'note' => 'Replaced by the monthly revenue product.', 'successor' => $succ]);
$row = q('SELECT status, deprecation_note, successor_id FROM datasets WHERE id = :i', ['i' => $id])[0];
ok($r['code'] === 200 && $row['status'] === 'deprecated' && (int) $row['successor_id'] === $succ && ($r['json']['followers_told'] ?? -1) === 1, 'an engineer deprecates it with a note and a successor; the one follower is told');
ok($bell() === $b0 + 1 && (int) one("SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'deprecated' AND dataset_id = :i", ['i' => $id]) === 1, 'the follower\'s notice (dl_notify) reaches the bell');
$card = html_as(26, '/browse?status=deprecated&q=' . rawurlencode($name))['body'];
ok(preg_match('/class="col is-deprecated">\s*<div class="card dataset-card mb-2" id="browse-card-' . $id . '"/', $card) === 1 && str_contains($card, 'id="browse-card-' . $id . '-successor"') && str_contains($card, 'product.monthly_revenue'),
    'its card is greyed with "use product.monthly_revenue"');
ok(str_contains(html_as(26, "/datasets/$id")['body'], 'id="dataset-deprecation"'), 'the About tab says it is deprecated and names the successor');
$r = post_json(27, '/datasets/deprecate.php', ['dataset' => $id, 'note' => 'x', 'successor' => $id]);
ok($r['code'] === 422 && field_of($r) === 'successor', 'a dataset is not its own successor (422 on successor)');
$r = post_json(27, '/datasets/deprecate.php', ['dataset' => $id, 'note' => 'x', 'successor' => $ids['curated.payroll']]);
ok($r['code'] === 422 && field_of($r) === 'successor', 'a successor the engineer may not discover: 422 — the payroll is not offered');
$r = post_json(26, '/datasets/save.php', ['dataset' => $id, 'status' => 'active']);
$row = q('SELECT status, deprecation_note, successor_id FROM datasets WHERE id = :i', ['i' => $id])[0];
ok($r['code'] === 200 && $row['status'] === 'active' && $row['deprecation_note'] === null && $row['successor_id'] === null, 'dataset_update status active brings it back (the undo): the note and the successor cleared');
$sb = 'm26_slice1_' . $t;
pg("INSERT INTO datasets (key, zone, name, title, owner_member_id, status) VALUES ('sandbox.$sb', 'sandbox', '$sb', 'SMOKE Priya scratch $t', 26, 'active')");
$sid = ds_id("sandbox.$sb");
ok(post_json(27, '/datasets/retire.php', ['dataset' => $sid])['code'] === 404, 'an engineer cannot even see Priya\'s sandbox dataset (404)');
$r = post_json(26, '/datasets/retire.php', ['dataset' => $sid]);
ok($r['code'] === 200 && pg1("SELECT status FROM datasets WHERE id = $sid") === 'retired' && $r['json']['location'] === '/browse?owner=me&notice=retired', 'Priya retires her own sandbox dataset');
$a = activity('dataset.retire');
ok((int) end($a)['dataset_id'] === $sid, 'logged dataset.retire with its dataset_id');
$r = post_json(26, '/datasets/retire.php', ['dataset' => $ids['curated.sales_orders']]);
ok($r['code'] === 403 && message_of($r) === 'Only a data engineer retires curated.sales_orders.', 'a Member may not retire a curated dataset: 403 in words');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['dataset_retire']['approval'] ?? null) === 'deletion' && ($reg['actions']['dataset_retire']['log_event'] ?? '') === 'dataset.retire',
    'an agent\'s dataset_retire pauses: the registry gives it the deletion category on its own log event dataset.retire');
$cats = [];
foreach (json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['approvals'] ?? [] as $ap) { $cats[$ap['action'] ?? ''] = $ap['category'] ?? null; }
ok(($cats['dataset_retire'] ?? null) === 'deletion' && !isset($cats['dataset_deprecate']) && !isset($cats['dataset_tag']), '…and maludb-os.json approvals[] pauses it (the kernel\'s hook); deprecate and tag stay free');

echo "6. Follow and unfollow\n";
$r = post_json(26, '/datasets/follow.php', ['dataset' => $ids['curated.sales_orders'], 'what' => 'deprecated,schema_changed']);
ok($r['code'] === 200 && pg1("SELECT array_to_string(what, ',') FROM dataset_follows WHERE member_id = 26 AND dataset_id = {$ids['curated.sales_orders']}") === 'deprecated,schema_changed', 'Priya follows the orders for two kinds');
ok(str_contains(html_as(26, '/datasets/curated.sales_orders')['body'], '>Unfollow</button>') && str_contains(html_as(26, '/')['body'], 'id="home-recent-' . $ids['curated.sales_orders'] . '"'), 'the button reads Unfollow; the home\'s tile lists it');
$r = post_json(26, '/datasets/follow.php', ['dataset' => $ids['curated.sales_orders'], 'what' => 'gossip']);
ok($r['code'] === 422 && field_of($r) === 'what', 'an unknown kind: 422');
ok(post_json(29, '/datasets/follow.php', ['dataset' => $ids['curated.sales_orders']])['code'] === 404, 'an external may not follow what they may not discover (404)');
ok(post_json(26, '/datasets/follow.php', ['dataset' => $ids['curated.payroll']])['code'] === 404, 'nobody follows a stub (404 — the same nothing)');
$r = post_json(26, '/datasets/unfollow.php', ['dataset' => $ids['curated.sales_orders']]);
ok($r['code'] === 200 && pg1("SELECT count(*) FROM dataset_follows WHERE member_id = 26 AND dataset_id = {$ids['curated.sales_orders']}") === '0', 'and unfollows');
ok(count(activity('dataset.follow')) >= 2 && count(activity('dataset.unfollow')) >= 1, 'logged dataset.follow and dataset.unfollow');

echo "8. Notes\n";
$r = post_json(26, '/notes/add.php', ['kind' => 'dataset', 'record' => $id, 'body' => "Region was blank before March — {$t}."]);
$nid = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $nid > 0 && str_contains(html_as(26, "/datasets/$id")['body'], 'id="note-' . $nid . '"'), 'Priya adds a note; it shows in the panel');
$a = activity('note.add');
$la = end($a);
ok((int) $la['dataset_id'] === $id && !str_contains((string) $la['after'], 'blank before March') && (json_decode((string) $la['after'], true)['length'] ?? 0) > 10, 'logged note.add with the dataset_id and the length — never the body');
ok(post_json(26, '/notes/add.php', ['kind' => 'dataset', 'record' => $ids['curated.payroll'], 'body' => 'x'])['code'] === 404, 'no note on a stub (404)');
ok(post_json(26, '/notes/add.php', ['kind' => 'dataset', 'record' => $id, 'body' => ''])['code'] === 422, 'an empty note: 422');
$r = post_json(31, '/notes/delete.php', ['note' => $nid]);
ok($r['code'] === 403 && message_of($r) === "Only the note's author or a data engineer may delete it.", 'another Member may not delete it: 403 in words');
ok(post_json(26, '/notes/delete.php', ['note' => $nid])['code'] === 200 && pg1("SELECT count(*) FROM notes WHERE id = $nid") === '0', 'its author deletes it');
$nid2 = (int) (post_json(30, '/notes/add.php', ['kind' => 'dataset', 'record' => $id, 'body' => 'Dana\'s note'])['json']['record_id'] ?? 0);
ok(post_json(27, '/notes/delete.php', ['note' => $nid2])['code'] === 200, 'an engineer deletes another\'s note');
ok(post_json(29, '/notes/delete.php', ['note' => 999999999])['code'] === 404, 'a note one may not see is nothing (404)');

echo "10. The worker's search_catch_up\n";
$fresh = 'Zanzibar ledger ' . $t;
pg("SET session_replication_role = replica; UPDATE datasets SET title = 'SMOKE $fresh', metadata_updated_at = now() WHERE id = $id;");
$found = static fn (): bool => in_array($id, array_map(static fn (array $r): int => (int) $r['entity_id'], get_json(26, '/search?q=' . rawurlencode('zanzibar ' . $t))['json']['data']['rows'] ?? []), true);
ok(!$found(), 'a change the triggers did not see (the writer\'s, or with triggers off) is not in the index yet');
exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=search_catch_up 2>&1', $out, $rc);
$res = json_decode((string) end($out), true) ?: [];
ok($rc === 0 && ($res['ran']['search_catch_up']['indexed'] ?? 0) >= 1, 'the worker\'s search_catch_up re-indexes it: ' . (string) end($out));
ok($found(), 'and the search box finds it by the new title');
finish();
