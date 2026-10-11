<?php
declare(strict_types=1);

/**
 * A person's result KEPT (slice 3 — query-service-editor.md divergence 3; THE SECOND EXEMPLAR's job pattern, which slice 7's transforms copy):
 * the SQL dry-run first through the one door (the allow-list and the policy: a refusal is the service's sentence on the `sql` field, nothing
 * queued), the datasets it reads taken from that parse, the person's policy COMPILED BY THE DATABASE NOW and frozen into the writer's job
 * (dl_query_policy(member, on_behalf_of, keys, 'transform', ceiling) — never at the job's run), the output's row made here with the highest
 * classification of its inputs as its floor, and the writer's `sandbox_save` job queued. The result is the person's.
 *
 *   sandbox_save($pdo, $member, $name, $sql, $title, $asOf) → [dataset_id, job_id, key]
 *   frozen_policy($pdo, $member, $keys, $source)            → the policy as the database compiles it now (no salt — the writer adds it)
 */
require_once dirname(__DIR__) . '/uploads/write.php';     // FieldRefused (slice 2's)

const CLASS_ORDER = ['public' => 0, 'internal' => 1, 'confidential' => 2, 'restricted' => 3];

function frozen_policy(PDO $pdo, int $member, array $keys, string $source): array
{
    $v = one_value($pdo, 'SELECT dl_query_policy(:m, :o, CAST(:k AS text[]), :s, :c)::text',
        ['m' => $member, 'o' => lake_on_behalf_of(), 'k' => text_array_literal($keys), 's' => $source, 'c' => lake_ceiling()]);
    return json_decode((string) $v, true) ?: [];
}

/** The SQL a job will run, dry-run now: its datasets, or a FieldRefused on `sql` in the service's own words. */
function dry_run_for_job(PDO $pdo, int $member, string $sql, int|string|null $asOf, array $params, ?array $saved = null): array
{
    $r = run_sql($pdo, $member, $sql, $asOf, $params, ['dry_run' => true, 'request_id' => editor_request_id()] + ($saved === null ? [] : ['saved_query' => $saved]));
    if ($r['status'] !== 'ok') {
        throw new FieldRefused(['sql' => ucfirst((string) $r['refusal']['message'])]);
    }
    if ($r['datasets'] === []) {
        throw new FieldRefused(['sql' => 'Name at least one dataset — the result is made from the lake\'s data.']);
    }
    return $r['datasets'];
}

function sandbox_save(PDO $pdo, int $member, string $name, string $sql, ?string $title, int|string|null $asOf, array $params = []): array
{
    if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name)) {
        throw new FieldRefused(['name' => 'Name the dataset: lower-case letters, digits and underscores, starting with a letter (at most 40).']);
    }
    $key = 'sandbox.m' . $member . '_' . $name;
    $keys = dry_run_for_job($pdo, $member, $sql, $asOf, $params);
    if (in_array($key, $keys, true)) {
        throw new FieldRefused(['name' => 'The result cannot replace a dataset it reads — choose another name.']);
    }
    $policy = frozen_policy($pdo, $member, $keys, 'transform');
    foreach ($keys as $k) {
        if (!isset($policy['datasets'][$k])) {
            throw new FieldRefused(['sql' => ucfirst((string) ($policy['refused'][$k]['message'] ?? 'you may not read ' . $k))]);
        }
    }
    $usage = $pdo->query('SELECT used_bytes, quota_bytes FROM dl_sandbox_usage(' . $member . ')')->fetch();
    if ((int) $usage['used_bytes'] >= (int) $usage['quota_bytes']) {
        throw new FieldRefused(['name' => 'Your sandbox is full (' . bytes_words((int) $usage['used_bytes']) . ' of ' . bytes_words((int) $usage['quota_bytes'])
            . ') — retire a dataset you no longer need first.']);
    }
    $floor = 'public';
    foreach ($keys as $k) {
        $c = (string) ($policy['datasets'][$k]['classification'] ?? 'internal');
        $floor = (CLASS_ORDER[$c] ?? 1) > CLASS_ORDER[$floor] ? $c : $floor;
    }
    $pdo->beginTransaction();
    $row = one_row($pdo, 'SELECT id, owner_member_id, status FROM datasets WHERE key = :k FOR UPDATE', ['k' => $key]);
    if ($row !== null && ($row['status'] === 'retired' || (int) $row['owner_member_id'] !== $member)) {
        $pdo->rollBack();
        throw new FieldRefused(['name' => (string) (one_value($pdo, 'SELECT dl_dataset_key_taken(:k)', ['k' => $key]) ?? $key . ' is taken — choose another name')]);
    }
    $title = $title !== null && $title !== '' ? mb_substr($title, 0, 200) : ucfirst(str_replace('_', ' ', $name));
    if ($row === null) {
        $st = $pdo->prepare("INSERT INTO datasets (key, zone, name, title, description, owner_member_id, status, classification_floor, created_by)
                             VALUES (:k, 'sandbox', :n, :t, :d, :o, 'active', :f, :o) RETURNING id");
        $st->execute(['k' => $key, 'n' => 'm' . $member . '_' . $name, 't' => $title, 'o' => $member, 'f' => $floor,
                      'd' => 'Saved from a query over ' . implode(', ', $keys) . '.']);
        $datasetId = (int) $st->fetchColumn();
    } else {
        $datasetId = (int) $row['id'];
        $pdo->prepare('UPDATE datasets SET classification_floor = :f WHERE id = :id')->execute(['f' => $floor, 'id' => $datasetId]);
    }
    $job = lake_enqueue($pdo, 'sandbox_save', $datasetId, null, ['sql' => $sql, 'params' => (object) $params, 'as_of' => $asOf, 'keys' => $keys,
        'policy' => $policy, 'agent_run_id' => current_agent_run_id()]);
    log_activity($pdo, 'sandbox.save', 'dataset', $datasetId, ['dataset_id' => $datasetId,
        'after' => ['key' => $key, 'job_id' => $job, 'inputs' => $keys, 'classification_floor' => $floor, 'replaced' => $row !== null]]);
    $pdo->commit();
    return ['dataset_id' => $datasetId, 'job_id' => $job, 'key' => $key];
}
