<?php
declare(strict_types=1);

/**
 * The tag vocabulary's writes (actions tag_create, tag_update, tag_archive — catalog.edit; and tag_ensure(), which dataset_tag calls: a new
 * slug makes a FREE tag). The slug's form and its uniqueness are the database's (tags.slug CHECK and UNIQUE); a tag is archived, never
 * deleted — its grants stay until someone revokes them (they reach every dataset that carries it, db/008).
 */

/** A slug from words: "Sales EMEA" → "sales-emea" (lowercase, letters digits _ and -, at most 50). '' when nothing is left. */
function tag_slug(string $words): string
{
    $s = strtolower(trim($words));
    $s = (string) preg_replace('/[^a-z0-9_-]+/', '-', $s);
    $s = trim((string) preg_replace('/-{2,}/', '-', $s), '-_');
    return substr($s, 0, 50);
}

/** Make or change a tag. $f: slug (on create), label, kind, colour (#rrggbb or null). Returns the id. */
function tag_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO tags (slug, label, kind, colour, created_by) VALUES (:s, :l, :k, :c, :by) RETURNING id');
        $st->execute(['s' => $f['slug'], 'l' => $f['label'], 'k' => $f['kind'] ?? 'managed', 'c' => $f['colour'] ?? null, 'by' => $by]);
        return (int) $st->fetchColumn();
    }
    $sets = [];
    $args = ['id' => $id];
    foreach (['label', 'kind', 'colour'] as $k) {
        if (array_key_exists($k, $f)) {
            $sets[] = "$k = :$k";
            $args[$k] = $f[$k];
        }
    }
    if ($sets !== []) {
        $pdo->prepare('UPDATE tags SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args);
    }
    return $id;
}

/** Archive a tag (it leaves the vocabulary and the pickers; datasets keep it; its grants stay until revoked). */
function tag_archive(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE tags SET archived_at = now() WHERE id = :id AND archived_at IS NULL')->execute(['id' => $id]);
}

/**
 * The tag a person or an agent names, made when new: an id → that tag; words → their slug's tag, else a new FREE tag labelled with the
 * words as typed. An archived tag is refused in words. Returns [tag_id, slug, label, made].
 */
function tag_ensure(PDO $pdo, string $given, int $by): array
{
    $given = trim($given);
    if ($given !== '' && ctype_digit($given)) {
        $t = find_tag($pdo, $given) ?? throw new DomainException('There is no tag ' . $given . '.');
    } else {
        $slug = tag_slug(ltrim($given, '#'));
        if ($slug === '' || !preg_match(TAG_SLUG_RE, $slug)) {
            throw new DomainException('A tag is a word or two — letters and digits.');
        }
        $t = find_tag($pdo, $slug);
        if ($t === null) {
            $label = mb_substr(trim(ltrim($given, '#')), 0, 80);
            $id = tag_save($pdo, null, ['slug' => $slug, 'label' => $label === '' ? $slug : $label, 'kind' => 'free'], $by);
            return [$id, $slug, $label === '' ? $slug : $label, true];
        }
    }
    if ($t['archived_at'] !== null) {
        throw new DomainException('The tag ' . $t['label'] . ' is archived — choose another.');
    }
    return [(int) $t['tag_id'], (string) $t['slug'], (string) $t['label'], false];
}
