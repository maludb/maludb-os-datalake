<?php
declare(strict_types=1);

/**
 * The sources' writes (slice 6; replicates slice 1's catalog writes): source_create / source_update (source_validate + source_save),
 * source_credential_set (sealed — dl_seal() is the only thing that sees the secret), source_pause, source_resume, source_delete, source_test
 * and source_pull_now (source_pull_queue). Every function changes rows and nothing else — the handler has checked the right and logs and lands.
 * THE DATABASE IS THE REFEREE where it can be: the connector's name, the postgres guard (a postgres source never reads this server's own
 * databases, a K7 source names its provider — dl_sources_guard), the role it forces; PHP's validate() per connector speaks to the form.
 * Nothing here pulls: a pull is the PHP worker's (app/lake/fetch/pulls.php), a load the writer's.
 */
require_once dirname(__DIR__, 2) . '/lake/fetch/pulls.php';
require_once dirname(__DIR__) . '/catalog/queries.php';      // DATASET_NAME_RE, text_array_literal
require_once dirname(__DIR__) . '/catalog/write.php';        // dataset_save()
require_once __DIR__ . '/queries.php';                       // find_source, for the handlers' prelude
require_once __DIR__ . '/present.php';                       // url_display, schedule_words

const SOURCE_MAX_MINUTES = 525600;
/** The request keys that are the source's own, not a connector's setting. */
const SOURCE_META_KEYS = ['name', 'connector', 'target_dataset', 'target_name', 'schedule_minutes', 'rate_per_second', 'user_agent', 'settings', 'source', 'return_to', 'csrf_token', '_csrf'];

/** The log-safe summary of a source's settings: hosts, names and counts — never a URL's query, a path's secret or a credential. */
function source_log_settings(string $connector, array $settings): array
{
    $out = [];
    switch ($connector) {
        case 'http_file':
            $out = ['url' => url_display($settings['url'] ?? null), 'format' => $settings['format'] ?? null, 'template_id' => $settings['template_id'] ?? null];
            break;
        case 'sftp_file':
            $s = (array) ($settings['sftp'] ?? []);
            $out = ['host' => $s['host'] ?? null, 'port' => $s['port'] ?? null, 'user' => $s['user'] ?? null, 'path' => $s['path'] ?? null, 'pattern' => $settings['pattern'] ?? null, 'format' => $settings['format'] ?? null];
            break;
        case 'k7':
            $out = ['provider' => $settings['provider'] ?? null, 'tool' => $settings['tool'] ?? null, 'paging' => $settings['paging'] ?? null, 'window' => $settings['window'] ?? null];
            break;
        case 'postgres':
            $out = ['host' => $settings['host'] ?? null, 'port' => $settings['port'] ?? null, 'dbname' => $settings['dbname'] ?? null, 'table' => $settings['table'] ?? null, 'watermark' => $settings['watermark'] ?? null];
            break;
        default:
            $out = ['template_id' => $settings['template_id'] ?? null];
    }
    return array_filter($out, static fn ($v) => $v !== null);
}

/**
 * The raw dataset a source lands in, named by the request: an id, a key (raw.name) or a bare name. An existing one must be raw and the caller's
 * to write ($checkLevel); a new one needs $mayCreate (loads.manage, as an upload's raw target does). Returns ['id' => ?int, 'key', 'create' => bool], or null with a sentence in $errors.
 */
function source_target_check(PDO $pdo, string $ref, bool $mayCreate, bool $checkLevel, array &$errors): ?array
{
    $ref = trim($ref);
    $byId = ctype_digit($ref) && strlen($ref) <= 18;
    $key = $byId ? null : (str_contains($ref, '.') ? $ref : 'raw.' . $ref);
    if (!$byId && !preg_match(DATASET_KEY_RE, (string) $key)) {
        $errors['target_dataset'] = 'Name the raw dataset it lands in: lower-case letters, digits and underscores, starting with a letter.';
        return null;
    }
    $d = dataset_by_ref($pdo, $byId ? $ref : (string) $key);
    if ($d === null) {
        if ($byId) {
            $errors['target_dataset'] = 'That dataset is not here.';
            return null;
        }
        if (!str_starts_with((string) $key, 'raw.')) {
            $errors['target_dataset'] = 'A source lands in a RAW dataset — ' . $key . ' is not one.';
            return null;
        }
        if (!$mayCreate) {
            $errors['target_dataset'] = 'Making a raw dataset is a data engineer\'s (loads.manage) — choose one that exists.';
            return null;
        }
        return ['id' => null, 'key' => (string) $key, 'create' => true];
    }
    $level = $checkLevel ? dl_level_rank(dataset_level($pdo, (int) $d['id'])) : DL_LEVELS['own'];
    if ($level < DL_LEVELS['discover']) {                       // a dataset the person may not even know is no dataset to them (the gate's rule)
        $errors['target_dataset'] = $byId ? 'That dataset is not here.' : 'Choose a raw dataset you may write, or name a new one.';
        return null;
    }
    if ($d['zone'] !== 'raw' || $d['status'] === 'retired') {
        $errors['target_dataset'] = $d['status'] === 'retired' ? $d['key'] . ' is retired.' : 'A source lands in a RAW dataset — ' . $d['key'] . ' is ' . $d['zone'] . '.';
        return null;
    }
    if ($level < DL_LEVELS['write']) {
        $errors['target_dataset'] = 'You may not change ' . $d['key'] . ' — choose a raw dataset you may write.';
        return null;
    }
    return ['id' => (int) $d['id'], 'key' => (string) $d['key'], 'create' => false];
}

