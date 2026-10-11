<?php
declare(strict_types=1);

/**
 * Writes of uploads (uploads-loads.md "Query functions"): the parts protocol (DocCloud's), the mapping that turns a landed file into a
 * load, the discard. PHP never reads a landed file's contents but to hash it and never opens DuckDB: the sniff and the load are the
 * WRITER's jobs (lake_enqueue). Every function throws UploadRefused (an HTTP status and a code) or DomainException (a sentence, "|id"
 * pointing at a record); the handlers answer them.
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/queries.php';
require_once dirname(__DIR__) . '/catalog/queries.php';     // DATASET_NAME_RE, text_array_literal, like_pattern
require_once dirname(__DIR__) . '/catalog/present.php';     // bytes_words

/** The extension of a file name, lower-case, for the log (never the name: a name can be a person's). */
function upload_ext(string $filename): string
{
    $e = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    return preg_match('/^[a-z0-9]{1,8}$/', $e) ? $e : '';
}

/** A file name made safe to keep: no path, no control characters, ≤ 200 characters. */
function upload_safe_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', '', $name));
    return $name === '' ? 'upload' : mb_substr($name, 0, 200);
}

/**
 * Start (or resume) an upload: the ceiling (413 too_large), an empty file (422 empty), the part size. $resume names an open session of the
 * caller's own for the same file name and size — it answers that session with the parts already received, so a broken connection carries on
 * where it stopped. Returns [upload_id, part_bytes, parts_total, parts_received[], resumed].
 */
