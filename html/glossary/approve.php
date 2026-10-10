<?php
declare(strict_types=1);
/** Action `term_approve` (log term.approve — catalog.edit; a PERSON: an agent proposes, never approves): **term** — a draft becomes approved. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/glossary/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/glossary/write.php';
dl_handler_begin();
require_right('catalog.edit');
require_human();
$pdo = db();
$given = (string) (req_val('term') ?? '');
$t = ctype_digit($given) && strlen($given) <= 18 ? find_term($pdo, (int) $given) : null;
if ($t === null) {
    respond_not_found('No such term.');
}
$id = (int) $t['term_id'];
dl_guard($pdo, static function () use ($pdo, $id, $t): void {
    $pdo->beginTransaction();
    term_approve($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'term.approve', 'glossary_term', $id, ['before' => ['status' => $t['status']], 'after' => ['status' => 'approved', 'name' => $t['name']]]);
    $pdo->commit();
});
dl_done('Approved ' . $t['name'], $id, dl_land('/glossary/' . $id, 'approved'), 'termChanged');
