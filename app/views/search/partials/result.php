<?php
/** One result: a dataset's card (title, key, zone, certification, owner, usage, the excerpt marked), a stub's locked card, or a term / query / metric. Data: r, here */
$id = 'search-result-' . $r['entity_kind'] . '-' . (int) $r['entity_id'];
if ($r['visibility'] === 'stub'): ?>
    <?= view('shared/stub-card.php', ['r' => ['id' => $id, 'title' => $r['title'], 'owner_name' => $r['owner_name'], 'request_url' => search_request_url($r)]]) ?>
<?php elseif ($r['entity_kind'] === 'dataset'): ?>
    <?= view('shared/record-card.php', ['r' => ['id' => $id, 'title' => $r['title'], 'key' => $r['key'], 'zone' => $r['zone'], 'certification' => $r['certification'],
        'owner_name' => $r['owner_name'], 'usage_30d' => (int) $r['usage_30d'], 'excerpt_html' => search_excerpt_html($r['excerpt']), 'url' => search_result_url($r), 'back' => $here]]) ?>
<?php else: ?>
    <div class="card dataset-card mb-2" id="<?= e($id) ?>"><div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="fw-semibold text-truncate min-w-0"><?= hx_link(with_back(search_result_url($r), $here), e((string) $r['title']), 'text-dark') ?></div>
            <span class="badge bg-soft-secondary text-secondary flex-shrink-0"><?= e(['glossary_term' => 'Term', 'saved_query' => 'Saved query', 'metric' => 'Metric'][$r['entity_kind']] ?? $r['entity_kind']) ?></span>
        </div>
        <?php if (($r['excerpt'] ?? '') !== ''): ?><div class="fs-12 text-muted text-break mt-1"><?= search_excerpt_html($r['excerpt']) ?></div><?php endif; ?>
    </div></div>
<?php endif; ?>