function upload_start(PDO $pdo, int $member, string $filename, int $bytes, ?string $sha, ?int $resume = null): array
{
    $filename = upload_safe_name($filename);
    if ($bytes < 0) {
        throw new UploadRefused(422, 'bad_size', 'Say how many bytes the file has.');
    }
    if ($bytes === 0) {
        throw new UploadRefused(422, 'empty', 'An empty file has nothing to load.');
    }
    $ceiling = (int) one_value($pdo, "SELECT dl_setting_int('max_upload_bytes')");
    if ($bytes > $ceiling) {
        throw new UploadRefused(413, 'too_large', 'A file is at most ' . bytes_words($ceiling) . '.');
    }
    if ($sha !== null && !preg_match('/^[0-9a-f]{64}$/', $sha)) {
        $sha = null;
    }
    if ($resume !== null) {
        $st = $pdo->prepare("SELECT id, part_bytes, parts_total FROM upload_sessions WHERE id = :id AND member_id = :m AND status = 'open'
                               AND filename = :f AND byte_size = :n AND expires_at > now()");
        $st->execute(['id' => $resume, 'm' => $member, 'f' => $filename, 'n' => $bytes]);
        $s = $st->fetch();
        if ($s !== false) {
            $got = $pdo->prepare('SELECT part_no FROM upload_parts WHERE session_id = :s ORDER BY part_no');
            $got->execute(['s' => (int) $s['id']]);
            $parts = array_values(array_filter(array_map('intval', array_column($got->fetchAll(), 'part_no')),
                static fn (int $n): bool => is_file(store_part_path((int) $s['id'], $n))));
            return ['upload_id' => (int) $s['id'], 'part_bytes' => (int) $s['part_bytes'], 'parts_total' => (int) $s['parts_total'],
                    'parts_received' => $parts, 'resumed' => true];
        }
    }
    $partBytes = (int) one_value($pdo, "SELECT dl_setting_int('upload_part_bytes')");
    $total = max(1, (int) ceil($bytes / $partBytes));
    $hours = (int) one_value($pdo, "SELECT dl_setting_int('upload_session_hours')");
    $st = $pdo->prepare("INSERT INTO upload_sessions (member_id, filename, byte_size, part_bytes, parts_total, sha256_claimed, expires_at)
                         VALUES (:m, :f, :n, :pb, :pt, :sha, now() + make_interval(hours => :h)) RETURNING id");
    $st->execute(['m' => $member, 'f' => $filename, 'n' => $bytes, 'pb' => $partBytes, 'pt' => $total, 'sha' => $sha, 'h' => max(1, $hours)]);
    $id = (int) $st->fetchColumn();
    $dir = store_parts_dir($id);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new UploadRefused(503, 'storage', 'The landing area is not writable.');
    }
    return ['upload_id' => $id, 'part_bytes' => $partBytes, 'parts_total' => $total, 'parts_received' => [], 'resumed' => false];
}

/** The caller's own session row (as the writer of parts sees it), or null. */
function upload_session_of(PDO $pdo, int $id, int $member): ?array
{
    $st = $pdo->prepare('SELECT * FROM upload_sessions WHERE id = :id AND member_id = :m');
    $st->execute(['id' => $id, 'm' => $member]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** One part: the bytes at $tmpPath become the part's file (a repeat overwrites — that is the resume). Returns [part_no, parts_done, parts_total]. */
function upload_part(PDO $pdo, int $upload, int $partNo, string $tmpPath): array
{
    $s = upload_session_of($pdo, $upload, (int) current_member_id()) ?? throw new UploadRefused(404, 'not_found', 'Not found.');
    if ($s['status'] !== 'open') {
        throw new UploadRefused(409, 'not_open', 'This upload is ' . $s['status'] . '.');
    }
    if (strtotime((string) $s['expires_at']) < time()) {
        throw new UploadRefused(410, 'expired', 'This upload expired — start it again.');
    }
    $total = (int) $s['parts_total'];
    if ($partNo < 1 || $partNo > $total) {
        throw new UploadRefused(422, 'bad_part', 'Parts are numbered 1 to ' . $total . '.');
    }
    $size = is_file($tmpPath) ? (int) filesize($tmpPath) : -1;
    $expected = $partNo < $total ? (int) $s['part_bytes'] : (int) $s['byte_size'] - ($total - 1) * (int) $s['part_bytes'];
    if ($size !== $expected) {
        throw new UploadRefused(422, 'bad_part', 'Part ' . $partNo . ' is ' . $size . ' bytes; ' . $expected . ' expected.');
    }
    $dest = store_part_path($upload, $partNo);
    if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0770, true) && !is_dir(dirname($dest))) {
        throw new UploadRefused(503, 'storage', 'The landing area is not writable.');
    }
    if (!@rename($tmpPath, $dest)) {
        if (!@copy($tmpPath, $dest)) {
            throw new UploadRefused(503, 'storage', 'Part ' . $partNo . ' could not be kept.');
        }
        @unlink($tmpPath);
    }
    $pdo->prepare('INSERT INTO upload_parts (session_id, part_no, byte_size) VALUES (:s, :n, :b)
                   ON CONFLICT (session_id, part_no) DO UPDATE SET byte_size = EXCLUDED.byte_size, received_at = now()')
        ->execute(['s' => $upload, 'n' => $partNo, 'b' => $size]);
    $pdo->prepare('UPDATE upload_sessions SET parts_done = (SELECT count(*) FROM upload_parts WHERE session_id = :s) WHERE id = :s')->execute(['s' => $upload]);
    return ['part_no' => $partNo, 'parts_done' => (int) one_value($pdo, 'SELECT parts_done FROM upload_sessions WHERE id = :s', ['s' => $upload]), 'parts_total' => $total];
}

/**
 * Finish: the parts assembled in order (hashed as they go), the size and the claimed hash checked, the file landed by dl_landing_store()
 * (an executable refused; the same bytes landed once — `deduped`), the session done with its blob, the sniff queued for the writer.
 * Returns [status, blob_id, sha256, deduped, job_id].
 */
function upload_finish(PDO $pdo, int $upload): array
{
    $s = upload_session_of($pdo, $upload, (int) current_member_id()) ?? throw new UploadRefused(404, 'not_found', 'Not found.');
    if ($s['status'] !== 'open') {
        throw new UploadRefused(409, 'not_open', 'This upload is ' . $s['status'] . '.');
    }
    $total = (int) $s['parts_total'];
    $missing = [];
    for ($n = 1; $n <= $total; $n++) {
        if (!is_file(store_part_path($upload, $n))) {
            $missing[] = $n;
        }
    }
    if ($missing !== []) {
        throw new UploadRefused(422, 'missing_parts', 'Part ' . $missing[0] . ' is missing' . (count($missing) > 1 ? ' (and ' . (count($missing) - 1) . ' more)' : '') . '.', ['missing' => $missing]);
    }
    $pdo->prepare("UPDATE upload_sessions SET status = 'assembling' WHERE id = :s")->execute(['s' => $upload]);
    $assembled = store_parts_dir($upload) . '/assembled';
    $out = fopen($assembled, 'wb');
    if ($out === false) {
        throw new UploadRefused(503, 'storage', 'The file could not be assembled.');
    }
    $h = hash_init('sha256');
    $written = 0;
    for ($n = 1; $n <= $total; $n++) {
        $in = fopen(store_part_path($upload, $n), 'rb');
        while ($in !== false && !feof($in)) {
            $chunk = fread($in, 1048576);
            if ($chunk === false || $chunk === '') {
                break;
            }
            hash_update($h, $chunk);
            fwrite($out, $chunk);
            $written += strlen($chunk);
        }
        if ($in !== false) {
            fclose($in);
        }
    }
    fclose($out);
    $sha = hash_final($h);
    $fail = static function (string $why, string $code, string $message) use ($pdo, $upload, $assembled): never {
        @unlink($assembled);
        $pdo->prepare("UPDATE upload_sessions SET status = 'failed', error = :e WHERE id = :s")->execute(['e' => $why, 's' => $upload]);
        throw new UploadRefused(422, $code, $message);
    };
    if ($written !== (int) $s['byte_size']) {
        $fail('size mismatch', 'size_mismatch', 'The parts add up to ' . $written . ' bytes, not ' . $s['byte_size'] . '.');
    }
    if ($s['sha256_claimed'] !== null && $s['sha256_claimed'] !== $sha) {
        $fail('hash mismatch', 'hash_mismatch', 'The file\'s sha256 is not the one the upload claimed.');
    }
    try {
        $blob = dl_landing_store($pdo, $assembled, (string) $s['filename']);
    } catch (UploadRefused $e) {
        if ($e->reason === 'executable') {
            $pdo->prepare("UPDATE upload_sessions SET status = 'failed', error = 'a program, not data' WHERE id = :s")->execute(['s' => $upload]);
            store_drop_parts($upload);
        }
        throw $e;
    }
    $pdo->prepare("UPDATE upload_sessions SET status = 'done', blob_id = :b, sniff_status = 'queued', sniff = NULL WHERE id = :s")
        ->execute(['b' => $blob['blob_id'], 's' => $upload]);
    store_drop_parts($upload);
    $job = lake_enqueue($pdo, 'sniff', null, $upload, [], 2);
    return ['status' => 'done', 'blob_id' => $blob['blob_id'], 'sha256' => $blob['sha256'], 'deduped' => $blob['deduped'], 'job_id' => $job];
}

/** A file that arrived whole (the no-JavaScript form, the API): start + one part + finish. Returns upload_finish's answer + upload_id. */
function upload_one(PDO $pdo, int $member, string $filename, string $tmpPath): array
{
    $size = is_file($tmpPath) ? (int) filesize($tmpPath) : 0;
    $s = upload_start($pdo, $member, $filename, $size, null);
    if ($s['parts_total'] === 1) {
        upload_part($pdo, $s['upload_id'], 1, $tmpPath);
    } else {                                                    // bigger than one part: cut it as the browser would
        $in = fopen($tmpPath, 'rb');
        for ($n = 1; $n <= $s['parts_total']; $n++) {
            $piece = store_parts_dir($s['upload_id']) . '/incoming-' . $n;
            file_put_contents($piece, (string) fread($in, $s['part_bytes']));
            upload_part($pdo, $s['upload_id'], $n, $piece);
        }
        fclose($in);
    }
    return ['upload_id' => $s['upload_id']] + upload_finish($pdo, $s['upload_id']);
}

/** Discard an upload not loaded anywhere: its parts go, the session is expired (its landing blob, unreferenced, goes with the window). */
function upload_discard(PDO $pdo, int $upload): void
{
    $s = upload_session_of($pdo, $upload, (int) current_member_id()) ?? throw new DomainException('Not found.');
    $load = one_value($pdo, 'SELECT l.id FROM loads l WHERE l.upload_session_id = :u ORDER BY l.id DESC LIMIT 1', ['u' => $upload]);
    if ($load !== null) {
        throw new DomainException('This file was loaded — roll the load back instead|' . (int) $load);
    }
    if ($s['status'] === 'expired') {
        return;
    }
    $pdo->prepare("UPDATE upload_sessions SET status = 'expired', sniff = CASE WHEN sniff IS NULL THEN NULL ELSE sniff - 'sample' END WHERE id = :s")
        ->execute(['s' => $upload]);
    store_drop_parts($upload);
}

/**
 * The mapping as posted (or left out — the sniff's proposals, or a template's, fill what is not said), checked against the sniff: every
 * source column named or skipped; names lower-case identifiers, unique, never _dl_*; types from MAP_TYPES; personal kinds from
 * PERSONAL_KINDS; the dedupe key among the names; the sheet one of the file's. $in: target, target_dataset, name, mode, sheet, header_row,
 * columns {source: {name, type, personal_kind, skip}}, dedupe_key[], template (an applied template's row), save_template.
 * Returns [mapping, errors (field => sentence)].
 */
function map_validate(PDO $pdo, array $upload, array $in): array
{
    $errors = [];
    $sniff = $upload['sniff'] ?? null;
    if ($upload['status'] !== 'done' || $upload['sniff_status'] !== 'done' || !is_array($sniff) || isset($sniff['error'])) {
        return [[], ['upload' => 'The file has not been read yet — wait for "Reading the file…" to finish.']];
    }
    $tpl = $in['template'] ?? null;
    $tm = is_array($tpl) ? ($tpl['mapping'] ?? []) : [];
    $sheetNames = array_map(static fn (array $s): string => (string) $s['name'], $sniff['sheets'] ?? []);
    $sheet = $in['sheet'] ?? ($tm['sheet'] ?? null);
    if ($sheetNames !== [] && $sheet !== null && !in_array($sheet, $sheetNames, true)) {
        $errors['sheet'] = 'The workbook has the sheets ' . implode(', ', $sheetNames) . '.';
        $sheet = null;
    }
    $sh = sniff_sheet($sniff, $sheetNames === [] ? null : $sheet);
    $headerRow = (int) ($in['header_row'] ?? ($sniff['header_row'] ?? 1));
    if ($headerRow < 1 || $headerRow > 50) {
        $errors['header_row'] = 'The header row is from 1 to 50.';
    } elseif ($headerRow !== (int) ($sniff['header_row'] ?? 1)) {
        $errors['header_row'] = 'The file was read with its header on row ' . (int) ($sniff['header_row'] ?? 1) . ' — read it again from row ' . $headerRow . ' first.';
    }
    $given = is_array($in['columns'] ?? null) ? $in['columns'] : [];
    $fromTpl = is_array($tm['columns'] ?? null) ? $tm['columns'] : [];
    $cols = [];
    $names = [];
    foreach ($sh['columns'] as $i => $c) {
        $src = (string) $c['source_name'];
        $g = $given[$src] ?? ($given[(string) $i] ?? null);
        $t = $fromTpl[$src] ?? null;
        $skip = is_array($g) ? !empty($g['skip']) && $g['skip'] !== '0' : (is_array($t) && !empty($t['skip']));
        $name = strtolower(trim((string) (is_array($g) && isset($g['name']) ? $g['name'] : ($t['name'] ?? $c['name']))));
        $type = strtoupper(trim((string) (is_array($g) && isset($g['type']) ? $g['type'] : ($t['type'] ?? ($c['type'] ?? 'VARCHAR')))));
        $proposed = $c['proposed_personal_kind'] ?? null;
        $kind = is_array($g) && array_key_exists('personal_kind', $g) ? (string) $g['personal_kind'] : (string) ($t['personal_kind'] ?? ($proposed ?? ''));
        $field = 'columns[' . $src . ']';
        if (!$skip) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) || str_starts_with($name, '_dl_')) {
                $errors[$field . '[name]'] = 'Column "' . $src . '": a name is lower-case letters, digits and underscores, starting with a letter.';
            } elseif (isset($names[$name])) {
                $errors[$field . '[name]'] = 'Two columns are named ' . $name . ' — names are unique.';
            }
            $names[$name] = true;
            if (!isset(MAP_TYPES[$type])) {
                $errors[$field . '[type]'] = 'Column "' . $src . '": the type is one of ' . implode(', ', array_keys(MAP_TYPES)) . '.';
            }
            if ($kind !== '' && !isset(PERSONAL_KINDS[$kind])) {
                $errors[$field . '[personal_kind]'] = 'Column "' . $src . '": personal data is one of ' . implode(', ', array_keys(PERSONAL_KINDS)) . ', or none.';
            }
        }
        $cols[$src] = ['name' => $name, 'type' => $type, 'personal_kind' => $kind === '' ? null : $kind, 'skip' => $skip,
                       'proposed' => $proposed, 'confidence' => $c['confidence'] ?? null];
    }
    $kept = array_filter($cols, static fn (array $c): bool => !$c['skip']);
    if ($kept === []) {
        $errors['columns'] = 'Keep at least one column.';
    }
    $byIndex = array_values($cols);
    $dedupe = [];
    foreach ((array) ($in['dedupe_key'] ?? ($tm['dedupe_key'] ?? [])) as $k) {        // a name, or "#i" — the i-th source column (the form's checkbox)
        $k = (string) $k;
        if (preg_match('/^#(\d+)$/', $k, $m)) {
            $c = $byIndex[(int) $m[1]] ?? null;
            $k = $c === null || $c['skip'] ? '' : $c['name'];
        }
        if ($k !== '' && !in_array($k, $dedupe, true)) {
            $dedupe[] = $k;
        }
    }
    foreach ($dedupe as $k) {
        if (!isset($names[$k])) {
            $errors['dedupe_key'] = 'The key names columns of this mapping (' . $k . ' is not one).';
        }
    }
    $target = (string) ($in['target'] ?? 'sandbox');
    if (!in_array($target, ['sandbox', 'raw'], true)) {
        $errors['target'] = 'The target is my sandbox or a raw dataset.';
    }
    $mode = (string) ($in['mode'] ?? 'append');
    if (!in_array($mode, ['append', 'replace'], true)) {
        $errors['mode'] = 'The mode is append or replace.';
    }
    $readOptions = (array) ($sniff['read_options'] ?? []) + ['format' => $sniff['format']];
    $readOptions['format'] = (string) $sniff['format'];
    if ($sh['name'] !== null) {
        $readOptions += ['sheet' => $sh['name'], 'header_rows' => $sh['header_rows'], 'skip_rows' => $sh['skip_rows']];
    }
    $mapping = ['format' => (string) $sniff['format'], 'sheet' => $sh['name'], 'header_row' => $headerRow, 'read_options' => $readOptions,
                'columns' => array_map(static fn (array $c): array => ['name' => $c['name'], 'type' => $c['type'], 'personal_kind' => $c['personal_kind'], 'skip' => $c['skip']], $cols),
                'dedupe_key' => $dedupe, 'target' => $target, 'mode' => $mode,
                'name' => strtolower(trim((string) ($in['name'] ?? ''))), 'target_dataset' => $in['target_dataset'] ?? null,
                'template_id' => is_array($tpl) ? (int) $tpl['template_id'] : null, 'save_template' => trim((string) ($in['save_template'] ?? '')),
                '_decided' => $cols];
    return [$mapping, $errors];
}

