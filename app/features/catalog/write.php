<?php
declare(strict_types=1);

/**
 * The catalog's writes (slice 1: dataset_create, dataset_update, dataset_tag, dataset_untag, dataset_deprecate, dataset_retire,
 * dataset_follow, dataset_unfollow, column_update). Every function changes rows and nothing else — the handler has checked the right and
 * the level, logs and lands. THE DATABASE IS THE REFEREE: the key's form and its uniqueness for ever (db/007's CHECK and index; db/020's dl_dataset_key_taken() in words), the
 * sandbox name rule, the zone and name never changing, the derived classification and personal flag, technical metadata the writer's alone
 * (a forged row count is ignored here and refused by dl_datasets_guard if ever sent), "metadata updated" moved by the trigger, a column's
 * name and type the engine's (dl_dataset_columns_guard), the certification's automatic loss. The writer is never called here: the catalog
 * is PostgreSQL's.
 */

/** The fields a person may set on a dataset (dataset_update) — and never the engine's (row_count, byte_size, data_updated_at…). */
const DATASET_EDITABLE = ['title', 'description', 'purpose', 'usage', 'limitations', 'owner_member_id', 'steward_member_id', 'department_id', 'tier',
    'freshness_warn_after', 'freshness_error_after', 'evolution_mode', 'status'];

/**
 * Create (id null: $f = zone, name, title, description?, owner_member_id?, steward_member_id?, department_id?, tier?) or change (the keys of $f
 * among DATASET_EDITABLE; an interval as text — '6 hours'). A description a person writes is theirs (description_source 'human'). Leaving
 * `deprecated` for draft or active clears the deprecation note and successor. Returns the id.
 */
function dataset_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO datasets (key, zone, name, title, description, owner_member_id, steward_member_id, department_id, tier, created_by)
                             VALUES (:key, :zone, :name, :title, :description, :owner, :steward, :dept, :tier, :by) RETURNING id');
        $st->execute(['key' => $f['zone'] . '.' . $f['name'], 'zone' => $f['zone'], 'name' => $f['name'], 'title' => $f['title'], 'description' => $f['description'] ?? null,
                      'owner' => $f['owner_member_id'] ?? null, 'steward' => $f['steward_member_id'] ?? null, 'dept' => $f['department_id'] ?? null,
                      'tier' => $f['tier'] ?? '2', 'by' => $by]);
        return (int) $st->fetchColumn();
    }
    $f = array_intersect_key($f, array_flip(DATASET_EDITABLE));
    if ($f === []) {
        return $id;
    }
    $sets = [];
    $args = ['id' => $id];
    foreach ($f as $k => $v) {
        $sets[] = in_array($k, ['freshness_warn_after', 'freshness_error_after'], true) ? "$k = CAST(:$k AS interval)" : "$k = :$k";
        $args[$k] = $v;
    }
    if (array_key_exists('description', $f)) {
        $sets[] = "description_source = 'human'";
    }
    if (isset($f['status']) && $f['status'] !== 'deprecated') {
        $sets[] = "deprecation_note = CASE WHEN status = 'deprecated' THEN NULL ELSE deprecation_note END";
        $sets[] = "successor_id = CASE WHEN status = 'deprecated' THEN NULL ELSE successor_id END";
    }
    $pdo->prepare('UPDATE datasets SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args);
    return $id;
}

/** Put a tag on a dataset or take it off ($on). A new slug makes a FREE tag (tag_ensure). Returns [tag_id, slug, label, changed]. */
function dataset_set_tag(PDO $pdo, int $id, string $slug, bool $on, int $by): array
{
    if ($on) {
        [$tagId, $s, $label] = tag_ensure($pdo, $slug, $by);
        $st = $pdo->prepare("INSERT INTO record_tags (record_type, record_id, tag_id, added_by) VALUES ('dataset', :d, :t, :by) ON CONFLICT DO NOTHING");
        $st->execute(['d' => $id, 't' => $tagId, 'by' => $by]);
        return [$tagId, $s, $label, $st->rowCount() > 0];
    }
    $t = find_tag($pdo, ctype_digit($slug) ? $slug : tag_slug(ltrim($slug, '#'))) ?? throw new DomainException('There is no tag ' . mb_substr($slug, 0, 50) . '.');
    $st = $pdo->prepare("DELETE FROM record_tags WHERE record_type = 'dataset' AND record_id = :d AND tag_id = :t");
    $st->execute(['d' => $id, 't' => (int) $t['tag_id']]);
    return [(int) $t['tag_id'], (string) $t['slug'], (string) $t['label'], $st->rowCount() > 0];
}

