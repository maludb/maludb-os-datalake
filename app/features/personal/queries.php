<?php
declare(strict_types=1);

/**
 * The personal-data map (access-policies.md screen `personal-data`): every live column that is personal (confirmed or set by the classifier's
 * rule at ≥ 0.8, ★3) or proposed, in the datasets I may discover (mcp_dataset_columns' wall). A person confirms or rejects (★15 — never an
 * agent); catalog.edit sets a column's classification and kind directly (column_classify).
 */

const PERSONAL_PAGE = 50;

/** The personal kinds (db/007 dl_personal_kinds()). */
function personal_kinds(PDO $pdo): array
{
    static $k = null;
    return $k ??= array_map('strval', $pdo->query('SELECT unnest(dl_personal_kinds())')->fetchAll(PDO::FETCH_COLUMN));
}

/** $filters: kind, confirmed (yes|no), dataset (id). ['rows', 'total', 'page']. */
function personal_map(PDO $pdo, array $filters, int $page): array
{
    $where = ['c.removed_snapshot_id IS NULL', 'NOT c.is_system', '(c.personal_kind IS NOT NULL OR c.proposed_personal_kind IS NOT NULL)'];
    $args = [];
    if (!empty($filters['kind']) && in_array($filters['kind'], personal_kinds($pdo), true)) {
        $where[] = '(c.personal_kind = :k OR c.proposed_personal_kind = :k2)';
        $args['k'] = $filters['kind'];
        $args['k2'] = $filters['kind'];
    }
    if (($filters['confirmed'] ?? '') === 'yes') {
        $where[] = 'c.classification_confirmed_at IS NOT NULL';
    } elseif (($filters['confirmed'] ?? '') === 'no') {
        $where[] = 'c.classification_confirmed_at IS NULL';
    }
    if (!empty($filters['dataset'])) {
        $where[] = 'c.dataset_id = :ds';
        $args['ds'] = (int) $filters['dataset'];
    }
    $w = implode(' AND ', $where);
    $page = max(1, $page);
    $st = $pdo->prepare("SELECT c.column_id, c.dataset_id, c.name, c.classification, c.personal_kind, c.proposed_personal_kind, c.proposed_confidence, c.classified_by,
                                c.classification_confirmed_at, d.key, d.title, d.owner_member_id, d.steward_member_id
                           FROM mcp_dataset_columns c JOIN mcp_datasets d ON d.dataset_id = c.dataset_id AND NOT d.is_stub
                          WHERE $w
                          ORDER BY (c.classification_confirmed_at IS NOT NULL), c.proposed_confidence DESC NULLS LAST, d.key, c.ordinal
                          LIMIT " . PERSONAL_PAGE . ' OFFSET ' . (($page - 1) * PERSONAL_PAGE));
    $st->execute($args);
    $rows = $st->fetchAll();
    $st = $pdo->prepare("SELECT count(*) FROM mcp_dataset_columns c JOIN mcp_datasets d ON d.dataset_id = c.dataset_id AND NOT d.is_stub WHERE $w");
    $st->execute($args);
    return ['rows' => $rows, 'total' => (int) $st->fetchColumn(), 'page' => $page];
}

/** May I confirm or reject a column's personal kind? A person (never an agent — ★15) who owns or stewards the dataset, or holds catalog.edit. */
function may_confirm_personal(array $c, int $me): bool
{
    if ((current_member()['member_kind'] ?? 'human') === 'agent') {
        return false;
    }
    return has_right('catalog.edit') || (int) ($c['owner_member_id'] ?? 0) === $me || (int) ($c['steward_member_id'] ?? 0) === $me;
}

/** A column of a dataset I may discover, by dataset and name or id: the map's fields, or null. */
function find_personal_column(PDO $pdo, int $dataset, string $nameOrId): ?array
{
    $byId = ctype_digit($nameOrId) && strlen($nameOrId) <= 18;
    return one_row($pdo, "SELECT c.column_id, c.dataset_id, c.name, c.classification, c.personal_kind, c.proposed_personal_kind, c.proposed_confidence, c.classified_by,
                                 c.classification_confirmed_at, d.key, d.owner_member_id, d.steward_member_id
                            FROM mcp_dataset_columns c JOIN mcp_datasets d ON d.dataset_id = c.dataset_id AND NOT d.is_stub
                           WHERE c.dataset_id = :d AND c.removed_snapshot_id IS NULL AND " . ($byId ? 'c.column_id = :c' : 'c.name = :c'),
        ['d' => $dataset, 'c' => $byId ? (int) $nameOrId : $nameOrId]);
}