/** A template's kind from the sniffed format and the file's extension. */
function template_kind_of(string $format, string $filename): string
{
    return match ($format) { 'workbook' => 'xlsx', 'json' => 'json', 'parquet' => 'parquet', default => 'csv' };
}

/**
 * Confirm a mapping: the dataset (my sandbox's `sandbox.m{me}_{name}`, made when new; or an engineer's raw dataset — one I may write, or a
 * new `raw.{name}`), the load (`idempotency_key` = `{dataset}:{sha256}` — this file already in that dataset answers "already loaded" with the
 * load), the columns a person decided (a kept or chosen personal kind confirmed as theirs; a proposal cleared confirmed as none), a template
 * saved, the load queued for the writer. All in one transaction. Returns [dataset_id, dataset_key, load_id, job_id, created].
 */
function map_confirm(PDO $pdo, array $upload, array $mapping, int $by): array
{
    $me = $by;
    $target = $mapping['target'];
    $name = $mapping['name'];
    $created = false;
    if ($target === 'sandbox') {
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name)) {
            throw new FieldRefused(['name' => 'Name your dataset: lower-case letters, digits and underscores, starting with a letter (at most 40).']);
        }
        $key = 'sandbox.m' . $me . '_' . $name;
        $usage = $pdo->query('SELECT used_bytes, quota_bytes FROM dl_sandbox_usage(' . $me . ')')->fetch();
        if ((int) $usage['used_bytes'] + (int) $upload['byte_size'] > (int) $usage['quota_bytes']) {
            throw new FieldRefused(['target' => 'Your sandbox is full (' . bytes_words((int) $usage['used_bytes']) . ' of ' . bytes_words((int) $usage['quota_bytes'])
                . ') — retire a dataset you no longer need first.']);
        }
    } else {
        if (!has_right('loads.manage')) {
            throw new FieldRefused(['target' => 'Loading into a raw dataset is a data engineer\'s (loads.manage).']);
        }
        if (($mapping['target_dataset'] ?? null) !== null && (string) $mapping['target_dataset'] !== '') {
            $d = require_dataset($pdo, (string) $mapping['target_dataset'], 'write');
            if ($d['zone'] !== 'raw') {
                throw new FieldRefused(['target_dataset' => 'A load lands in a raw dataset (' . $d['key'] . ' is ' . $d['zone'] . ').']);
            }
            $key = (string) $d['key'];
        } else {
            if (!preg_match(DATASET_NAME_RE, $name)) {
                throw new FieldRefused(['name' => 'Name the raw dataset: lower-case letters, digits and underscores, starting with a letter.']);
            }
            $key = 'raw.' . $name;
        }
    }
    $pdo->beginTransaction();
    $row = one_row($pdo, 'SELECT id, owner_member_id, status FROM datasets WHERE key = :k', ['k' => $key]);
    if ($row !== null && ($row['status'] === 'retired' || ($target === 'sandbox' && (int) $row['owner_member_id'] !== $me))) {
        $pdo->rollBack();
        throw new FieldRefused(['name' => (string) (one_value($pdo, 'SELECT dl_dataset_key_taken(:k)', ['k' => $key]) ?? $key . ' is taken — choose another name')]);
    }
    if ($row !== null && $target === 'raw' && ($mapping['target_dataset'] ?? null) === null) {
        $d = require_dataset($pdo, $key, 'write');                     // an existing raw dataset named by name: the same wall as the picker
    }
    if ($row === null) {
        [$zone, $nm] = explode('.', $key, 2);
        $title = trim((string) preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', (string) $upload['filename'])) ?: $nm;
        $st = $pdo->prepare("INSERT INTO datasets (key, zone, name, title, description, owner_member_id, status, evolution_mode, dedupe_key, created_by)
                             VALUES (:k, :z, :n, :t, :d, :o, 'active', 'rescue', CAST(:dk AS text[]), :o) RETURNING id");
        $st->execute(['k' => $key, 'z' => $zone, 'n' => $nm, 't' => mb_substr($title, 0, 200),
            'd' => $zone === 'sandbox' ? 'Made from an upload.' : 'Loaded from an upload.', 'o' => $me, 'dk' => text_array_literal($mapping['dedupe_key'])]);
        $datasetId = (int) $st->fetchColumn();
        $created = true;
    } else {
        $datasetId = (int) $row['id'];
    }
    $sha = (string) $upload['sha256'];
    $existing = one_row($pdo, 'SELECT id, status FROM loads WHERE idempotency_key = :k', ['k' => $datasetId . ':' . $sha]);
    if ($existing !== null) {
        $pdo->rollBack();
        throw new DomainException('This file is already loaded into ' . $key . '|' . (int) $existing['id']);
    }
    // the columns a person decided, on a NEW dataset (the load creates exactly these). An existing dataset's columns are the engine's: a column
    // its file brings new is drift — rescued or added by the writer, never registered here before the lake has it (the policy's views name
    // only columns the table has; the profile's classifier proposes on what was added)
    $have = [];
    if (!$created) {
        $have = array_flip(array_map(static fn (array $c): string => (string) $c['name'], $mapping['_decided']));
    }
    $ins = $pdo->prepare('INSERT INTO dataset_columns (dataset_id, name, ordinal, personal_kind, classified_by, classification_confirmed_by, classification_confirmed_at,
                                                       proposed_personal_kind, proposed_confidence)
                          VALUES (:d, :n, :o, :k, :cb, :by, CASE WHEN CAST(:by AS bigint) IS NULL THEN NULL ELSE now() END, :pk, :pc)');
    $ordinal = 0;
    $proposals = 0;
    foreach ($mapping['_decided'] as $c) {
        if ($c['skip']) {
            continue;
        }
        $proposals += $c['proposed'] !== null ? 1 : 0;
        if (isset($have[$c['name']])) {
            $ordinal++;
            continue;
        }
        $decided = $c['personal_kind'] !== null || $c['proposed'] !== null;          // a kind chosen or kept, or a proposal cleared
        $ins->execute(['d' => $datasetId, 'n' => $c['name'], 'o' => $ordinal++, 'k' => $c['personal_kind'], 'cb' => $decided ? 'person' : 'rule',
            'by' => $decided ? $me : null, 'pk' => $decided ? null : $c['proposed'], 'pc' => $decided ? null : $c['confidence']]);
    }
    $templateId = $mapping['template_id'];
    if ($mapping['save_template'] !== '') {
        $tm = ['sheet' => $mapping['sheet'], 'header_row' => $mapping['header_row'], 'columns' => $mapping['columns'], 'dedupe_key' => $mapping['dedupe_key']];
        $st = $pdo->prepare('INSERT INTO import_templates (name, kind, mapping, created_by) VALUES (:n, :k, CAST(:m AS jsonb), :by) ON CONFLICT (name) DO NOTHING RETURNING id');
        $st->execute(['n' => mb_substr($mapping['save_template'], 0, 120), 'k' => template_kind_of($mapping['format'], (string) $upload['filename']),
            'm' => json_encode($tm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'by' => $me]);
        $tid = $st->fetchColumn();
        if ($tid === false) {
            $pdo->rollBack();
            throw new FieldRefused(['save_template' => 'A template named ' . $mapping['save_template'] . ' exists — choose another name.']);
        }
        $templateId = (int) $tid;
    }
    $stored = $mapping;
    unset($stored['_decided'], $stored['save_template'], $stored['name'], $stored['target_dataset']);
    $st = $pdo->prepare("INSERT INTO loads (dataset_id, upload_session_id, blob_id, landing_sha256, template_id, mode, idempotency_key, mapping, requested_by, agent_run_id)
                         VALUES (:d, :u, :b, :s, :t, :m, :k, CAST(:map AS jsonb), :by, :run) RETURNING id");
    $st->execute(['d' => $datasetId, 'u' => (int) $upload['id'], 'b' => (int) $upload['blob_id'], 's' => $sha, 't' => $templateId, 'm' => $mapping['mode'],
        'k' => $datasetId . ':' . $sha, 'map' => json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'by' => $me, 'run' => current_agent_run_id()]);
    $loadId = (int) $st->fetchColumn();
    $pdo->prepare('UPDATE upload_sessions SET target_dataset_id = :d WHERE id = :u')->execute(['d' => $datasetId, 'u' => (int) $upload['id']]);
    $job = lake_enqueue($pdo, 'load', $datasetId, $loadId, [], 3);
    log_activity($pdo, 'upload.map', 'load', $loadId, ['dataset_id' => $datasetId, 'after' => ['dataset' => $key, 'target' => $target, 'mode' => $mapping['mode'],
        'columns' => count(array_filter($mapping['_decided'], static fn (array $c): bool => !$c['skip'])), 'skipped' => count(array_filter($mapping['_decided'], static fn (array $c): bool => $c['skip'])),
        'personal_proposals' => $proposals, 'created' => $created, 'template_id' => $templateId, 'upload_id' => (int) $upload['id']]]);
    $pdo->commit();
    return ['dataset_id' => $datasetId, 'dataset_key' => $key, 'load_id' => $loadId, 'job_id' => $job, 'created' => $created, 'template_id' => $templateId];
}

/** A refusal naming fields (the handler answers it with dl_refuse_fields). */
final class FieldRefused extends DomainException
{
    public function __construct(public readonly array $fields)
    {
        parent::__construct((string) (array_values($fields)[0] ?? 'That could not be saved.'));
    }
}
