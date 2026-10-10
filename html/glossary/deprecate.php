<?php
declare(strict_types=1);
/** Action `term_deprecate` (log term.deprecate — catalog.edit; confirm): **term** — it leaves the pickers and search; its columns keep the link. Undo: term_update. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/glossary/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/glossary/write.php';
dl_handler_begin();
require_right('catalog.edit');
$pdo = db();
$given = (string) (req_val('term') ?? '');
$t = ctype_digit($given) && strlen($given) <= 18 ? find_term($pdo, (int) $given) : null;
if ($t === null) {
    respond_not_found('No such term.');
}
$id = (int) $t['term_id'];
dl_guard($pdo, static function () use ($pdo, $id, $t): void {
    $pdo->beginTransaction();
    term_deprecate($pdo, $id);
    log_activity($pdo, 'term.deprecate', 'glossary_term', $id, ['before' => ['status' => $t['status']], 'after' => ['status' => 'deprecated', 'name' => $t['name'], 'columns' => (int) $t['column_count']]]);
    $pdo->commit();
});
dl_done('Deprecated ' . $t['name'], $id, dl_land('/glossary', 'deprecated'), 'termChanged');
