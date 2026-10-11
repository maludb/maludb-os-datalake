<?php
declare(strict_types=1);

/** Saved mappings (uploads-loads.md; the find_import_templates tool and the `template` resolver): mcp_import_templates, every insider's. */

require_once dirname(__DIR__) . '/catalog/queries.php';     // like_pattern

const TEMPLATE_KINDS = ['csv' => 'CSV', 'xlsx' => 'Workbook', 'json' => 'JSON', 'parquet' => 'Parquet'];

function find_templates(PDO $pdo, ?string $q, ?string $kind = null, int $limit = 100): array
{
    $where = "(:q = '' OR t.name ILIKE :like)";
    $args = ['q' => (string) $q, 'like' => like_pattern((string) $q)];
    if ($kind !== null && isset(TEMPLATE_KINDS[$kind])) {
        $where .= ' AND t.kind = :k';
        $args['k'] = $kind;
    }
    $st = $pdo->prepare("SELECT t.template_id, t.name, t.kind, t.connector, t.mapping, t.created_by, t.updated_at, m.display_name AS created_by_name
                           FROM mcp_import_templates t LEFT JOIN members m ON m.id = t.created_by WHERE $where ORDER BY t.name LIMIT " . max(1, min(200, $limit)));
    $st->execute($args);
    return array_map(static function (array $r): array {
        $r['mapping'] = json_decode((string) $r['mapping'], true) ?? [];
        return $r;
    }, $st->fetchAll());
}

function find_template(PDO $pdo, int|string $ref): ?array
{
    $r = ctype_digit((string) $ref)
        ? one_row($pdo, 'SELECT template_id, name, kind, connector, mapping, created_by, updated_at FROM mcp_import_templates WHERE template_id = :id', ['id' => (int) $ref])
        : one_row($pdo, 'SELECT template_id, name, kind, connector, mapping, created_by, updated_at FROM mcp_import_templates WHERE lower(name) = lower(:n)', ['n' => (string) $ref]);
    if ($r === null) {
        return null;
    }
    $r['mapping'] = json_decode((string) $r['mapping'], true) ?? [];
    return $r;
}

/** May the caller change or delete it: its maker, or loads.manage. */
function may_manage_template(array $t): bool
{
    return (int) ($t['created_by'] ?? 0) === (int) current_member_id() || has_right('loads.manage');
}

function templates_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $where = "(:q = '' OR name ILIKE :like)";
    $args = ['q' => $q, 'like' => like_pattern($q)];
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_import_templates WHERE $where",
        "SELECT template_id, name, kind, mapping FROM mcp_import_templates WHERE $where ORDER BY name, template_id", $args, $page);
    $r['rows'] = array_map(static fn (array $t): array => ['id' => (int) $t['template_id'], 'label' => (string) $t['name'],
        'detail' => count((array) (json_decode((string) $t['mapping'], true)['columns'] ?? [])) . ' columns', 'badge' => TEMPLATE_KINDS[$t['kind']] ?? $t['kind'], 'color' => 'secondary'], $r['rows']);
    return $r;
}
