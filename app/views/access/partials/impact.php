<?php /** The impact line of an approval: "Approving gives {name} {level} on {key}: {n} columns stay masked". Data: r, impact */
$n = (int) $impact['masked_count'];
?>
<div class="fs-12 text-muted mt-2" id="request-<?= (int) $r['request_id'] ?>-impact">Approving gives <?= e((string) ($r['requester_name'] ?? 'them')) ?>
    <strong><?= e(level_words((string) $impact['level'])) ?></strong> on <span class="font-monospace"><?= e((string) $r['key']) ?></span>:
    <?= $impact['level'] === 'discover' ? 'they see it in the catalog, not its rows' : ($n === 0 ? 'no column stays masked' : $n . ' column' . ($n === 1 ? ' stays' : 's stay') . ' masked') ?>.</div>
