<?php
declare(strict_types=1);
/**
 * /access/break-glass?dataset= (screen `admin-read`) and, POSTed, action `admin_read_open` (log dataset.admin_read — lake.admin_read; PEOPLE
 * only; confirm; pauses `other` were it ever relayed): **dataset**, **reason** (10 to 2,000 characters). dl_admin_read_open: an hour of the
 * dataset's rows read unmasked, every query audited; its owner and every other Lake admin told. The log carries the reason's length only.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/breakglass/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/breakglass/write.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    dl_handler_begin();
    require_right('lake.admin_read');
    require_human();
    $d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
    $errors = [];
    $reason = dl_text('reason', null, 2000, 'The reason', $errors, true);
    if (($reason === null && !isset($errors['reason'])) || ($reason !== null && mb_strlen($reason) < 10)) {
        $errors['reason'] = 'Say why in at least 10 characters — the owner reads it.';
    }
    $me = (int) current_member_id();
    if (($open = my_open_admin_read($pdo, $me, (int) $d['id'])) !== null) {
        $errors['dataset'] = 'Your break-glass on ' . $d['key'] . ' is open until ' . format_ts((string) $open['expires_at'], member_timezone(), 'g:i A') . '.';
    }
    if ($errors !== []) {
        dl_refuse_fields($errors);
    }
    $id = dl_guard($pdo, static function () use ($pdo, $d, $reason): int {
        $pdo->beginTransaction();
        $id = admin_read_open($pdo, (int) $d['id'], (string) $reason);
        $until = (string) one_value($pdo, 'SELECT expires_at FROM admin_reads WHERE id = :id', ['id' => $id]);
        log_activity($pdo, 'dataset.admin_read', 'dataset', (int) $d['id'], ['after' => ['dataset_id' => (int) $d['id'], 'admin_read_id' => $id, 'expires_at' => $until,
            'reason_length' => mb_strlen((string) $reason)]]);
        $pdo->commit();
        return $id;
    });
    dl_done('Opened ' . $d['key'] . ' for one hour — the owner is told', (int) $d['id'], dl_land(return_path('/access/break-glass'), 'opened'), 'accessChanged', ['admin_read_id' => $id]);
}
require_login();
require_right('lake.admin_read');
require_human();
$me = (int) current_member_id();
$v = ['dataset' => null, 'reason' => ''];
$ownerName = null;
if (request_string('dataset') !== '') {
    $d = require_dataset($pdo, request_string('dataset'), 'discover');
    $v['dataset'] = (int) $d['id'];
    $ownerName = $d['owner_member_id'] === null ? null : (string) (one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :m', ['m' => (int) $d['owner_member_id']]) ?? '');
}
$open = my_admin_reads($pdo, $me);
log_screen_view($pdo, 'admin-read', $v['dataset'] === null ? [] : ['entity_type' => 'dataset', 'entity_id' => $v['dataset'], 'dataset_id' => $v['dataset']]);
if (wants_json()) {
    respond_screen(['values' => $v, 'open' => array_map(static fn (array $w): array => ['admin_read_id' => (int) $w['admin_read_id'], 'dataset' => ['id' => (int) $w['dataset_id'], 'key' => $w['key']],
        'started_at' => json_ts($w['started_at']), 'expires_at' => json_ts($w['expires_at'])], $open)]);
}
render_screen('Break-glass', view('access/break-glass.php', ['v' => $v, 'open' => $open, 'owner_name' => $ownerName ?: null,
    'notice' => dl_notice($_GET['notice'] ?? null, ['opened' => ['danger', 'Open for one hour — the owner and the other Lake admins have been told; every query is audited.']])]),
    ['activeNav' => 'access', 'screen' => 'admin-read']);
