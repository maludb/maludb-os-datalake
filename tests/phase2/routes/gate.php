<?php
declare(strict_types=1);
/**
 * THE GATE'S FIXTURE ROUTE — proofs only (served at /__proof/gate.php by tests/dev_router.php under php -S, and by a proof-only Alias that
 * tests/phase2/servers.sh adds to the rendered vhost under Apache; never in deploy/ or html/). GET ?ref=<id|key>&need=<level>: the dataset gate
 * exactly as a slice's page calls it — 404 below discover, 403 with the sentence below the need, the row with my_level otherwise.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_login();
$d = require_level(db(), request_string('ref'), request_string('need', 'read'));
$out = ['id' => (int) $d['id'], 'key' => $d['key'], 'my_level' => $d['my_level'], 'is_stub' => (bool) $d['is_stub'], 'on_behalf_of' => lake_on_behalf_of(), 'ceiling' => lake_ceiling()];
if (wants_json()) {
    respond_screen($out);
}
header('Content-Type: text/plain');
echo 'gate ok ' . json_encode($out);
