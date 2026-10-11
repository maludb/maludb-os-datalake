<?php
declare(strict_types=1);

/**
 * Reads of uploads (uploads-loads.md "Query functions"). An upload is its uploader's alone: find_upload() answers only the caller's own
 * session — not even an admin reads another person's sniff, because the sniff's 50 sample rows are that person's file shown back to them
 * (lake-engine §3: "samples are shown only to the uploader"). The list a tool reads (find_uploads) is mcp_uploads, which carries no sample.
 */

require_once dirname(__DIR__) . '/catalog/queries.php';     // like_pattern

const UPLOAD_STATUS = ['open' => ['secondary', 'Uploading'], 'assembling' => ['info', 'Checking'], 'done' => ['success', 'Landed'],
    'failed' => ['danger', 'Failed'], 'expired' => ['dark', 'Expired']];
const SNIFF_STATUS = ['none' => null, 'queued' => ['info', 'Reading…'], 'done' => null, 'failed' => ['danger', 'Could not read it']];
/** The mapping screen's types (uploads-loads.md): a raw load keeps every column text; a sandbox load is typed by these. */
const MAP_TYPES = ['VARCHAR' => 'Text', 'BIGINT' => 'Whole number', 'DECIMAL(18,2)' => 'Amount (2 decimals)', 'DOUBLE' => 'Number', 'BOOLEAN' => 'Yes / no',
    'DATE' => 'Date', 'TIMESTAMP' => 'Date and time'];
/** The personal kinds (db/007 dl_personal_kinds()), in words. */
const PERSONAL_KINDS = ['email' => 'email addresses', 'phone' => 'phone numbers', 'name' => 'names', 'address' => 'addresses', 'national_id' => 'national ids',
    'payment_card' => 'card numbers', 'bank_account' => 'bank accounts', 'birth_date' => 'birth dates', 'ip_address' => 'IP addresses',
    'member_id' => 'member ids', 'free_text_personal' => 'text with personal data', 'other' => 'other personal data'];

/** The caller's own upload, with its sniff, its blob and the load it made (null when it is not theirs or not there). */
function find_upload(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT u.id, u.member_id, u.filename, u.byte_size, u.part_bytes, u.parts_total, u.parts_done, u.status, u.error, u.sniff,
                                u.sniff_status, u.blob_id, u.target_dataset_id, u.source_id, u.created_at, u.updated_at, u.expires_at,
                                b.sha256, b.ref_count, b.deleted_at AS blob_deleted_at,
                                (SELECT l.id FROM loads l WHERE l.upload_session_id = u.id ORDER BY l.id DESC LIMIT 1) AS load_id,
                                (SELECT j.error FROM lake_jobs j WHERE j.kind = 'sniff' AND j.ref_id = u.id ORDER BY j.id DESC LIMIT 1) AS sniff_error
                           FROM upload_sessions u LEFT JOIN blobs b ON b.id = u.blob_id
                          WHERE u.id = :id AND u.member_id = :me");
    $st->execute(['id' => $id, 'me' => (int) current_member_id()]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['sniff'] = $r['sniff'] === null ? null : json_decode((string) $r['sniff'], true);
    return $r;
}

/** My last uploads (the upload screen's list): no sample, the load each made and its dataset. */
function my_uploads(PDO $pdo, int $member, int $limit): array
{
    $st = $pdo->prepare("SELECT u.id, u.filename, u.byte_size, u.status, u.sniff_status, u.created_at, u.parts_done, u.parts_total,
                                l.id AS load_id, l.status AS load_status, d.id AS dataset_id, d.key AS dataset_key
                           FROM upload_sessions u
                           LEFT JOIN LATERAL (SELECT x.id, x.status, x.dataset_id FROM loads x WHERE x.upload_session_id = u.id ORDER BY x.id DESC LIMIT 1) l ON true
                           LEFT JOIN datasets d ON d.id = l.dataset_id
                          WHERE u.member_id = :m AND u.source_id IS NULL
                          ORDER BY u.id DESC LIMIT " . max(1, min(100, $limit)));
    $st->execute(['m' => $member]);
    return $st->fetchAll();
}

/** The sniff's state for the polling partial: [status, sniff (own only)]. */
function sniff_state(PDO $pdo, int $id): array
{
    $u = find_upload($pdo, $id);
    return $u === null ? ['status' => 'none', 'sniff' => null] : ['status' => (string) $u['sniff_status'], 'sniff' => $u['sniff']];
}

/** The `upload` resolver and the find_uploads tool (records MCP): mcp_uploads — no sample, never another person's but for the admin's list. */
function find_uploads(PDO $pdo, ?string $status, int $limit, ?string $q = null): array
{
    $where = 'member_id = :me';
    $args = ['me' => (int) current_member_id()];
    if ($status !== null && isset(UPLOAD_STATUS[$status])) {
        $where .= ' AND status = :s';
        $args['s'] = $status;
    }
    if ($q !== null && $q !== '') {
        $where .= ' AND filename ILIKE :q';
        $args['q'] = like_pattern($q);
    }
    $st = $pdo->prepare("SELECT upload_id, filename, byte_size, status, sniff_status, target_dataset_id, created_at FROM mcp_uploads WHERE $where
                          ORDER BY upload_id DESC LIMIT " . max(1, min(200, $limit)));
    $st->execute($args);
    return $st->fetchAll();
}

/** The sheet the mapping works on: the one asked for, else the first; [name|null, columns, sample rows, header_rows, warning]. */
function sniff_sheet(array $sniff, ?string $sheet): array
{
    if (!empty($sniff['sheets'])) {
        $pick = $sniff['sheets'][0];
        foreach ($sniff['sheets'] as $sh) {
            if ($sheet !== null && $sh['name'] === $sheet) {
                $pick = $sh;
            }
        }
        return ['name' => $pick['name'], 'columns' => $pick['columns'], 'rows' => $sniff['sample'][$pick['name']]['rows'] ?? [],
                'header_rows' => (int) ($pick['header_rows'] ?? 1), 'skip_rows' => (int) ($pick['skip_rows'] ?? 0), 'warning' => $pick['warning'] ?? null,
                'rows_estimate' => $pick['rows_estimate'] ?? null];
    }
    return ['name' => null, 'columns' => $sniff['columns'] ?? [], 'rows' => $sniff['sample']['']['rows'] ?? [], 'header_rows' => 1, 'skip_rows' => 0,
            'warning' => null, 'rows_estimate' => $sniff['rows_estimate'] ?? null];
}

/** The raw datasets I may write (the `raw_dataset` picker; an engineer's target). */
function raw_datasets_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $where = "zone = 'raw' AND NOT is_stub AND status <> 'retired' AND dl_level_rank(my_level) >= 4 AND (:q = '' OR key ILIKE :like OR title ILIKE :like)";
    $args = ['q' => $q, 'like' => like_pattern($q)];
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_datasets WHERE $where",
        "SELECT dataset_id, key, title, row_count FROM mcp_datasets WHERE $where ORDER BY title, dataset_id", $args, $page);
    $r['rows'] = array_map(static fn (array $d): array => ['id' => (int) $d['dataset_id'], 'label' => (string) $d['title'], 'detail' => (string) $d['key'],
        'badge' => $d['row_count'] === null ? 'empty' : count_words((int) $d['row_count']) . ' rows', 'color' => 'secondary'], $r['rows']);
    return $r;
}
