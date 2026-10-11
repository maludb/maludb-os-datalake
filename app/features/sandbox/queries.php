<?php
declare(strict_types=1);

/** My sandbox (uploads-loads.md; the my_sandbox tool): my sandbox datasets as cards and my quota (dl_sandbox_usage, db/013). Own only. */
function my_sandbox(PDO $pdo, int $member): array
{
    $st = $pdo->prepare("SELECT d.dataset_id, d.key, d.title, d.row_count, d.byte_size, d.data_updated_at, d.status, d.classification, d.has_personal_data,
                                EXISTS (SELECT 1 FROM loads l WHERE l.dataset_id = d.dataset_id AND l.upload_session_id IS NOT NULL) AS from_upload,
                                (SELECT max(l.id) FROM loads l WHERE l.dataset_id = d.dataset_id) AS last_load_id
                           FROM mcp_datasets d
                          WHERE d.zone = 'sandbox' AND d.owner_member_id = :m AND NOT d.is_stub AND d.status <> 'retired'
                          ORDER BY d.data_updated_at DESC NULLS LAST, d.dataset_id DESC");
    $st->execute(['m' => $member]);
    $u = one_row($pdo, 'SELECT used_bytes, quota_bytes, datasets FROM dl_sandbox_usage(:m)', ['m' => $member]) ?? ['used_bytes' => 0, 'quota_bytes' => 0, 'datasets' => 0];
    return ['datasets' => $st->fetchAll(), 'used_bytes' => (int) $u['used_bytes'], 'quota_bytes' => (int) $u['quota_bytes']];
}
