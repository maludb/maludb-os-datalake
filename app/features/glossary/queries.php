<?php
declare(strict_types=1);

/**
 * The business glossary's reads (slice 1; the records tools `glossary` — the `term` resolver — and `get_term`): terms through
 * mcp_glossary_terms (every insider), the columns that carry a term through mcp_dataset_columns joined to mcp_datasets — ONLY columns of
 * datasets the caller may discover (a stub's column is never named). A parent term is the category.
 */

const TERM_STATUSES = ['draft' => 'Draft', 'approved' => 'Approved', 'deprecated' => 'Deprecated'];
const TERM_PAGE = 60;

/** Terms by words (name, synonyms, definition), status and category; deprecated ones only when asked. Rows + parent_name and steward_name; ['rows', 'total']. */
function find_terms(PDO $pdo, array $filters, int $page): array
{
    $w = [];
    $a = [];
    $status = $filters['status'] ?? null;
    $w[] = $status !== null && isset(TERM_STATUSES[$status]) ? 't.status = :status' : "t.status <> 'deprecated'";
    if ($status !== null && isset(TERM_STATUSES[$status])) {
        $a['status'] = $status;
    }
    if (($filters['q'] ?? '') !== '') {
        $w[] = "(t.name ILIKE :q OR t.definition ILIKE :q OR array_to_string(t.synonyms, ' ') ILIKE :q)";
        $a['q'] = like_pattern((string) $filters['q']);
    }
    if (($filters['parent'] ?? null) !== null) {
        $w[] = 't.parent_id = :parent';
        $a['parent'] = (int) $filters['parent'];
    }
    $limit = max(1, min(200, (int) ($filters['limit'] ?? TERM_PAGE)));
    $where = implode(' AND ', $w);
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_glossary_terms t WHERE $where", $a);
    $st = $pdo->prepare("SELECT t.*, p.name AS parent_name, m.display_name AS steward_name FROM mcp_glossary_terms t
                           LEFT JOIN mcp_glossary_terms p ON p.term_id = t.parent_id LEFT JOIN mcp_members m ON m.member_id = t.steward_member_id
                          WHERE $where ORDER BY lower(COALESCE(p.name, t.name)), (t.parent_id IS NOT NULL), lower(t.name), t.term_id
                          LIMIT $limit OFFSET " . ((max(1, $page) - 1) * $limit));
    $st->execute($a);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['synonyms'] = pg_text_array((string) $r['synonyms']);
    }
    unset($r);
    return ['rows' => $rows, 'total' => $total];
}

/** One term (any status) with its parent's and steward's names and who approved it. */
function find_term(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT t.*, p.name AS parent_name, m.display_name AS steward_name, g.approved_by, a.display_name AS approved_by_name
                           FROM mcp_glossary_terms t LEFT JOIN mcp_glossary_terms p ON p.term_id = t.parent_id
                           LEFT JOIN mcp_members m ON m.member_id = t.steward_member_id
                           LEFT JOIN glossary_terms g ON g.id = t.term_id LEFT JOIN mcp_members a ON a.member_id = g.approved_by
                          WHERE t.term_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['synonyms'] = pg_text_array((string) $r['synonyms']);
    return $r;
}

/** The columns that carry a term — of discoverable datasets only: [{dataset_id, key, title, column_id, column}]. */
function term_columns(PDO $pdo, int $termId): array
{
    $st = $pdo->prepare('SELECT d.dataset_id, d.key, d.title, c.column_id, c.name AS column FROM mcp_dataset_columns c
                           JOIN mcp_datasets d ON d.dataset_id = c.dataset_id AND NOT d.is_stub
                          WHERE c.glossary_term_id = :t AND c.removed_snapshot_id IS NULL ORDER BY d.key, c.ordinal');
    $st->execute(['t' => $termId]);
    return $st->fetchAll();
}

/** A term's live children (the category's terms). */
function term_children(PDO $pdo, int $termId): array
{
    $st = $pdo->prepare("SELECT term_id, name, status FROM mcp_glossary_terms WHERE parent_id = :t AND status <> 'deprecated' ORDER BY lower(name)");
    $st->execute(['t' => $termId]);
    return $st->fetchAll();
}

/** The picker's page of terms (draft and approved). Param `except` leaves a term out (a term is not its own category). */
function terms_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $where = "status <> 'deprecated' AND (:q = '' OR name ILIKE :like OR array_to_string(synonyms, ' ') ILIKE :like)";
    $args = ['q' => $q, 'like' => like_pattern($q)];
    if (($params['except'] ?? null) !== null) {
        $where .= ' AND term_id <> :except';
        $args['except'] = (int) $params['except'];
    }
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_glossary_terms WHERE $where",
        "SELECT term_id, name, definition, status FROM mcp_glossary_terms WHERE $where ORDER BY lower(name), term_id", $args, $page);
    $r['rows'] = array_map(static fn (array $t): array => ['id' => (int) $t['term_id'], 'label' => (string) $t['name'],
        'detail' => mb_substr((string) strtok((string) $t['definition'], "\n"), 0, 120), 'badge' => $t['status'] === 'draft' ? 'Draft' : null, 'color' => 'secondary'], $r['rows']);
    return $r;
}
