<?php
declare(strict_types=1);

/**
 * Transforms' writes (slice 7, transforms-products-lineage.md; replicates slice 1's CRUD and slice 3's saved queries): save (create, or change — a change of SQL is a new
 * version, refrozen as the saver), run (the run row and the writer's job), enable / disable, delete (the output dataset stays).
 *
 *   transform_save($pdo, $id, $f, $by) → [id, version, inputs[], refrozen]
 *       the SQL is dry-run FIRST through the one door as the saver, source `transform` (query-service §7): a SQL the allow-list refuses is the service's sentence on `sql`;
 *       an input the saver may not read is refused with the input NAMED (the policy's own words — "You may not read curated.x — request access"); the datasets it reads come
 *       from that parse, never from a person; the output may not be one of them, nor upstream of one of them (a cycle); then the row is saved and
 *       dl_transform_freeze() stores the saver's read level over the inputs — the ceiling of the product for good (FINDINGS §6: a masked input stays masked).
 *   transform_run_queue($pdo, $id, $by) → [run, job, new]       transform_enable($pdo, $id, $on, $by)       transform_delete($pdo, $id, $by)
 */
require_once dirname(__DIR__) . '/uploads/write.php';          // FieldRefused (slice 2's)
require_once dirname(__DIR__) . '/editor/queries.php';         // run_sql, editor_request_id
require_once __DIR__ . '/queries.php';

/** May the caller change or run this transform? transforms.manage, or its owner (who holds the sandbox right or the manage right). */
function may_manage_transform(array $t): bool
{
    return has_right('transforms.manage') || ((int) $t['owner_member_id'] === (int) current_member_id() && (has_right('sandbox.transform') || has_right('transforms.manage')));
}

function require_manage_transform(array $t): void
{
    if (!may_manage_transform($t)) {
        refuse(403, 'Only the owner of ' . $t['name'] . ' or a data engineer may do that.');
    }
}

/** The schedule from the form: kind manual | after_inputs | cron (+ the expression) → NULL | 'after_inputs' | the cron text; errors on `schedule` / `cron`. */
function transform_schedule_from_request(?string $current, array &$errors): ?string
{
    if (req_has('schedule') && !req_has('schedule_kind')) {              // an agent: schedule = "0 6 * * *" | after_inputs | (empty: on request)
        $v = (string) req_val('schedule');
        $kind = $v === '' ? 'manual' : ($v === 'after_inputs' ? 'after_inputs' : 'cron');
        $cron = $kind === 'cron' ? $v : '';
    } elseif (req_has('schedule_kind')) {
        $kind = (string) req_val('schedule_kind');
        $cron = trim((string) (req_val('cron') ?? ''));
        if (!in_array($kind, ['manual', 'after_inputs', 'cron'], true)) {
            $errors['schedule'] = 'Choose when it runs: on request, after its inputs change, or on a cron schedule.';
            return $current;
        }
    } else {
        return $current;
    }
    if ($kind === 'manual') {
        return null;
    }
    if ($kind === 'after_inputs') {
        return 'after_inputs';
    }
    $cron = preg_replace('/\s+/', ' ', $cron) ?? '';
    if (!cron_valid($cron) || !preg_match('/^[0-9*\/,\- ]+$/', $cron)) {
        $errors['cron'] = 'A cron schedule has five fields — minute hour day-of-month month day-of-week — such as 0 6 * * * (every day at 06:00). Numbers, *, lists, ranges and steps only.';
        return $current;
    }
    return $cron;
}

/** The inputs the SQL reads, learned by the one door as the saver (source transform, dry run): their keys. A refusal is the service's sentence on `sql`. */
function transform_inputs_of(PDO $pdo, int $by, string $sql): array
{
    $r = run_sql($pdo, $by, $sql, null, [], ['dry_run' => true, 'source' => 'transform', 'request_id' => editor_request_id()]);
    if ($r['status'] !== 'ok') {
        throw new FieldRefused(['sql' => ucfirst((string) $r['refusal']['message'])]);
    }
    if ($r['datasets'] === []) {
        throw new FieldRefused(['sql' => 'Name at least one dataset — a transform makes a dataset from others.']);
    }
    return $r['datasets'];
}

