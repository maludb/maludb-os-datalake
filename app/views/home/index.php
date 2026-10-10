<?php
/**
 * Home (screen `home`; design §9; sso-shell.md). Data: s (home_summary), tz. A person who may discover no dataset sees first-run.php — one
 * button; everyone else the search box with Search and Ask, then the tiles, each from its own partial (a tile its slice has not built says so).
 * Phone first: the search box, decisions, popular, then the rest; at 1280 two columns.
 */
?>
<?= view('shared/page-header.php', ['id' => 'home', 'title' => 'Home', 'crumbs' => [['Home', null]]]) ?>
<div class="main-content" id="home-content">
<?php if ($s['first_run']): ?>
    <?= view('home/first-run.php', ['s' => $s]) ?>
<?php else: ?>
    <form method="get" action="/search" class="card mb-3 home-search" id="home-search" role="search"><div class="card-body">
        <label class="form-label fw-semibold" for="home-search-q">Find data, or ask a question</label>
        <div class="d-flex flex-column flex-md-row gap-2">
            <input type="search" name="q" id="home-search-q" class="form-control btn-touch flex-md-grow-1" maxlength="200" autocomplete="off" placeholder="sales orders, churn, customer email…">
            <div class="d-flex gap-2 flex-shrink-0">
                <button type="submit" class="btn btn-primary btn-touch flex-fill" id="home-search-btn"><i class="feather-search me-1"></i>Search</button>
                <?php if ($s['may']['ask']): ?><button type="submit" formaction="/ask" class="btn btn-light-brand btn-touch flex-fill" id="home-ask-btn"><i class="feather-message-square me-1"></i>Ask</button><?php endif; ?>
            </div>
        </div>
    </div></form>
    <div class="row g-3">
        <div class="col-xl-7">
            <?= view('home/partials/decisions.php', ['s' => $s]) ?>
            <?= view('home/partials/products.php', ['s' => $s]) ?>
            <?= view('home/partials/popular.php', ['s' => $s]) ?>
            <?= view('home/partials/recent.php', ['s' => $s]) ?>
        </div>
        <div class="col-xl-5">
            <?= view('home/partials/incidents.php', ['s' => $s]) ?>
            <?php if ($s['engineer'] !== null): ?><?= view('home/partials/engineer.php', ['s' => $s]) ?><?php endif; ?>
            <?php if ($s['admin'] !== null): ?><?= view('home/partials/admin.php', ['s' => $s]) ?><?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</div>
