<?php /** Data: kind (success|danger|warning|info), message, link? = [url, label] */ ?>
<div class="alert alert-<?= e($kind ?? 'info') ?> alert-dismissible m-3" role="alert" id="flash-message">
    <?= e($message ?? '') ?><?php if (!empty($link)): ?> <a href="<?= e($link[0]) ?>" class="alert-link" id="flash-link"><?= e($link[1]) ?></a><?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