/**
 * Validate a source from what a form or an agent sent. $in: name, target_dataset (id | key | name) or target_name, schedule_minutes, rate_per_second,
 * user_agent, settings (an object or its JSON) and/or the connector's flat fields. A field left out stays as $current (the source row, its settings
 * decoded) has it. Returns [fields for source_save, errors (field => sentence)].
 */
function source_validate(PDO $pdo, string $connector, array $in, ?array $current, bool $mayCreate = true, bool $checkLevel = true): array
{
    $errors = [];
    try {
        $conn = dl_fetch_connector($connector);
    } catch (DlMisconfigured $e) {
        return [[], ['connector' => str_starts_with($e->getMessage(), 'connector_extended') ? 'connector_extended: the ' . $connector . ' connector is Extended — version 1 ships ' . implode(', ', DL_CONNECTORS_V1) . '.' : 'The connector is one of ' . implode(', ', DL_CONNECTORS_V1) . '.']];
    }
    $caps = $conn->capabilities();
    $name = array_key_exists('name', $in) ? trim((string) $in['name']) : (string) ($current['name'] ?? '');
    if ($name === '') {
        $errors['name'] = 'The name is required.';
    } elseif (mb_strlen($name) > 200) {
        $errors['name'] = 'The name is up to 200 characters.';
    }
    $given = $in['settings'] ?? [];
    if (is_string($given)) {
        $given = trim($given) === '' ? [] : json_decode($given, true);
        if (!is_array($given)) {
            $errors['settings'] = 'The settings are a JSON object.';
            $given = [];
        }
    }
    $flat = array_diff_key($in, array_flip(SOURCE_META_KEYS));
    $cur = (array) ($current['settings'] ?? []);
    unset($cur['target_dataset_id']);
    [$settings, $sErrors] = $conn->validate(array_merge((array) $given, $flat), $cur);
    $errors += $sErrors;
    // the minutes between pulls (0 = manual); a source that pulls nothing has none
    $minutes = (int) ($current['schedule_minutes'] ?? 0);
    if (!$caps['pulls']) {
        $minutes = 0;
    } elseif (array_key_exists('schedule_minutes', $in)) {
        $raw = trim((string) $in['schedule_minutes']);
        if ($raw === '' || !ctype_digit($raw) || (int) $raw > SOURCE_MAX_MINUTES) {
            $errors['schedule_minutes'] = 'The minutes between pulls are a whole number from 0 (manual) to ' . SOURCE_MAX_MINUTES . '.';
        } else {
            $minutes = (int) $raw;
        }
    }
    $rate = (float) ($current['rate_per_second'] ?? 1);
    if (array_key_exists('rate_per_second', $in) && $in['rate_per_second'] !== '') {
        $r = (string) $in['rate_per_second'];
        if (!is_numeric($r) || (float) $r < 0.1 || (float) $r > 10) {
            $errors['rate_per_second'] = 'The rate is from 0.1 to 10 requests a second.';
        } else {
            $rate = round((float) $r, 2);
        }
    }
    $ua = array_key_exists('user_agent', $in) ? trim((string) $in['user_agent']) : (string) ($current['user_agent'] ?? '');
    if ($ua !== '' && ($connector !== 'http_file' || mb_strlen($ua) > 200 || preg_match('~[\x00-\x1f]~', $ua))) {
        $errors['user_agent'] = $connector !== 'http_file' ? 'Only a file at a URL has a user-agent.' : 'The user-agent is up to 200 characters on one line.';
    }
    // the saved mapping a file-reading source applies must exist
    if (isset($settings['template_id']) && one_value($pdo, 'SELECT 1 FROM import_templates WHERE id = :id', ['id' => (int) $settings['template_id']]) === null) {
        $errors['template_id'] = 'That saved mapping is not here.';
    }
    // the target
    $ref = trim((string) ($in['target_dataset'] ?? ''));
    if ($ref === '') {
        $ref = trim((string) ($in['target_name'] ?? ''));
    }
    $target = null;
    if ($ref !== '') {
        $target = source_target_check($pdo, $ref, $mayCreate, $checkLevel, $errors);
    } elseif (isset($current['settings']['target_dataset_id'])) {
        $target = ['id' => (int) $current['settings']['target_dataset_id'], 'key' => null, 'create' => false];
    } elseif ($connector !== 'upload') {
        $errors['target_dataset'] = 'Choose the raw dataset it lands in, or name a new one.';
    }
    $base = null;
    if ($connector === 'http_file' && isset($settings['url'])) {
        $p = parse_url((string) $settings['url']);
        $base = isset($p['host']) ? ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') : null;
    }
    return [['name' => $name, 'connector' => $connector, 'settings' => $settings, 'target' => $target, 'schedule_minutes' => $minutes, 'rate_per_second' => $rate,
             'user_agent' => $ua === '' ? null : $ua, 'base_url' => $base], $errors];
}

