<?php
declare(strict_types=1);

/**
 * THE SEARCH BOX is the catalog's search (screen `search`; the records server's `catalog_search` tool reads the same function):
 * dl_search() over the canonical search_index (db/015) — datasets, glossary terms, saved queries and metrics by title, description,
 * columns and tags; websearch syntax; a misspelt title by trigram; certified first, then usage; every row judged by the wall in the
 * function — a dataset the person may not discover but may know exists comes back as a STUB (its name and owner, no excerpt, no
 * classification), anything else not at all. PHP adds nothing to the rule.
 */
const SEARCH_KINDS = ['dataset' => 'Datasets', 'glossary_term' => 'Glossary', 'saved_query' => 'Saved queries', 'metric' => 'Metrics'];
const SEARCH_ZONES = ['raw', 'curated', 'product', 'sandbox'];
const SEARCH_CLASSIFICATIONS = ['public', 'internal', 'confidential', 'restricted'];
const SEARCH_PAGE = 25;

/** The screen's filters from the request (kind, zone, department, owner, tag, classification, certification, personal) — unknown values dropped. */
function search_filters_from_request(): array
{
    $f = [];
    $kind = request_string('kind');
    if (isset(SEARCH_KINDS[$kind])) {
        $f['kind'] = $kind;
    }
    $zone = request_string('zone');
    if (in_array($zone, SEARCH_ZONES, true)) {
        $f['zone'] = $zone;
    }
    foreach (['department', 'owner'] as $k) {
        $v = request_integer($k);
        if ($v !== null && $v > 0) {
            $f[$k] = $v;
        }
    }
    $tag = request_string('tag');
    if ($tag !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,59}$/', $tag)) {
        $f['tag'] = $tag;
    }
    $class = request_string('classification');
    if (in_array($class, SEARCH_CLASSIFICATIONS, true)) {
        $f['classification'] = $class;
    }
    if (request_string('certification') === 'certified') {
        $f['certification'] = 'certified';
    }
    $personal = request_string('personal');
    if (in_array($personal, ['yes', 'no', 'true', 'false', '1', '0'], true)) {
        $f['personal'] = in_array($personal, ['yes', 'true', '1'], true);
    }
    return $f;
}

/** One page of results: ['rows' => [...], 'total' => int, 'stubs' => int on this page]. */
function search(PDO $pdo, string $q, array $filters, int $page): array
{
    $st = $pdo->prepare('SELECT entity_kind, entity_id, dataset_id, title, excerpt, zone, certification, classification, owner_member_id, usage_30d, visibility, rank, total
                           FROM dl_search(:q, CAST(:f AS jsonb), :lim, :off)');
    $st->execute(['q' => $q, 'f' => json_encode((object) $filters), 'lim' => SEARCH_PAGE, 'off' => (max(1, $page) - 1) * SEARCH_PAGE]);
    $rows = $st->fetchAll();
    $datasetIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['dataset_id'], array_filter($rows, static fn (array $r): bool => $r['dataset_id'] !== null))));
    $keys = [];
    if ($datasetIds !== []) {
        $k = $pdo->prepare('SELECT dataset_id, key FROM mcp_datasets WHERE dataset_id = ANY (CAST(:ids AS bigint[])) AND NOT is_stub');
        $k->execute(['ids' => '{' . implode(',', $datasetIds) . '}']);
        $keys = $k->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $ownerIds = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) $r['owner_member_id'], $rows))));
    $owners = [];
    if ($ownerIds !== []) {
        $o = $pdo->prepare('SELECT id, display_name FROM members WHERE id = ANY (CAST(:ids AS bigint[]))');
        $o->execute(['ids' => '{' . implode(',', $ownerIds) . '}']);
        $owners = $o->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    foreach ($rows as &$r) {
        $r['key'] = $r['visibility'] === 'full' && $r['entity_kind'] === 'dataset' ? ($keys[(int) $r['entity_id']] ?? null) : null;
        $r['owner_name'] = $r['owner_member_id'] !== null ? ($owners[(int) $r['owner_member_id']] ?? null) : null;
    }
    unset($r);
    return ['rows' => $rows, 'total' => $rows === [] ? 0 : (int) $rows[0]['total'],
            'stubs' => count(array_filter($rows, static fn (array $r): bool => $r['visibility'] === 'stub'))];
}

/** A result's screen (a dataset by its key when the person may discover it, else by id — the stub page). */
function search_result_url(array $r): string
{
    if ($r['entity_kind'] === 'dataset') {
        return '/datasets/' . (($r['key'] ?? null) !== null ? $r['key'] : (int) $r['entity_id']);
    }
    return ['glossary_term' => '/glossary/', 'saved_query' => '/queries/', 'metric' => '/metrics/'][$r['entity_kind']] . (int) $r['entity_id'];
}

/** The Request access link a stub carries. */
function search_request_url(array $r): string
{
    return '/access/requests/new?' . http_build_query(['dataset' => (int) $r['entity_id'], 'level' => 'read']);
}

/** dl_search's excerpt marks the words matched with <mark>…</mark>; everything else escaped — no markup from the data reaches the page. */
function search_excerpt_html(?string $excerpt): string
{
    return str_replace(['&lt;mark&gt;', '&lt;/mark&gt;'], ['<mark>', '</mark>'], e((string) $excerpt));
}
