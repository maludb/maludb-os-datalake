<?php
declare(strict_types=1);

/** The JSON shape of a search result (whitelisted; the excerpt as plain text, the marks removed; a stub carries its name, owner and the request link only). */
function present_search_row(array $r): array
{
    if ($r['visibility'] === 'stub') {
        return ['entity_kind' => $r['entity_kind'], 'entity_id' => (int) $r['entity_id'], 'visibility' => 'stub', 'title' => $r['title'],
                'owner_member_id' => $r['owner_member_id'] !== null ? (int) $r['owner_member_id'] : null, 'owner_name' => $r['owner_name'],
                'request_access' => search_request_url($r)];
    }
    return ['entity_kind' => $r['entity_kind'], 'entity_id' => (int) $r['entity_id'], 'visibility' => 'full', 'title' => $r['title'], 'key' => $r['key'],
            'dataset_id' => $r['dataset_id'] !== null ? (int) $r['dataset_id'] : null, 'excerpt' => strip_tags((string) $r['excerpt']), 'zone' => $r['zone'],
            'certification' => $r['certification'], 'classification' => $r['classification'],
            'owner_member_id' => $r['owner_member_id'] !== null ? (int) $r['owner_member_id'] : null, 'owner_name' => $r['owner_name'],
            'usage_30d' => (int) $r['usage_30d'], 'rank' => round((float) $r['rank'], 4), 'url' => search_result_url($r)];
}
