<?php
/** One query run (screen `query-run-view`, #run-view): who, on whose behalf, the source, the datasets, masks and filters by name, the counts, the SQL or "hidden". Data: r (present_run) */
$tz = member_timezone();
[$cls, $words] = run_badge((string) $r['status'], $r['refusal_rule']);
$row = static fn (string $label, string $html, string $id): string => '<div class="row py-1 border-bottom"><div class="col-5 col-md-3 text-muted fs-12">' . e($label) . '</div><div class="col-7 col-md-9 fs-12" id="' . e($id) . '">' . $html . '</div></div>';
?>
<?= view('shared/page-header.php', ['id' => 'run-view', 'title' => 'Run #' . $r['query_run_id'], 'crumbs' => [['Home', '/'], [has_right('sql.editor') ? 'SQL' : 'Saved queries', has_right('sql.editor') ? '/sql' : '/queries'], ['Run #' . $r['query_run_id'], null]]]) ?>
<div class="main-content" id="run-view"><div class="card"><div class="card-body">
    <?= $row('Status', '<span class="badge ' . e($cls) . '">' . e($words) . '</span>', 'run-view-status') ?>
    <?= $row('Who', e((string) ($r['caller_name'] ?? 'member ' . $r['caller_member_id'])) . ($r['caller_kind'] === 'agent' ? ' <span class="badge bg-soft-info text-info">agent</span>' : '')
        . ($r['on_behalf_of'] !== null ? ' for ' . e((string) ($r['on_behalf_name'] ?? 'member ' . $r['on_behalf_of'])) : '') . ($r['agent_run_id'] !== null ? ' · agent run ' . (int) $r['agent_run_id'] : ''), 'run-view-who') ?>
    <?= $row('Source', e((string) $r['source']) . ($r['saved_query_id'] !== null ? ' · ' . ($r['saved_query_title'] !== null ? hx_link('/queries/' . $r['saved_query_id'], e((string) $r['saved_query_title'])) : 'saved query ' . (int) $r['saved_query_id']) : ''), 'run-view-source') ?>
    <?= $row('Datasets', $r['datasets'] === [] ? '—' : implode(', ', array_map(static fn (string $k): string => hx_link('/datasets/' . $k, e($k), 'font-monospace'), $r['datasets'])), 'run-view-datasets') ?>
    <?= $row('Masked', $r['masked_columns'] === [] ? 'none' : e(implode(', ', $r['masked_columns'])), 'run-view-masks') ?>
    <?= $row('Row filters', $r['row_filtered_datasets'] === [] ? 'none' : 'on ' . e(implode(', ', $r['row_filtered_datasets'])), 'run-view-filters') ?>
    <?= $row('Rows', number_format($r['rows']) . ($r['truncated'] ? ' (cut at the limit)' : '') . ' · ' . e((string) (bytes_words($r['bytes']) ?? '0 B')), 'run-view-rows') ?>
    <?= $row('Time', (int) $r['ms'] . ' ms · ' . e(format_ts($r['created_at'], $tz)) . ($r['as_of'] !== null ? ' · as of ' . e((string) $r['as_of']) : ''), 'run-view-time') ?>
    <?php if ($r['refusal_rule'] !== null): ?><?= $row('Rule', '<code>' . e((string) $r['refusal_rule']) . '</code>', 'run-view-rule') ?><?php endif; ?>
    <div class="mt-3"><div class="text-muted fs-12 mb-1">SQL</div>
        <?php if ($r['sql_hidden']): ?><div class="fs-12 text-muted" id="run-view-sql-hidden">Hidden — the SQL is shown to who ran it, the person it acted for and the audit.</div>
        <?php else: ?><pre class="bg-light p-2 fs-12 mb-0" id="run-view-sql"><?= e((string) $r['sql']) ?></pre>
            <?php if (has_right('sql.editor') && (int) $r['caller_member_id'] === (int) current_member_id()): ?>
                <div class="mt-2"><?= hx_link('/sql', 'Open the editor', 'btn btn-light btn-sm btn-touch', 'id="run-view-open"') ?></div><?php endif; ?>
        <?php endif; ?></div>
</div></div></div>
