<?php /** The key a new dataset will have, as typed (Pattern A on the name and zone fields): zone.name, and whether it is free. Data: zone, name, state (null|ok|bad|taken), message? */ ?>
<div class="form-text" id="dataset-form-key-preview" aria-live="polite">
    <?php if ($name === ''): ?>Its key will be <span class="dataset-key"><?= e($zone) ?>.your_name</span> — lowercase letters, digits and underscores, never changed.
    <?php elseif ($state === 'bad'): ?><span class="text-danger">A name is lowercase letters, digits and underscores, starting with a letter.</span>
    <?php elseif ($state === 'taken'): ?><span class="text-danger"><?= e($message ?? ($zone . '.' . $name . ' is taken')) ?></span>
    <?php else: ?>Its key will be <span class="dataset-key"><?= e($zone . '.' . $name) ?></span><?= $state === 'ok' ? ' — free' : '' ?>.
    <?php endif; ?>
</div>
