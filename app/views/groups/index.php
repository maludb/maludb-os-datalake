<?php
/** Groups as cards (screen `groups`): name, kind (custom / mirrors {department}), members' count, owners; New group for grants.manage. Data: rows, q, kind, archived, may_add, notice */
$url = static function (array $change) use ($q, $kind, $archived): string {
    $p = array_filter(array_merge(['q' => $q, 'kind' => $kind, 'archived' => $archived ? '1' : null], $change), static fn ($v) => $v !== null && $v !== '');
    return '/groups' . ($p === [] ? '' : '?' . http_build_query($p));
};
?>
<?= view('shared/page-header.php', ['id' => 'groups', 'title' => 'Groups', 'crumbs' => [['Home', '/'], ['Access', '/access'], ['Groups', null]],
    'action' => $may_add ? hx_link('/groups/new', '<i class="feather-plus me-1"></i>New group', 'btn btn-primary btn-touch', 'id="groups-add-btn"') : '']) ?>
<div class="main-content" id="groups-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('access/partials/subnav.php', ['active' => 'groups']) ?>
    <form method="get" action="/groups" class="mb-2" id="groups-search" role="search">
        <div class="input-group"><input type="search" name="q" id="groups-field-q" class="form-control btn-touch" value="<?= e((string) $q) ?>" placeholder="A group's name" maxlength="120" aria-label="Search groups">
            <button type="submit" class="btn btn-primary btn-touch" id="groups-search-btn" aria-label="Search"><i class="feather-search"></i></button></div>
    </form>
    <div class="facet-chips mb-3" id="groups-kinds">
        <?php foreach (['' => 'All', 'custom' => 'Custom', 'department' => 'Department mirrors'] as $k => $label): ?>
            <?= hx_link($url(['kind' => $k === '' ? null : $k, 'archived' => null]), e($label), 'btn btn-touch btn-sm ' . ((string) $kind === $k && !$archived ? 'btn-primary' : 'btn-light'), 'id="groups-kind-' . e($k === '' ? 'all' : $k) . '"') ?>
        <?php endforeach; ?>
        <?= hx_link($url(['archived' => '1', 'kind' => null]), 'Archived', 'btn btn-touch btn-sm ' . ($archived ? 'btn-primary' : 'btn-light'), 'id="groups-kind-archived"') ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="groups-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-users"></i></span>
            <div><div class="fw-semibold">No groups here</div><div class="fs-12 text-muted">A group gathers people a grant can name at once — custom, or following a department.</div></div></div></div></div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2">
            <?php foreach ($rows as $g): ?>
                <div class="col"><div class="card h-100 dataset-card" id="group-card-<?= (int) $g['group_id'] ?>"><div class="card-body py-3">
                    <div class="d-flex justify-content-between gap-2"><div class="fw-semibold text-break"><?= hx_link('/groups/' . (int) $g['group_id'], e((string) $g['name']), 'text-dark') ?></div>
                        <span class="badge <?= $g['kind'] === 'department' ? 'bg-soft-info text-info' : 'bg-soft-secondary text-secondary' ?> align-self-start"><?= $g['kind'] === 'department' ? 'Mirrors ' . e((string) ($g['department_name'] ?? 'a department')) : 'Custom' ?></span></div>
                    <?php if (($g['description'] ?? '') !== ''): ?><div class="fs-12 text-muted mt-1 text-break"><?= e(mb_substr((string) $g['description'], 0, 160)) ?></div><?php endif; ?>
                    <div class="fs-11 text-muted mt-2"><i class="feather-users me-1"></i><?= (int) $g['member_count'] ?> member<?= (int) $g['member_count'] === 1 ? '' : 's' ?><?= ($g['owners'] ?? '') !== '' ? ' · owners ' . e((string) $g['owners']) : '' ?></div>
                </div></div></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
