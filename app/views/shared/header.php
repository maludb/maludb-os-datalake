<?php
/**
 * The app's top header (layout.php): the business name and the badge of the highest role, THE SEARCH BOX with its two buttons — Search
 * (the catalog search, /search) and Ask (the ask window, /ask — the expert answers through the kernel) — the switcher (K31), the bell,
 * the decisions badge, the theme toggle, the account menu. On a phone the box folds into the magnifier (the tab bar carries Search and Ask).
 * Data: screen (the search screen keeps its query in the box).
 */
$pdo = db();
$m = current_member();
$me = (int) ($m['id'] ?? 0);
$initials = strtoupper(mb_substr((string) ($m['display_name'] ?? '?'), 0, 1));
$roleBadge = role_badge();
$badgeKind = match ($roleBadge) { 'Lake admin', 'Super-admin' => 'danger', 'Data engineer' => 'primary', 'Analyst' => 'info', default => 'secondary' };
$unread = $m !== null ? bell_count($pdo, $me) : 0;
$waiting = $m !== null ? decisions_count($pdo, $me) : 0;
$q = ($screen ?? '') === 'search' ? (string) ($_GET['q'] ?? '') : '';
$mayAsk = has_right('ask.use');
?>
<!--! [Start] Header !-->
<header class="nxl-header" id="top-header">
    <div class="header-wrapper">
        <div class="header-left d-flex align-items-center gap-3">
            <a href="javascript:void(0);" class="nxl-head-mobile-toggler" id="mobile-collapse" aria-label="Menu">
                <div class="hamburger hamburger--arrowturn">
                    <div class="hamburger-box"><div class="hamburger-inner"></div></div>
                </div>
            </a>
            <div class="nxl-navigation-toggle">
                <a href="javascript:void(0);" id="menu-mini-button"><i class="feather-align-left"></i></a>
                <a href="javascript:void(0);" id="menu-expend-button" style="display: none"><i class="feather-arrow-right"></i></a>
            </div>
            <div class="header-role d-flex align-items-center" id="header-person">
                <span class="fw-semibold text-dark text-truncate" id="header-business"><?= e(business_name($pdo)) ?></span>
                <?php if ($roleBadge !== ''): ?><span class="badge bg-soft-<?= e($badgeKind) ?> text-<?= e($badgeKind) ?> ms-2 text-nowrap" id="header-role-badge"><?= e($roleBadge) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="header-right ms-auto">
            <div class="d-flex align-items-center">
                <!-- THE SEARCH BOX: Search (dl_search — datasets, glossary, saved queries, metrics; the wall per row) or Ask (the expert). A plain GET form: works without JavaScript. -->
                <form class="header-search d-none d-md-block me-2" method="get" action="/search" role="search" id="header-search-form">
                    <div class="input-group input-group-sm">
                        <input type="search" name="q" class="form-control" id="header-search" placeholder="Search the lake, or ask a question" value="<?= e($q) ?>" maxlength="200" aria-label="Search the lake" autocomplete="off" />
                        <button type="submit" class="btn btn-light-brand" id="header-search-btn" aria-label="Search" title="Search the catalog"><i class="feather-search"></i></button>
                        <?php if ($mayAsk): ?><button type="submit" class="btn btn-light-brand" id="header-ask" formaction="/ask" aria-label="Ask" title="Ask the lake's expert"><i class="feather-message-square"></i></button><?php endif; ?>
                    </div>
                </form>
                <div class="nxl-h-item d-md-none">
                    <a href="/search" class="nxl-head-link me-0" id="header-search-link" aria-label="Search" hx-get="/search" hx-target="#page-content" hx-push-url="/search"><i class="feather-search"></i></a>
                </div>
                <?php if ($waiting > 0): ?>
                <div class="nxl-h-item">
                    <a href="/#home-decisions" class="nxl-head-link me-0 position-relative" id="header-decisions" aria-label="Decisions waiting for you" title="Decisions waiting for you">
                        <i class="feather-inbox"></i><span class="badge text-bg-warning nxl-h-badge" id="header-decisions-count"><?= $waiting > 99 ? '99+' : (int) $waiting ?></span>
                    </a>
                </div>
                <?php endif; ?>
                <div class="nxl-h-item">
                    <a href="/notifications" class="nxl-head-link me-0 position-relative" id="bell" aria-label="Notifications" hx-get="/notifications" hx-target="#page-content" hx-push-url="/notifications">
                        <i class="feather-bell"></i>
                        <?= view('notifications/partials/bell.php', ['unread' => $unread]) ?>
                    </a>
                </div>
                <?= view('shared/app-switcher.php') ?>
                <div class="nxl-h-item dark-light-theme">
                    <a href="javascript:void(0);" class="nxl-head-link me-0 dark-button"><i class="feather-moon"></i></a>
                    <a href="javascript:void(0);" class="nxl-head-link me-0 light-button" style="display:none"><i class="feather-sun"></i></a>
                </div>
                <div class="dropdown nxl-h-item">
                    <a href="javascript:void(0);" data-bs-toggle="dropdown" role="button" data-bs-auto-close="outside" id="header-user-menu" aria-label="Your account">
                        <span class="avatar-text avatar-md user-avtar me-0"><?= e($initials) ?></span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown nxl-user-dropdown">
                        <div class="dropdown-header">
                            <div class="d-flex align-items-center">
                                <span class="avatar-text avatar-md user-avtar"><?= e($initials) ?></span>
                                <div class="min-w-0">
                                    <h6 class="text-dark mb-0" id="header-user-name"><?= e($m['display_name'] ?? 'Member') ?></h6>
                                    <span class="fs-12 fw-medium text-muted" id="header-user-status"><?= e($m['email'] ?? '') ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a href="/settings/" class="dropdown-item" hx-get="/settings/" hx-target="#page-content" hx-push-url="/settings/" id="header-settings-link">
                            <i class="feather-settings"></i><span>My settings</span>
                        </a>
                        <a href="/settings/tokens/" class="dropdown-item" hx-get="/settings/tokens/" hx-target="#page-content" hx-push-url="/settings/tokens/" id="header-tokens-link">
                            <i class="feather-key"></i><span>Tokens and keys</span>
                        </a>
                        <a href="<?= e(launcher_url()) ?>" class="dropdown-item" id="header-launcher-item">
                            <i class="feather-grid"></i><span>All applications</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <form method="post" action="/logout.php" class="px-2">
                            <?= csrf_field() ?>
                            <button type="submit" id="header-logout-btn" class="dropdown-item border-0 bg-transparent w-100 text-start">
                                <i class="feather-log-out"></i><span>Sign out</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
<!--! [End] Header !-->