/** Make the raw dataset a source lands in (status active, rescue, the key as given) — through the same insert an upload's raw target uses. Returns its id. */
function source_make_target(PDO $pdo, string $key, string $title, ?array $dedupe, int $by): int
{
    [, $name] = explode('.', $key, 2);
    $id = dataset_save($pdo, null, ['zone' => 'raw', 'name' => $name, 'title' => mb_substr($title, 0, 200), 'description' => 'Loaded from a source.', 'owner_member_id' => $by, 'tier' => '2'], $by);
    $pdo->prepare("UPDATE datasets SET status = 'active', evolution_mode = 'rescue', dedupe_key = CAST(:dk AS text[]) WHERE id = :id")
        ->execute(['dk' => text_array_literal($dedupe ?? []), 'id' => $id]);
    return $id;
}

/**
 * Create (id null) or change a source. $f from source_validate(): name, connector, settings, target (['id'|null, 'key', 'create'] | null),
 * schedule_minutes, rate_per_second, user_agent, base_url. The target is made when new (the postgres source's key[] is its dedupe key); the dataset's
 * own `source_id` is set when it has none. One transaction is the caller's. Returns the source id.
 */
function source_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $settings = (array) $f['settings'];
    $target = $f['target'] ?? null;
    $targetId = null;
    if ($target !== null) {
        $targetId = $target['create'] ? source_make_target($pdo, (string) $target['key'], (string) $f['name'], $f['dedupe_key'] ?? ($f['connector'] === 'postgres' ? (array) ($settings['key'] ?? []) : null), $by) : (int) $target['id'];
        $settings['target_dataset_id'] = $targetId;
    } elseif ($id !== null) {
        $keep = one_value($pdo, "SELECT settings ->> 'target_dataset_id' FROM sources WHERE id = :id", ['id' => $id]);
        if ($keep !== null) {
            $settings['target_dataset_id'] = (int) $keep;
            $targetId = (int) $keep;
        }
    }
    $json = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO sources (name, connector, role, base_url, settings, schedule_minutes, rate_per_second, user_agent, created_by)
                             VALUES (:n, :c, :r, :u, CAST(:s AS jsonb), :m, :rate, :ua, :by) RETURNING id');
        $st->execute(['n' => $f['name'], 'c' => $f['connector'], 'r' => $f['connector'] === 'upload' ? 'manual' : 'public_data', 'u' => $f['base_url'], 's' => $json,
                      'm' => $f['schedule_minutes'], 'rate' => $f['rate_per_second'], 'ua' => $f['user_agent'], 'by' => $by]);
        $id = (int) $st->fetchColumn();
    } else {
        $pdo->prepare('UPDATE sources SET name = :n, base_url = :u, settings = CAST(:s AS jsonb), schedule_minutes = :m, rate_per_second = :rate, user_agent = :ua WHERE id = :id')
            ->execute(['n' => $f['name'], 'u' => $f['base_url'], 's' => $json, 'm' => $f['schedule_minutes'], 'rate' => $f['rate_per_second'], 'ua' => $f['user_agent'], 'id' => $id]);
    }
    if ($targetId !== null) {
        $pdo->prepare('UPDATE datasets SET source_id = :s WHERE id = :d AND source_id IS NULL')->execute(['s' => $id, 'd' => $targetId]);
    }
    return $id;
}

