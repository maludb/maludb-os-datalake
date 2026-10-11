<?php
declare(strict_types=1);

/**
 * Open a break-glass (dl_admin_read_open — lake.admin_read; an hour, a reason, people only): the dataset's owner is told, and every other
 * Lake admin (admin_read). Returns the window's id.
 */
function admin_read_open(PDO $pdo, int $dataset, string $reason): int
{
    $st = $pdo->prepare('SELECT dl_admin_read_open(:d, :r)');
    $st->execute(['d' => $dataset, 'r' => $reason]);
    $id = (int) $st->fetchColumn();
    $me = (int) current_member_id();
    $w = one_row($pdo, 'SELECT a.expires_at, d.key, d.title, d.owner_member_id FROM admin_reads a JOIN datasets d ON d.id = a.dataset_id WHERE a.id = :id', ['id' => $id]);
    $who = (string) (current_member()['display_name'] ?? 'A Lake admin');
    $until = format_ts((string) $w['expires_at'], member_timezone(), 'g:i A T');
    $title = $who . ' opened ' . $w['title'] . ' until ' . $until;
    $body = mb_substr($reason, 0, 300);
    $told = [];
    $tell = static function (int $m) use ($pdo, $title, $body, $id, $dataset, $me, &$told): void {
        if ($m === $me || isset($told[$m])) {
            return;
        }
        $told[$m] = true;
        $pdo->prepare("SELECT dl_notify(:m, 'admin_read', :t, :b, 'dataset', :d, NULL, :actor, :dd)")
            ->execute(['m' => $m, 't' => $title, 'b' => $body, 'd' => $dataset, 'actor' => $me, 'dd' => 'admin_read:' . $id . ':' . $m]);
    };
    if ($w['owner_member_id'] !== null) {
        $tell((int) $w['owner_member_id']);
    }
    foreach ($pdo->query("SELECT dl_members_with_right('lake.admin_read')")->fetchAll(PDO::FETCH_COLUMN) as $admin) {
        $tell((int) $admin);
    }
    return $id;
}
