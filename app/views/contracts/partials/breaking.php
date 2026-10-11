<?php /** The draft's breaking changes, live (contract-breaking), and the version they call for. Data: breaking, suggest */ ?>
<div id="contract-breaking">
<?php if ($breaking === []): ?><div class="fs-12 text-success">No breaking change against the active version. Suggested version: <span class="font-monospace" id="contract-suggested"><?= e($suggest) ?></span>.</div>
<?php else: ?><div class="fs-12 fw-semibold text-danger">Breaking — a new major version (<span class="font-monospace" id="contract-suggested"><?= e($suggest) ?></span>):</div>
    <ul class="fs-12 mb-0"><?php foreach ($breaking as $b): ?><li><?= e(breaking_words($b)) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
</div>
