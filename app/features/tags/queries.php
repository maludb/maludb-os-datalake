<?php
declare(strict_types=1);

/**
 * The tag vocabulary's reads (slice 1; the records tool `find_tags`, the `tag` resolver and picker): managed and free tags through
 * mcp_tags, archived ones on request. A tag's live grants are COUNTED (never listed) so archiving one can warn that its grants stay.
 */

const TAG_KINDS = ['managed' => 'Managed — the vocabulary engineers keep', 'free' => 'Free — made by tagging'];
const TAG_SLUG_RE = '/^[a-z0-9][a-z0-9_-]{0,49}$/';

/** Tags by words (label or slug) and kind; archived ones only when $archived. Rows {tag_id, slug, label, usage_count, kind, colour, archived_at}. */
function find_tags(PDO $pdo, ?string $q, ?string $kind, bool $archived = false, int $limit = 200): array
{
    $w = [$archived ? 'true' : 'archived_at IS NULL'];
    $a = [];
    if ($q !== null && $q !== '') {
        $w[] = '(label ILIKE :q OR slug ILIKE :q)';
        $a['q'] = like_pattern($q);
    }
    if ($kind !== null && isset(TAG_KINDS[$kind])) {
        $w[] = 'kind = :kind';
        $a['kind'] = $kind;
    }
    $st = $pdo->prepare('SELECT tag_id, slug, label, usage_count, kind, colour, archived_at FROM mcp_tags WHERE ' . implode(' AND ', $w)
        . " ORDER BY (archived_at IS NOT NULL), (kind = 'managed') DESC, usage_count DESC, label LIMIT " . max(1, min(500, $limit)));
    $st->execute($a);
    return $st->fetchAll();
}

/** One tag by its id or its slug (archived ones too). */
function find_tag(PDO $pdo, string $idOrSlug): ?array
{
    $byId = ctype_digit($idOrSlug) && strlen($idOrSlug) <= 18;
    if (!$byId && !preg_match(TAG_SLUG_RE, $idOrSlug)) {
        return null;
    }
    $st = $pdo->prepare('SELECT tag_id, slug, label, usage_count, kind, colour, archived_at FROM mcp_tags WHERE ' . ($byId ? 'tag_id = :v' : 'slug = :v'));
    $st->execute(['v' => $byId ? (int) $idOrSlug : $idOrSlug]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** How many live grants a tag carries (a count only — grants on a tag reach every dataset that carries it, db/008). */
function tag_grant_count(PDO $pdo, int $tagId): int
{
    return (int) one_value($pdo, "SELECT count(*) FROM grants WHERE target_kind = 'tag' AND tag_id = :t AND revoked_at IS NULL", ['t' => $tagId]);
}

/** The tags carrying a live grant (the tags page warns on their archive button): [tag_id => count]. */
function tags_with_grants(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT tag_id, count(*) AS n FROM grants WHERE target_kind = 'tag' AND revoked_at IS NULL GROUP BY tag_id")->fetchAll() as $r) {
        $out[(int) $r['tag_id']] = (int) $r['n'];
    }
    return $out;
}

/** The picker's page of tags (live only). */
function tags_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $where = 'archived_at IS NULL AND (:q = \'\' OR label ILIKE :like OR slug ILIKE :like)';
    $args = ['q' => $q, 'like' => like_pattern($q)];
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_tags WHERE $where",
        "SELECT tag_id, slug, label, kind, usage_count FROM mcp_tags WHERE $where ORDER BY (kind = 'managed') DESC, usage_count DESC, label, tag_id", $args, $page);
    $r['rows'] = array_map(static fn (array $t): array => ['id' => (int) $t['tag_id'], 'label' => (string) $t['label'], 'detail' => '#' . $t['slug'] . ' · ' . (int) $t['usage_count'] . ' in use',
        'badge' => $t['kind'] === 'managed' ? 'Managed' : null, 'color' => 'primary'], $r['rows']);
    return $r;
}

/** A tag's JSON. */
function present_tag(array $t): array
{
    return ['tag_id' => (int) $t['tag_id'], 'slug' => $t['slug'], 'label' => $t['label'], 'usage_count' => (int) $t['usage_count'], 'kind' => $t['kind'],
            'colour' => $t['colour'], 'archived' => $t['archived_at'] !== null];
}
