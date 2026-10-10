<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract; design-system record-picker.md).
 * One entry per record kind, added by the slice whose form chooses it:
 *   key => ['title', 'noun', 'roles' => [Data Lake rights, any one admits — has_right()], 'params' => [filter keys the endpoint forwards, as integers],
 *           'search' => fn(PDO, string $q, array $params, int $page): array{rows, total, page, page_size, pages},   rows: [id, label, detail?, badge?, color?]
 *           'label'  => fn(PDO, int $id): ?string]
 * Every read goes through a walled view (mcp_members: an external sees only themselves; mcp_departments). A short enumeration (a zone, a
 * level, a kind, a classification) stays a <select>.
 *
 * Phase 2 (the shell) registers `member` and `department`. The slices add theirs (docs/build-specs/*.md):
 *   dataset, tag, term — slice 1 · template, upload — slice 2 · saved_query — slice 3 · group, grant — slice 4 · check, incident — slice 5 ·
 *   source — slice 6 · transform, metric — slice 7 · erasure — slice 8
 */

const PICKER_PAGE = 20;

/** One page of a walled list: ['rows', 'total', 'page', 'page_size', 'pages']. */
function picker_page(PDO $pdo, string $countSql, string $rowsSql, array $args, int $page): array
{
    $st = $pdo->prepare($countSql);
    $st->execute($args);
    $total = (int) $st->fetchColumn();
    $pages = max(1, (int) ceil($total / PICKER_PAGE));
    $page = max(1, min($page, $pages));
    $st = $pdo->prepare($rowsSql . ' LIMIT ' . PICKER_PAGE . ' OFFSET ' . (($page - 1) * PICKER_PAGE));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'page_size' => PICKER_PAGE, 'pages' => $pages];
}

/** A search box's words as an ILIKE pattern (the % and _ a person types are literal). */
function picker_like(string $q): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
}

return [
    'member' => [
        'title' => 'People and agents', 'noun' => 'person', 'roles' => ['catalog.browse'], 'params' => ['agents'],
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            $where = '(:q = \'\' OR display_name ILIKE :like OR email ILIKE :like OR job_title ILIKE :like)';
            $args = ['q' => $q, 'like' => picker_like($q)];
            if (($params['agents'] ?? null) !== null) {
                $where .= ' AND is_agent = :agents';
                $args['agents'] = (int) $params['agents'] === 1 ? 't' : 'f';
            }
            $r = picker_page($pdo, "SELECT count(*) FROM mcp_members WHERE $where",
                "SELECT member_id, display_name, job_title, email, is_agent, is_external FROM mcp_members WHERE $where ORDER BY is_agent, display_name, member_id", $args, $page);
            $r['rows'] = array_map(static fn (array $m): array => [
                'id' => (int) $m['member_id'], 'label' => (string) $m['display_name'],
                'detail' => (string) ($m['job_title'] ?: ($m['email'] ?? '')),
                'badge' => $m['is_agent'] ? 'Agent' : ($m['is_external'] ? 'External' : null), 'color' => $m['is_agent'] ? 'info' : 'warning',
            ], $r['rows']);
            return $r;
        },
        'label' => static fn (PDO $pdo, int $id): ?string => ($v = one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :id', ['id' => $id])) === null ? null : (string) $v,
    ],
    'department' => [
        'title' => 'Departments', 'noun' => 'department', 'roles' => ['catalog.browse'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            $where = 'archived_at IS NULL AND (:q = \'\' OR name ILIKE :like OR description ILIKE :like)';
            $args = ['q' => $q, 'like' => picker_like($q)];
            $r = picker_page($pdo, "SELECT count(*) FROM mcp_departments WHERE $where",
                "SELECT department_id, name, description, is_system FROM mcp_departments WHERE $where ORDER BY name, department_id", $args, $page);
            $r['rows'] = array_map(static fn (array $d): array => [
                'id' => (int) $d['department_id'], 'label' => (string) $d['name'], 'detail' => mb_substr((string) ($d['description'] ?? ''), 0, 120),
                'badge' => $d['is_system'] ? 'Standing' : null, 'color' => 'secondary',
            ], $r['rows']);
            return $r;
        },
        'label' => static fn (PDO $pdo, int $id): ?string => ($v = one_value($pdo, 'SELECT name FROM mcp_departments WHERE department_id = :id', ['id' => $id])) === null ? null : (string) $v,
    ],
];
