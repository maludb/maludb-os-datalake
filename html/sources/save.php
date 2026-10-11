<?php
declare(strict_types=1);
/**
 * Actions `source_create` (no `source`; log source.create) and `source_update` (`source` + any field; log source.update) — sources.manage. A source
 * reads ONE thing into ONE raw dataset: name, connector (fixed once made), target_dataset (an id, a key raw.x or a name — made when new, which
 * needs loads.manage), settings (an object, or its JSON) and/or the connector's own fields (url, format, template_id · sftp_host, sftp_user,
 * sftp_path, pattern · provider, tool, arguments, rows_path, paging, page_size, window, inject · host, dbname, user, table, watermark, key[]),
 * schedule_minutes (0 = manual), rate_per_second, user_agent. THE CRUD EXEMPLAR'S ORDER (slice 1): dl_handler_begin() → require_right() → the
 * fields (a field left out stays as it was) → dl_guard() around the verbs → log_activity() (names, hosts, counts — never a URL's query, a
 * credential or a row) → dl_done(). The postgres guard's and the K7 provider's refusals are the database's, named on the field.
 * The credential is NOT here: source_credential_set seals it apart.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/write.php';
dl_handler_begin();
require_right('sources.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('source') ?? 0;
$cur = null;
if (req_has('source') && $id > 0) {
    $cur = find_source($pdo, $id) ?? respond_not_found('No such source.');
    if (req_has('connector') && (string) req_val('connector') !== $cur['connector']) {
        dl_refuse_fields(['connector' => 'A source\'s connector never changes — make another source.']);
    }
    $connector = (string) $cur['connector'];
} else {
    $id = 0;
    $connector = strtolower((string) (req_val('connector') ?? ''));
}
$in = [];
foreach ($_POST as $k => $v) {
    $in[(string) $k] = is_array($v) ? array_values(array_map(static fn ($x): string => trim((string) $x), $v)) : trim((string) $v);
}
[$f, $errors] = source_validate($pdo, $connector, $in, $cur, has_right('loads.manage'), true);
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$logFields = static fn (array $f): array => ['name' => $f['name'], 'connector' => $f['connector'], 'schedule_minutes' => $f['schedule_minutes'], 'rate_per_second' => (float) $f['rate_per_second'],
    'settings' => source_log_settings($f['connector'], (array) $f['settings']), 'target' => $f['target']['key'] ?? ($f['target']['id'] ?? null)];
$newId = dl_guard($pdo, static function () use ($pdo, $id, $f, $me, $cur, $logFields): int {
    $pdo->beginTransaction();
    try {
        $sid = source_save($pdo, $id > 0 ? $id : null, $f, $me);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23514') {                 // dl_sources_guard: a database of this server, a K7 source with no provider
            $pdo->rollBack();
            dl_refuse_fields([$f['connector'] === 'k7' ? 'provider' : 'host' => db_message($e, 'That source could not be saved.')]);
        }
        throw $e;
    }
    $target = (int) one_value($pdo, "SELECT NULLIF(settings ->> 'target_dataset_id', '')::bigint FROM sources WHERE id = :id", ['id' => $sid]) ?: null;
    if ($cur === null) {
        log_activity($pdo, 'source.create', 'source', $sid, ['dataset_id' => $target, 'after' => $logFields($f)]);
    } else {
        $before = ['name' => $cur['name'], 'schedule_minutes' => (int) $cur['schedule_minutes'], 'rate_per_second' => (float) $cur['rate_per_second'], 'settings' => source_log_settings($cur['connector'], (array) $cur['settings'])];
        $after = $logFields($f);
        unset($after['connector'], $after['target']);
        log_activity($pdo, 'source.update', 'source', $sid, ['dataset_id' => $target, 'before' => $before, 'after' => $after]);
    }
    $pdo->commit();
    return $sid;
});
dl_done($cur === null ? 'Added ' . $f['name'] : 'Saved ' . $f['name'], $newId, dl_land(return_path('/sources/' . $newId), $cur === null ? 'created' : 'updated'), 'sourceChanged');