function transform_save(PDO $pdo, ?int $id, array $f, int $by): array
{
    $cur = $id === null ? null : one_row($pdo, 'SELECT id, name, output_dataset_id, owner_member_id, sql_sha256, version, frozen_policy IS NOT NULL AS frozen FROM transforms WHERE id = :id FOR UPDATE', ['id' => $id]);
    if ($id !== null && $cur === null) {
        throw new DomainException('Not found.');
    }
    $out = dataset_by_ref($pdo, (string) $f['output_dataset']);
    if ($out === null || $out['status'] === 'retired') {
        throw new FieldRefused(['output_dataset' => 'That output dataset is not here.']);
    }
    $outLevel = dl_level_rank(dataset_level($pdo, (int) $out['id']));
    if ($outLevel < DL_LEVELS['discover']) {                       // a hidden dataset never confirms itself (access-and-policies §1)
        throw new FieldRefused(['output_dataset' => 'That output dataset is not here.']);
    }
    if ($out['zone'] === 'raw') {
        throw new FieldRefused(['output_dataset' => 'A transform writes a curated, product or sandbox dataset, never raw — raw is what a source lands.']);
    }
    if ($out['zone'] === 'sandbox') {
        if ((int) $out['owner_member_id'] !== $by || !(has_right('sandbox.transform') || has_right('transforms.manage'))) {
            throw new FieldRefused(['output_dataset' => 'A sandbox transform writes your own sandbox only.']);
        }
    } elseif (!has_right('transforms.manage')) {
        throw new FieldRefused(['output_dataset' => 'A transform into ' . $out['zone'] . ' is a data engineer\'s — yours may write your own sandbox.']);
    } elseif ($outLevel < DL_LEVELS['write']) {
        throw new FieldRefused(['output_dataset' => 'You may not change ' . $out['key'] . ' — ask its owner for write access.']);
    }
    if ($cur !== null && (int) $cur['output_dataset_id'] !== (int) $out['id']) {
        throw new FieldRefused(['output_dataset' => 'A transform\'s output never changes — make another transform.']);
    }
    $taken = one_value($pdo, 'SELECT id FROM transforms WHERE output_dataset_id = :d AND id IS DISTINCT FROM :self', ['d' => (int) $out['id'], 'self' => $id]);
    if ($taken !== null) {
        throw new FieldRefused(['output_dataset' => $out['key'] . ' is already made by another transform — one transform makes one dataset.']);
    }
    if (one_value($pdo, 'SELECT id FROM transforms WHERE name = :n AND id IS DISTINCT FROM :self', ['n' => $f['name'], 'self' => $id]) !== null) {
        throw new FieldRefused(['name' => 'There is a transform called ' . $f['name'] . ' already — choose another name.']);
    }
    $sqlChanged = $cur === null || ($f['sql'] !== null && hash('sha256', (string) $f['sql']) !== (string) $cur['sql_sha256']);
    $sql = $f['sql'];
    if ($sql === null) {
        $sql = (string) one_value($pdo, 'SELECT sql FROM transforms WHERE id = :id', ['id' => $id]);
    }
    // the SQL through the one door, as the saver: the inputs come from the parse; a refusal names the input
    $keys = transform_inputs_of($pdo, $by, $sql);
    if (in_array($out['key'], $keys, true)) {
        throw new FieldRefused(['sql' => 'The SQL reads ' . $out['key'] . ', the dataset it makes — a transform cannot read its own output.']);
    }
    $inIds = array_map('intval', array_column(dataset_ids_of_keys($pdo, $keys), 'id'));
    foreach ($inIds as $in) {                                              // a cycle: the output upstream of an input
        $up = $pdo->prepare("SELECT 1 FROM dl_lineage(:i, 'up', 10) l WHERE l.dataset_id = :o LIMIT 1");
        $up->execute(['i' => $in, 'o' => (int) $out['id']]);
        if ($up->fetchColumn() !== false) {
            throw new FieldRefused(['sql' => 'That would make a cycle — ' . $out['key'] . ' is already made, upstream, from one of the datasets the SQL reads.']);
        }
    }
    if ($f['mode'] === 'incremental_merge' && array_filter(pg_text_array((string) one_value($pdo, 'SELECT dedupe_key FROM datasets WHERE id = :d', ['d' => (int) $out['id']]))) === []) {
        throw new FieldRefused(['mode' => 'An incremental merge merges by the output\'s key, and ' . $out['key'] . ' has none — set its key first, or use replace.']);
    }
    $vals = ['n' => $f['name'], 'd' => $f['description'], 'o' => (int) $out['id'], 's' => $sql, 'm' => $f['mode'], 'sc' => $f['schedule']];
    if ($cur === null) {
        $st = $pdo->prepare('INSERT INTO transforms (name, description, output_dataset_id, sql, mode, schedule, owner_member_id) VALUES (:n, :d, :o, :s, :m, :sc, :by) RETURNING id');
        $st->execute($vals + ['by' => $by]);
        $tid = (int) $st->fetchColumn();
    } else {
        $tid = (int) $cur['id'];
        // new SQL: the saver owns it from now on — "refrozen as you" (the trigger bumps the version and drops the frozen level); the same SQL keeps its owner
        $st = $pdo->prepare('UPDATE transforms SET name = :n, description = :d, sql = :s, mode = :m, schedule = :sc' . ($sqlChanged ? ', owner_member_id = :by' : '') . ' WHERE id = :id');
        $st->execute(['n' => $f['name'], 'd' => $f['description'], 's' => $sql, 'm' => $f['mode'], 'sc' => $f['schedule'], 'id' => $tid] + ($sqlChanged ? ['by' => $by] : []));
    }
    $refreeze = $sqlChanged || !(bool) one_value($pdo, 'SELECT frozen_policy IS NOT NULL FROM transforms WHERE id = :id', ['id' => $tid]);
    if ($refreeze) {
        $pdo->prepare('SELECT dl_transform_freeze(:t, CAST(:k AS text[]))')->execute(['t' => $tid, 'k' => text_array_literal($keys)]);
    }
    $version = (int) one_value($pdo, 'SELECT version FROM transforms WHERE id = :id', ['id' => $tid]);
    return ['id' => $tid, 'version' => $version, 'inputs' => $keys, 'refrozen' => $refreeze, 'output' => $out['key'], 'created' => $cur === null];
}