/**
 * Seal and keep a source's credential (a new row, the old one deleted, a source paused for want of one resumed). Only the secret is sealed; the
 * label is for the screen. Throws DomainException with the sentence for a kind the connector does not take, an empty secret, or a missing secrets
 * key (`secrets_key_missing: …`). Returns nothing — the handler reads the credential's columns afterwards.
 */
function source_credential_set(PDO $pdo, int $id, string $kind, string $secret, ?string $label, int $by): void
{
    $s = one_row($pdo, 'SELECT id, connector, credential_id FROM sources WHERE id = :id FOR UPDATE', ['id' => $id]) ?? throw new DomainException('Not found.');
    $kinds = dl_fetch_connector((string) $s['connector'])->capabilities()['credential_kinds'];
    if ($kinds === []) {
        throw new DomainException('The ' . $s['connector'] . ' connector takes no credential.');
    }
    if (!in_array($kind, $kinds, true)) {
        throw new DomainException('The ' . $s['connector'] . ' connector takes ' . implode(' or ', $kinds) . ', not ' . ($kind === '' ? 'nothing' : $kind) . '.');
    }
    $secret = $kind === 'sftp_key' ? trim($secret) : $secret;
    if ($secret === '' || strlen($secret) > 20000) {
        throw new DomainException('Give the secret (up to 20,000 characters).');
    }
    $label = mb_substr(trim((string) $label) ?: str_replace('_', ' ', $kind), 0, 120);
    $cred = ['kind' => $kind, 'label' => $label, dl_credential_secret_field($kind) => $secret];
    try {
        $sealed = dl_seal($cred);
    } catch (DlCredentialError $e) {
        throw new DomainException(str_starts_with($e->getMessage(), 'secrets_key_missing') ? 'secrets_key_missing: this installation has no secrets key (DL_SECRETS_KEY) — a credential cannot be kept until the owner sets one.' : 'The credential could not be sealed.');
    }
    $sum = dl_credential_summary($cred);
    $old = $s['credential_id'];
    $st = $pdo->prepare("INSERT INTO source_credentials (source_id, kind, label, last4, ciphertext, created_by) VALUES (:s, :k, :l, :f, convert_to(:c, 'UTF8'), :b) RETURNING id");
    $st->execute(['s' => $id, 'k' => $kind, 'l' => $label, 'f' => $sum['last4'] === '' ? '----' : $sum['last4'], 'c' => $sealed, 'b' => $by]);
    $new = (int) $st->fetchColumn();
    $pdo->prepare('UPDATE sources SET credential_id = :c WHERE id = :s')->execute(['c' => $new, 's' => $id]);
    if ($old !== null) {
        $pdo->prepare('DELETE FROM source_credentials WHERE id = :o')->execute(['o' => $old]);
    }
    $pdo->prepare("UPDATE sources SET paused_at = NULL, paused_reason = NULL, consecutive_failures = 0, backoff_until = NULL WHERE id = :s AND paused_reason LIKE 'credential:%'")->execute(['s' => $id]);
}

