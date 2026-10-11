<?php
declare(strict_types=1);
/**
 * Action `source_test` (log source.test — sources.manage): **source** — the connector's probe: it ASKS (the file's headers, the directory, the share's first
 * rows, the database's table) and lands nothing; no pull is made or counted. The answer's words are the notice: ok, blocked (robots.txt, a bot wall, no
 * connection) or misconfigured. Logged with the state and the sentence (no URL query, no row).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/write.php';
[$pdo, $s, $me, $target] = source_action_begin();
$r = dl_guard($pdo, static fn (): array => source_test($pdo, (int) $s['source_id']));
$said = mb_substr($r['message'], 0, 240);
log_activity($pdo, 'source.test', 'source', (int) $s['source_id'], ['dataset_id' => $target, 'after' => ['state' => $r['state'], 'message' => mb_substr($said, 0, 200)]]);
dl_done($said, (int) $s['source_id'], dl_land(return_path('/sources/' . (int) $s['source_id']), 'test_' . $r['state']) . '&said=' . rawurlencode($said), 'sourceChanged', ['state' => $r['state'], 'message' => $said, 'facts' => $r['facts']]);
