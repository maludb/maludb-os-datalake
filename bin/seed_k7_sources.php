<?php
declare(strict_types=1);

/**
 * Seeds the owner's FIVE K7 reads as sources (design §15 (c); maludb-os.json reads[]; sources-pulls.md "The owner's five reads") — idempotent: a source
 * for the same provider and tool is left as it is. Each is a `k7` source with its raw dataset made (raw.gl_account_balances, raw.gl_ar_aging,
 * raw.gl_ap_due, raw.inventory_sales_closed, raw.crm_deals_won), scheduled daily, pulled by the worker once a super-admin has approved the connection
 * to each application (a pull before that PAUSES the source with `no_connection` — Resume it after the approval, or leave it: resuming is one tap).
 * Run once by the owner after the connections are approved, and by the slice 6 proof.
 *   php bin/seed_k7_sources.php [--owner=MEMBER_ID] [--dry-run]
 * The owner of the datasets and the creator of the sources: --owner, else the first person holding sources.manage, else the first holding settings.manage.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/sources/write.php';

const SEEDED_READS = [
    ['name' => 'GL account balances', 'provider' => 'gl', 'tool' => 'account_balances', 'arguments' => ['as_of' => '<yesterday>'], 'rows_path' => 'accounts', 'paging' => 'none', 'window' => 'none',
     'inject' => ['as_of' => 'document'], 'target' => 'raw.gl_account_balances', 'key' => ['as_of', 'code']],
    ['name' => 'GL receivables aging', 'provider' => 'gl', 'tool' => 'ar_aging_summary', 'arguments' => ['as_of' => '<yesterday>'], 'rows_path' => 'currencies', 'paging' => 'none', 'window' => 'none',
     'inject' => ['as_of' => 'document'], 'target' => 'raw.gl_ar_aging', 'key' => ['as_of', 'currency']],
    ['name' => 'GL payables due', 'provider' => 'gl', 'tool' => 'ap_due_summary', 'arguments' => ['weeks' => 8], 'rows_path' => 'buckets', 'paging' => 'none', 'window' => 'none',
     'inject' => ['as_of' => 'pull_date'], 'target' => 'raw.gl_ap_due', 'key' => ['as_of', 'bucket', 'currency']],
    ['name' => 'Inventory sales closed', 'provider' => 'inventory', 'tool' => 'sales_closed', 'arguments' => [], 'rows_path' => 'orders', 'paging' => 'offset', 'page_size' => 500, 'window' => 'date',
     'inject' => [], 'target' => 'raw.inventory_sales_closed', 'key' => ['number']],
    ['name' => 'CRM deals won', 'provider' => 'sales_crm', 'tool' => 'deals_won', 'arguments' => [], 'rows_path' => 'deals', 'paging' => 'offset', 'page_size' => 500, 'window' => 'date',
     'inject' => [], 'target' => 'raw.crm_deals_won', 'key' => ['deal_id']],
];

$owner = null;
$dry = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--owner=')) {
        $owner = (int) substr($a, 8);
    } elseif ($a === '--dry-run') {
        $dry = true;
    } else {
        fwrite(STDERR, "Usage: php bin/seed_k7_sources.php [--owner=MEMBER_ID] [--dry-run]\n");
        exit(2);
    }
}
$pdo = db();
$GLOBALS['__public_door'] = 'cron';
if ($owner === null) {
    foreach (['sources.manage', 'settings.manage'] as $right) {
        $owner = (int) (one_value($pdo, 'SELECT min(m) FROM dl_members_with_right(:r) m', ['r' => $right]) ?? 0) ?: null;
        if ($owner !== null) {
            break;
        }
    }
}
if ($owner === null) {
    fwrite(STDERR, "No one holds sources.manage here yet — pass --owner=MEMBER_ID.\n");
    exit(1);
}
$rc = 0;
foreach (SEEDED_READS as $def) {
    $have = one_value($pdo, "SELECT id FROM sources WHERE connector = 'k7' AND settings ->> 'provider' = :p AND settings ->> 'tool' = :t ORDER BY id LIMIT 1", ['p' => $def['provider'], 't' => $def['tool']]);
    if ($have !== null) {
        echo json_encode(['source' => $def['name'], 'id' => (int) $have, 'seeded' => false, 'why' => 'already there']) . "\n";
        continue;
    }
    $in = ['name' => $def['name'], 'provider' => $def['provider'], 'tool' => $def['tool'], 'arguments' => $def['arguments'], 'rows_path' => $def['rows_path'], 'paging' => $def['paging'],
           'page_size' => $def['page_size'] ?? '', 'window' => $def['window'], 'inject' => $def['inject'], 'target_dataset' => $def['target'], 'schedule_minutes' => 1440];
    [$f, $errors] = source_validate($pdo, 'k7', $in, null, true, false);
    if ($errors !== []) {
        fwrite(STDERR, $def['name'] . ': ' . json_encode($errors) . "\n");
        $rc = 1;
        continue;
    }
    $f['dedupe_key'] = $def['key'];
    if ($dry) {
        echo json_encode(['source' => $def['name'], 'seeded' => false, 'dry_run' => true, 'target' => $def['target']]) . "\n";
        continue;
    }
    $pdo->beginTransaction();
    try {
        $id = source_save($pdo, null, $f, $owner);
        $target = (int) one_value($pdo, "SELECT NULLIF(settings ->> 'target_dataset_id', '')::bigint FROM sources WHERE id = :id", ['id' => $id]);
        log_activity($pdo, 'source.create', 'source', $id, ['source' => 'cron', 'actor_member_id' => $owner, 'dataset_id' => $target,
            'after' => ['name' => $f['name'], 'connector' => 'k7', 'schedule_minutes' => 1440, 'settings' => source_log_settings('k7', (array) $f['settings']), 'target' => $def['target'], 'seeded' => true]]);
        $pdo->commit();
        echo json_encode(['source' => $def['name'], 'id' => $id, 'seeded' => true, 'dataset' => $def['target']]) . "\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, $def['name'] . ': ' . db_message($e, $e->getMessage()) . "\n");
        $rc = 1;
    }
}
exit($rc);
