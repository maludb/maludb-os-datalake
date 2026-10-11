<?php
declare(strict_types=1);
/** /access/policies (screen `policies`) — the fifteen masks, each with an example; Save per row for policies.manage. Readable by catalog.edit too. JSON: the rows. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/policies/queries.php';
require_login();
require_any_right('policies.manage|catalog.edit');
$pdo = db();
$rows = masking_policies($pdo);
log_screen_view($pdo, 'policies');
if (wants_json()) {
    respond_screen(['policies' => array_map(static fn (array $p): array => ['applies_to' => (string) $p['applies_to'], 'subject' => MASK_SUBJECTS[$p['applies_to']] ?? $p['applies_to'],
        'mask' => (string) $p['mask'], 'example' => MASKS[$p['mask']][1] ?? null, 'note' => $p['note'], 'updated_at' => json_ts($p['updated_at'])], $rows),
        'masks' => array_map(static fn (array $m): array => ['label' => $m[0], 'example' => $m[1]], MASKS), 'may_edit' => has_right('policies.manage')]);
}
render_screen('Masks', view('access/policies.php', ['rows' => $rows, 'may_edit' => has_right('policies.manage'), 'notice' => dl_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved — every reader sees the new mask from their next query.']])]),
    ['activeNav' => 'access', 'screen' => 'policies']);
