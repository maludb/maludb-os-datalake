<?php
/**
 * The phone's tab bar (hidden from 992 px, where the sidebar is): Home · Search · Ask · Me (design §9; sso-shell.md). Data: activeNav.
 * Me opens the sidebar (the theme's offcanvas); without JavaScript it opens My settings. A tab whose right the person lacks is not shown.
 */
?>
<nav class="app-tabbar d-lg-none" id="tab-bar" aria-label="Main">
    <?php foreach (nav_tabs() as [$tid, $turl, $ticon, $tlabel, $tright, $tkind]): ?>
        <?php if (!nav_has_right($tright)) { continue; } ?>
        <?php if ($tkind === 'menu'): ?>
            <a href="<?= e($turl) ?>" id="tab-<?= e($tid) ?>" class="app-tab" aria-controls="left-sidenav"
               onclick="var t = document.getElementById('mobile-collapse'); if (t) { event.preventDefault(); t.click(); }">
                <i class="<?= e($ticon) ?>"></i><span><?= e($tlabel) ?></span>
            </a>
        <?php else: ?>
            <a href="<?= e($turl) ?>" id="tab-<?= e($tid) ?>" class="app-tab<?= ($activeNav ?? '') === $tid ? ' active' : '' ?>"
               hx-get="<?= e($turl) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($turl) ?>">
                <i class="<?= e($ticon) ?>"></i><span><?= e($tlabel) ?></span>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
