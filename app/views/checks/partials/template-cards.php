<?php /** The check templates as cards — each a link to the form with its kind (and the column, when one was named). Data: key, column */ ?>
<div class="row g-2" id="check-templates">
<?php foreach (CHECK_KINDS as $k => $t): ?>
    <div class="col-12 col-sm-6 col-lg-4">
        <?= hx_link('/datasets/' . $key . '/checks/new?' . http_build_query(array_filter(['kind' => $k, 'column' => $column])), '<div class="card h-100 template-card"><div class="card-body d-flex gap-2 align-items-start">'
            . '<i class="' . e($t['icon']) . ' fs-4" aria-hidden="true"></i><div><div class="fw-semibold">' . e($t['label']) . '</div><div class="fs-12 text-muted">' . e($t['what']) . '</div>'
            . '<div class="fs-11 text-muted mt-1">' . e(ucfirst($t['dimension'])) . '</div></div></div></div>', 'text-reset text-decoration-none', 'id="check-template-' . e($k) . '"') ?>
    </div>
<?php endforeach; ?>
</div>