/** [{id, key}] of dataset keys, in the order given. */
function dataset_ids_of_keys(PDO $pdo, array $keys): array
{
    if ($keys === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT id, key FROM datasets WHERE key = ANY (CAST(:k AS text[])) ORDER BY id');
    $st->execute(['k' => text_array_literal($keys)]);
    return $st->fetchAll();
}

/** Queue a run (the row and the writer's job). One at a time: a run queued or running is that run. */
function transform_run_queue(PDO $pdo, int $id, ?int $by): array
{
    return transform_enqueue_run($pdo, $id, $by);
}

function transform_enable(PDO $pdo, int $id, bool $on, int $by): void
{
    $st = $pdo->prepare('UPDATE transforms SET enabled = :on WHERE id = :id');
    $st->execute(['on' => $on ? 'true' : 'false', 'id' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Not found.');
    }
}

/** Delete a transform: its runs go with it, the output dataset STAYS (and its lineage edges, as history). Refused while a run is queued or running. */
function transform_delete(PDO $pdo, int $id, int $by): void
{
    $live = one_value($pdo, "SELECT count(*) FROM transform_runs WHERE transform_id = :id AND status IN ('queued', 'running') AND created_at > now() - interval '6 hours'", ['id' => $id]);
    if ((int) $live > 0) {
        throw new DomainException('A run is queued or running — wait for it to finish, then delete the transform.');
    }
    $st = $pdo->prepare('DELETE FROM transforms WHERE id = :id');
    $st->execute(['id' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Not found.');
    }
}
