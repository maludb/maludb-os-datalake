<?php
/*
 * Proof: tags (catalog.md "Proof" 4) — tagging a dataset with new words makes a FREE tag, the count follows, untag; the vocabulary page;
 * a managed tag made, changed and archived — archiving one that carries a grant warns that its grants stay until revoked (a fixture grant);
 * an archived tag is never applied again and leaves the picker.
 */
require __DIR__ . '/lib.php';
$ids = seed_catalog();
$t = run_tag();
$orders = $ids['curated.sales_orders'];

echo "4. Tags\n";
$r = post_json(27, '/datasets/tag.php', ['dataset' => $orders, 'tag' => 'Quarter End ' . $t]);
$slug = 'quarter-end-' . $t;
$tag = q('SELECT id, label, kind, usage_count FROM tags WHERE slug = :s', ['s' => $slug])[0] ?? null;
ok($r['code'] === 200 && $tag !== null && $tag['kind'] === 'free' && $tag['label'] === 'Quarter End ' . $t && (int) $tag['usage_count'] === 1, "tagging with new words makes a FREE tag #$slug labelled as typed, used once");
ok(in_array($slug, tags_of($orders), true) && str_contains(html_as(26, "/datasets/$orders")['body'], 'id="tag-chip-' . $slug . '"'), 'the dataset carries it; its About shows the chip');
$a = activity('dataset.tag');
ok((int) end($a)['dataset_id'] === $orders && json_decode((string) end($a)['after'], true)['tag'] === $slug, 'logged dataset.tag with the dataset_id and the slug');
$r = post_json(27, '/datasets/tag.php', ['dataset' => $orders, 'tag' => $slug]);
ok($r['code'] === 200 && (int) pg1("SELECT usage_count FROM tags WHERE slug = '$slug'") === 1, 'tagging again is a no-op (the count stays 1)');
post_json(27, '/datasets/tag.php', ['dataset' => $ids['product.monthly_revenue'], 'tag' => $slug]);
ok((int) pg1("SELECT usage_count FROM tags WHERE slug = '$slug'") === 2 && str_contains(html_as(26, '/browse')['body'], 'id="browse-facet-tag-' . $slug . '"'), 'a second dataset: the count is 2; Browse offers the tag as a way in');
ok(str_contains(html_as(26, '/browse?tag=' . $slug)['body'], 'id="browse-card-' . $orders . '"') && !str_contains(html_as(26, '/browse?tag=' . $slug)['body'], 'id="browse-card-' . $ids['curated.payroll'] . '"'), 'browsing by the tag lists its datasets');
$r = post_json(26, '/datasets/tag.php', ['dataset' => $orders, 'tag' => 'mine']);
ok($r['code'] === 403, 'a Member who is neither owner nor steward may not tag: 403');
ok(post_json(27, '/datasets/tag.php', ['dataset' => $orders, 'tag' => '!!!'])['code'] === 422, 'words that make no slug: 422');
$r = post_json(27, '/datasets/untag.php', ['dataset' => $orders, 'tag' => $slug]);
ok($r['code'] === 200 && (int) pg1("SELECT usage_count FROM tags WHERE slug = '$slug'") === 1 && !in_array($slug, tags_of($orders), true), 'untag: the dataset no longer carries it; the count is 1');
ok(count(activity('dataset.untag')) >= 1, 'logged dataset.untag');

echo "The vocabulary — make, change, archive\n";
$r = post_json(27, '/tags/save.php', ['label' => 'Finance core ' . $t, 'colour' => '#1f77b4']);
$fin = (int) ($r['json']['record_id'] ?? 0);
$row = q('SELECT slug, kind, colour FROM tags WHERE id = :i', ['i' => $fin])[0] ?? [];
ok($r['code'] === 200 && ($row['slug'] ?? '') === 'finance-core-' . $t && $row['kind'] === 'managed' && $row['colour'] === '#1f77b4', 'an engineer makes a managed tag; its slug from the label');
ok(post_json(27, '/tags/save.php', ['label' => 'Finance core ' . $t])['code'] === 422, 'the same slug again: 422');
ok(post_json(26, '/tags/save.php', ['label' => 'Nope ' . $t])['code'] === 403, 'a Member may not make a tag: 403');
$r = post_json(27, '/tags/save.php', ['tag' => $fin, 'label' => 'Finance — core ' . $t, 'kind' => 'managed', 'colour' => 'red']);
ok($r['code'] === 422 && field_of($r) === 'colour', 'a colour that is not #rrggbb: 422 on colour');
$r = post_json(27, '/tags/save.php', ['tag' => $fin, 'label' => 'Finance — core ' . $t]);
ok($r['code'] === 200 && pg1("SELECT label FROM tags WHERE id = $fin") === 'Finance — core ' . $t && pg1("SELECT slug FROM tags WHERE id = $fin") === 'finance-core-' . $t, 'tag_update changes the label; the slug never changes');
$page = html_as(26, '/tags')['body'];
ok(str_contains($page, 'id="tags-managed"') && str_contains($page, 'id="tag-chip-finance-core-' . $t . '"') && str_contains($page, 'id="tag-chip-' . $slug . '"') && !str_contains($page, 'id="tag-form"'),
    'the tags page: managed and free chips with their counts; no form for a Member');
post_json(27, '/datasets/tag.php', ['dataset' => $orders, 'tag' => $fin]);
pg("INSERT INTO grants (target_kind, tag_id, principal_kind, level, reason, granted_by) VALUES ('tag', $fin, 'everyone', 'read', 'SMOKE the finance tag', 1)");
$h = html_as(27, "/tags?edit=$fin")['body'];
ok(str_contains($h, 'id="tag-archive-warning"') && str_contains($h, 'Grants on this tag stay until revoked'), 'the archive button of a tag carrying a grant warns: grants on this tag stay until revoked');
$r = post_json(27, '/tags/archive.php', ['tag' => $fin]);
ok($r['code'] === 200 && ($r['json']['grants_kept'] ?? 0) === 1 && str_contains((string) ($r['json']['did'] ?? ''), 'grants on this tag stay until revoked'), 'archived — the answer says its one grant stays');
ok(pg1("SELECT archived_at IS NOT NULL FROM tags WHERE id = $fin") === 't' && pg1("SELECT count(*) FROM grants WHERE tag_id = $fin AND revoked_at IS NULL") === '1', 'the tag is archived; the grant is not touched');
$a = activity('tag.archive');
ok((json_decode((string) end($a)['after'], true)['grants_kept'] ?? -1) === 1, 'logged tag.archive with grants_kept');
$r = post_json(27, '/datasets/tag.php', ['dataset' => $ids['product.monthly_revenue'], 'tag' => 'finance-core-' . $t]);
ok($r['code'] === 422 && str_contains(message_of($r), 'is archived'), 'an archived tag is never applied again: 422 in words');
ok(!str_contains(html_as(27, '/pick/?source=tag&q=' . rawurlencode('finance'))['body'], 'core ' . $t), 'it leaves the tag picker');
ok(in_array('finance-core-' . $t, tags_of($orders), true), 'the datasets that carry it keep it');
pg("UPDATE grants SET revoked_at = now(), revoked_by = 1 WHERE tag_id = $fin AND revoked_at IS NULL");
ok(post_json(27, '/tags/archive.php', ['tag' => $fin])['code'] === 422, 'archiving it again: 422');
finish();