/** Pause a source (it is not pulled until resumed). Throws when it already is. */
function source_pause(PDO $pdo, int $id, ?string $reason, int $by): void
{
    $st = $pdo->prepare("UPDATE sources SET paused_at = now(), paused_reason = :r WHERE id = :id AND paused_at IS NULL");
    $st->execute(['r' => mb_substr(trim((string) $reason) ?: 'Paused by a person', 0, 300), 'id' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Already paused.');
    }
}

/** Resume a paused source: the ladder cleared, the next pull at its schedule (or now, if one is due). Throws when it is not paused. */
function source_resume(PDO $pdo, int $id, int $by): void
{
    $st = $pdo->prepare('UPDATE sources SET paused_at = NULL, paused_reason = NULL, consecutive_failures = 0, backoff_until = NULL WHERE id = :id AND paused_at IS NOT NULL');
    $st->execute(['id' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('It is not paused.');
    }
}

/** Delete a source: its pulls and credential go with it, the datasets it fed STAY (their loads lose the source). Refused while a pull runs. */
function source_delete(PDO $pdo, int $id, int $by): void
{
    if (one_value($pdo, "SELECT 1 FROM source_pulls WHERE source_id = :s AND status = 'running' AND started_at > now() - interval '" . DL_PULL_STUCK_HOURS . " hours'", ['s' => $id]) !== null) {
        throw new DomainException('A pull is running — pause the source and delete it once the pull is done.');
    }
    $st = $pdo->prepare('DELETE FROM sources WHERE id = :id');
    $st->execute(['id' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Not found.');
    }
}

/** Ask the connector (probe): no pull row, nothing landed. Returns ['state' => ok|blocked|misconfigured, 'message', 'facts']. Logged source.test by the handler. */
function source_test(PDO $pdo, int $id): array
{
    $work = sys_get_temp_dir() . '/dl-probe-' . bin2hex(random_bytes(5));
    try {
        $row = one_row($pdo, 'SELECT connector FROM sources WHERE id = :id', ['id' => $id]) ?? throw new DomainException('Not found.');
        $source = dl_source_for_connector($pdo, $id, ['work_dir' => $work]);
        if (dl_fetch_connector((string) $row['connector'])->capabilities()['needs_credential'] && $source['credential'] === null) {
            return dl_probe_result('misconfigured', 'It needs a credential — set one first.');
        }
        return dl_fetch_connector((string) $row['connector'])->probe($source);
    } catch (DlCredentialError $e) {
        return dl_probe_result('misconfigured', str_starts_with($e->getMessage(), 'secrets_key_missing') ? 'This installation has no secrets key (DL_SECRETS_KEY), so the credential cannot be opened.' : 'The credential cannot be opened — set it again.');
    } catch (DlMisconfigured $e) {
        return dl_probe_result('misconfigured', mb_substr($e->getMessage(), 0, 200) . '.');
    } catch (DomainException $e) {
        throw $e;
    } catch (Throwable $e) {
        error_log('source test ' . $id . ': ' . $e->getMessage());
        return dl_probe_result('misconfigured', 'The test could not be made (' . mb_substr($e->getMessage(), 0, 120) . ').');
    }
}

/** Queue a pull for the worker (a source_pulls row with policy.queued — the worker takes it within the minute). Refuses a pull that pulls nothing, a paused source, a pull already queued or running. Returns the pull's id. */
function source_pull_queue(PDO $pdo, int $id, int $by): int
{
    $s = one_row($pdo, 'SELECT id, name, connector, active, paused_at, paused_reason FROM sources WHERE id = :id FOR UPDATE', ['id' => $id]) ?? throw new DomainException('Not found.');
    if (!dl_fetch_connector((string) $s['connector'])->capabilities()['pulls']) {
        throw new DomainException('manual_only: ' . $s['name'] . ' is a named shape for people\'s uploads — there is nothing to pull.');
    }
    if (!$s['active']) {
        throw new DomainException($s['name'] . ' is switched off.');
    }
    if ($s['paused_at'] !== null) {
        throw new DomainException($s['name'] . ' is paused (' . ($s['paused_reason'] ?? 'no reason given') . ') — resume it first.');
    }
    if (one_value($pdo, "SELECT 1 FROM source_pulls WHERE source_id = :s AND status = 'running' AND started_at > now() - interval '" . DL_PULL_STUCK_HOURS . " hours'", ['s' => $id]) !== null) {
        throw new DomainException('A pull of ' . $s['name'] . ' is already queued or running.');
    }
    $pull = (int) one_value($pdo, "INSERT INTO source_pulls (source_id, kind, started_by, policy) VALUES (:s, 'manual', :b, '{\"queued\": true}'::jsonb) RETURNING id", ['s' => $id, 'b' => $by]);
    $pdo->prepare('UPDATE sources SET last_pull_id = :p WHERE id = :s')->execute(['p' => $pull, 's' => $id]);
    return $pull;
}

/**
 * The prelude of the per-source action handlers (test, pull, pause, resume, delete, credential): POST + login + CSRF, sources.manage, the source
 * (404 when the view does not show it to this person). Returns [PDO, the source (find_source), the acting member, the target dataset's id or null].
 */
function source_action_begin(): array
{
    dl_handler_begin();
    require_right('sources.manage');
    $pdo = db();
    $id = request_integer('source') ?? request_integer('id') ?? 0;
    $s = $id > 0 ? find_source($pdo, $id) : null;
    if ($s === null) {
        respond_not_found('No such source.');
    }
    $target = isset($s['settings']['target_dataset_id']) ? (int) $s['settings']['target_dataset_id'] : null;
    return [$pdo, $s, (int) current_member_id(), $target];
}