/** Deprecate: the note (why, and what to use instead) and the successor; status deprecated. */
function dataset_deprecate(PDO $pdo, int $id, string $note, ?int $successor): void
{
    $pdo->prepare("UPDATE datasets SET status = 'deprecated', deprecation_note = :n, successor_id = :s WHERE id = :id")
        ->execute(['n' => $note, 's' => $successor, 'id' => $id]);
}

/** Retire: the entry stays (its key is never reused), it leaves the catalog for everyone (db/008: a retired dataset gives no level). */
function dataset_retire(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE datasets SET status = 'retired' WHERE id = :id AND status <> 'retired'")->execute(['id' => $id]);
}

/** Follow (or change what one hears about): $what ⊆ FOLLOW_KINDS. */
function dataset_follow(PDO $pdo, int $id, int $member, array $what): void
{
    $pdo->prepare('INSERT INTO dataset_follows (member_id, dataset_id, what) VALUES (:m, :d, CAST(:w AS text[]))
                   ON CONFLICT (member_id, dataset_id) DO UPDATE SET what = EXCLUDED.what')
        ->execute(['m' => $member, 'd' => $id, 'w' => text_array_literal($what)]);
}

/** Stop following. */
function dataset_unfollow(PDO $pdo, int $id, int $member): void
{
    $pdo->prepare('DELETE FROM dataset_follows WHERE member_id = :m AND dataset_id = :d')->execute(['m' => $member, 'd' => $id]);
}

/**
 * Tell the dataset's followers who asked to hear $kind (dl_notify applies the wall per follower and their preferences; the actor is not
 * told of their own act). Returns how many notices were made.
 */
function notify_followers(PDO $pdo, int $datasetId, string $kind, string $title, ?string $body, int $actor): int
{
    $st = $pdo->prepare("SELECT count(dl_notify(f.member_id, :k, :t, :b, 'dataset', :d, :d, :actor, NULL)) FROM dataset_follows f
                          WHERE f.dataset_id = :d AND :k = ANY (f.what) AND f.member_id <> :actor");
    $st->execute(['k' => $kind, 't' => $title, 'b' => $body, 'd' => $datasetId, 'actor' => $actor]);
    return (int) $st->fetchColumn();
}

/** The fields a person may set on a column (column_update) — never its name, type, classification or personal kind (catalog.md divergence 3). */
const COLUMN_EDITABLE = ['business_name', 'description', 'glossary_term_id', 'critical', 'references_dataset_id', 'references_column'];

/** Change a column's meaning: the keys of $f among COLUMN_EDITABLE. A description a person writes is theirs. */
function column_save(PDO $pdo, int $columnId, array $f): void
{
    $f = array_intersect_key($f, array_flip(COLUMN_EDITABLE));
    if ($f === []) {
        return;
    }
    $sets = [];
    $args = ['id' => $columnId];
    foreach ($f as $k => $v) {
        $sets[] = "$k = :$k";
        $args[$k] = is_bool($v) ? ($v ? 't' : 'f') : $v;
    }
    if (array_key_exists('description', $f)) {
        $sets[] = "description_source = 'human'";
    }
    $pdo->prepare('UPDATE dataset_columns SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args);
}

/** A column's current editable fields (the before of a diff). */
function column_current(PDO $pdo, int $columnId): array
{
    $st = $pdo->prepare('SELECT business_name, description, glossary_term_id, critical, references_dataset_id, references_column FROM mcp_dataset_columns WHERE column_id = :id');
    $st->execute(['id' => $columnId]);
    $r = $st->fetch() ?: [];
    foreach (['glossary_term_id', 'references_dataset_id'] as $k) {
        $r[$k] = isset($r[$k]) ? (int) $r[$k] : null;
    }
    $r['critical'] = (bool) ($r['critical'] ?? false);
    return $r;
}

/** A dataset's current editable fields (the before of a diff), intervals as text in words. */
function dataset_current(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT title, description, purpose, usage, limitations, owner_member_id, steward_member_id, department_id, tier, status, evolution_mode,
                                EXTRACT(epoch FROM freshness_warn_after) AS w, EXTRACT(epoch FROM freshness_error_after) AS e, zone
                           FROM mcp_datasets WHERE dataset_id = :id AND NOT is_stub');
    $st->execute(['id' => $id]);
    $r = $st->fetch() ?: [];
    foreach (['owner_member_id', 'steward_member_id', 'department_id'] as $k) {
        $r[$k] = isset($r[$k]) ? (int) $r[$k] : null;
    }
    $r['freshness_warn_after'] = interval_words($r['w'] ?? null);
    $r['freshness_error_after'] = interval_words($r['e'] ?? null);
    unset($r['w'], $r['e']);
    return $r;
}
