<?php
/**
 * One group (screen `group-view`): its members (avatar, role, joined) with Add (the member picker) and Remove; a department mirror's members
 * read-only ("follows {department}"); the grants it holds. Data: g, members, grants, may_manage, may_archive, notice
 */
$id = (int) $g['group_id'];
$mirror = $g['kind'] === 'department';
$pdo = db();
?>
<?= view('shared/page-header.php', ['id' => 'group-view', 'title' => (string) $g['name'], 'crumbs' => [['Home', '/'], ['Access', '/access'], ['Groups', '/groups'], [(string) $g['name'], null]],
    'back' => ['/groups', 'Groups'],
    'action' => '<div class="d-flex gap-2">' . ($may_manage && $g['archived_at'] === null ? hx_link('/groups/' . $id . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="group-view-edit-btn"') : '')
        . ($may_archive && $g['archived_at'] === null ? '<form method="post" action="/groups/archive.php" hx-post="/groups/archive.php" hx-target="#flash" hx-confirm="Archive this group? Its grants stay until revoked, and reach nobody." class="d-inline" id="group-view-archive-form">'
            . csrf_field() . '<input type="hidden" name="group" value="' . $id . '"><button type="submit" class="btn btn-light btn-touch" id="group-view-archive-btn">Archive</button></form>' : '') . '</div>']) ?>
<div class="main-content" id="group-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge <?= $mirror ? 'bg-soft-info text-info' : 'bg-soft-secondary text-secondary' ?>"><?= $mirror ? 'Follows ' . e((string) ($g['department_name'] ?? 'its department')) : 'Custom' ?></span>
            <?php if ($g['archived_at'] !== null): ?><span class="badge bg-dark">Archived</span><?php endif; ?>
            <span class="fs-12 text-muted font-monospace"><?= e((string) $g['key']) ?></span>
        </div>
        <?php if (($g['description'] ?? '') !== ''): ?><p class="mt-2 mb-0"><?= nl2br(e((string) $g['description'])) ?></p><?php endif; ?>
        <?php if ($mirror): ?><div class="fs-12 text-muted mt-2" id="group-view-mirror-note">Its members are the department's, kept by the directory — add or remove people in the directory, not here.</div><?php endif; ?>
    </div></div>
    <h6 class="fs-12 text-uppercase text-muted mb-2">Members (<?= count($members) ?>)</h6>
    <?php if ($may_manage && !$mirror && $g['archived_at'] === null): ?>
        <form method="post" action="/groups/members/add.php" hx-post="/groups/members/add.php" hx-target="#flash" class="card mb-2" id="group-member-add-form"><div class="card-body row g-2 align-items-end">
            <?= csrf_field() ?><input type="hidden" name="group" value="<?= $id ?>">
            <div class="col-12 col-md-6"><label class="form-label fs-12 mb-1" id="group-member-add-label" for="group-member-add-field-member">Add a member</label>
                <?= picker_field(['id' => 'group-member-add-field-member', 'name' => 'member', 'source' => 'member', 'value' => null, 'labelledby' => 'group-member-add-label', 'title' => 'The member', 'required' => true]) ?></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="group-member-add-field-role">Role</label>
                <select class="form-select btn-touch" id="group-member-add-field-role" name="role"><option value="member">Member</option><option value="owner">Owner (may change the group)</option></select></div>
            <div class="col-6 col-md-3"><button type="submit" class="btn btn-primary btn-touch w-100" id="group-member-add-btn">Add</button></div>
        </div></form>
    <?php endif; ?>
    <?php if ($members === []): ?>
        <div class="fs-12 text-muted mb-3" id="group-view-no-members">No members yet.</div>
    <?php else: ?>
        <div class="row g-2 mb-3" id="group-view-members">
            <?php foreach ($members as $m): ?>
                <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="group-member-<?= (int) $m['member_id'] ?>"><div class="card-body py-2 d-flex align-items-center gap-2">
                    <span class="avatar-text avatar-md rounded-circle"><?= e(mb_strtoupper(mb_substr((string) $m['display_name'], 0, 1))) ?></span>
                    <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate"><?= e((string) $m['display_name']) ?><?= $m['is_agent'] ? ' <span class="badge bg-soft-info text-info">Agent</span>' : '' ?></div>
                        <div class="fs-11 text-muted"><?= $m['role'] === 'owner' ? 'Owner' : 'Member' ?> · joined <?= e(format_ts((string) $m['joined_at'], member_timezone(), 'M j, Y')) ?></div></div>
                    <?php if ($may_manage && !$mirror && $g['archived_at'] === null): ?>
                        <form method="post" action="/groups/members/remove.php" hx-post="/groups/members/remove.php" hx-target="#flash" hx-confirm="Remove <?= e((string) $m['display_name']) ?> from the group? Its grants stop reaching them." id="group-member-<?= (int) $m['member_id'] ?>-remove-form">
                            <?= csrf_field() ?><input type="hidden" name="group" value="<?= $id ?>"><input type="hidden" name="member" value="<?= (int) $m['member_id'] ?>">
                            <button type="submit" class="btn btn-light btn-sm btn-touch" id="group-member-<?= (int) $m['member_id'] ?>-remove" aria-label="Remove">Remove</button></form>
                    <?php endif; ?>
                </div></div></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <h6 class="fs-12 text-uppercase text-muted mb-2">The grants it holds</h6>
    <?php if ($grants === []): ?>
        <div class="fs-12 text-muted" id="group-view-no-grants">No grant names this group<?= has_right('grants.manage') ? '' : ' that you may see' ?>.</div>
    <?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table mb-0 align-middle" id="group-view-grants">
            <thead><tr><th>Target</th><th>Who</th><th>Level</th><th>Row filter</th><th>Expires</th><th>Granted</th><th>Reason</th><th></th></tr></thead>
            <tbody><?php foreach ($grants as $gr): ?><?= view('access/partials/grant-row.php', ['g' => $gr, 'may_revoke' => may_grant_on($pdo, (string) $gr['target_kind'], $gr['dataset_id'] === null ? null : (int) $gr['dataset_id'])]) ?><?php endforeach; ?></tbody>
        </table></div></div>
    <?php endif; ?>
</div>
