<?php
/** Seeds the proofs' SMOKE lake (idempotent) and prints its ids as JSON — the browser proof calls this between its empty and its filled home. */
require __DIR__ . '/lib.php';
echo json_encode(seed_lake()), "\n";
