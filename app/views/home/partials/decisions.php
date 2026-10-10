<?php /** Decisions waiting for me: access requests I decide (slice 4 makes them), the Steward's proposals on mine (slice 10). Counted from Phase 2. Data: s */
$d = $s['decisions'];
$total = (int) $d['requests'] + (int) $d['proposals'];
?>
<div class="card mb-3 home-tile" id="home-decisions"><div class="card-header"><h5 class="card-title mb-0">Waiting for you<?php if ($total > 0): ?> <span class="badge text-bg-warning" id="home-decisions-count"><?= $total ?></span><?php endif; ?></h5></div><div class="card-body">
<?php if ($total === 0): ?>
    <div class="fs-12 text-muted" id="home-decisions-none">Nothing is waiting for your decision.</div>
<?php else: ?>
    <div class="d-flex flex-wrap gap-3">
        <?php if ((int) $d['requests'] > 0): ?><div id="home-decisions-requests"><div class="home-tile-figure"><?= (int) $d['requests'] ?></div><div class="fs-12"><a href="/access/requests?box=to_decide">access request<?= (int) $d['requests'] === 1 ? '' : 's' ?> to decide</a></div></div><?php endif; ?>
        <?php if ((int) $d['proposals'] > 0): ?><div id="home-decisions-proposals"><div class="home-tile-figure"><?= (int) $d['proposals'] ?></div><div class="fs-12"><a href="/steward">proposal<?= (int) $d['proposals'] === 1 ? '' : 's' ?> from the Steward</a></div></div><?php endif; ?>
    </div>
<?php endif; ?>
</div></div>
