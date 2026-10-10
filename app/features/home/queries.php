<?php
declare(strict_types=1);

/**
 * The home (screen `home`; sso-shell.md "Query functions"; design §9): the search box with Search and Ask, then tiles — each rendered
 * from its own partial and filled by the slice whose data it shows; until that slice lands its key is null and the partial says which
 * slice builds it. Phase 2 fills `popular` (dl_popular_datasets(30, NULL, 6), db/018), `first_run` and `decisions`.
 *   products   certified products as cards                                   slice 7
 *   recent     my recently queried datasets                                  slice 3
 *   followed   the datasets I follow (my_followed_datasets — built)          slice 1
 *   popular    popular this month (queries and people, the wall the function's)  Phase 2
 *   incidents  open incidents on datasets I use                              slice 5
 *   decisions  access requests I decide, the Steward's proposals on mine     Phase 2 (counted; slices 4 and 10 make them)
 *   engineer   for catalog.edit: failed loads in 24 h, late datasets, the Steward's pending proposals — slice 10 fills each (null until then)
 *   admin      for the Lake admin: the engine, storage, the last backup and drill — slice 10 fills each (null until then)
 *   first_run  the person may discover no dataset: the home is ONE button, Upload a spreadsheet (and, for an engineer, a link to connect a source)
 * Every read goes through a view or a function that applies the wall; nothing here re-implements a rule.
 */

/** Which slice fills each tile — the partials print it while the key is null. */
const HOME_TILE_SLICES = ['products' => 'slice 7', 'recent' => 'slice 3', 'followed' => 'slice 1', 'incidents' => 'slice 5', 'engineer' => 'slice 10', 'admin' => 'slice 10'];

function home_summary(PDO $pdo, int $memberId): array
{
    require_once dirname(__DIR__) . '/catalog/queries.php';
    $first = !(bool) one_value($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_datasets WHERE NOT is_stub)');
    $popular = $pdo->query('SELECT dataset_id, key, title, certification, queries, people FROM dl_popular_datasets(30, NULL, 6)')->fetchAll();
    $engineer = has_right('catalog.edit') ? ['failed_loads' => null, 'late' => null, 'proposals' => null] : null;
    $admin = is_dl_admin() ? ['engine' => null, 'storage' => null, 'last_backup' => null] : null;
    return [
        'products'  => null,
        'recent'    => null,
        'followed'  => my_followed_datasets($pdo, 6),           // slice 1
        'popular'   => $popular,
        'incidents' => null,
        'decisions' => decisions($pdo, $memberId),
        'engineer'  => $engineer,
        'admin'     => $admin,
        'first_run' => $first,
        'may'       => ['upload' => has_right('sandbox.upload'), 'source' => has_right('sources.manage'), 'ask' => has_right('ask.use'),
                        'decide' => has_right('grants.manage') || has_right('catalog.edit')],
    ];
}

/** The JSON shape of the home (whitelisted; a tile not built yet is null and names its slice in `pending`). */
function present_home(array $s): array
{
    $pending = [];
    foreach (HOME_TILE_SLICES as $k => $slice) {
        if ($s[$k] === null && !in_array($k, ['engineer', 'admin'], true)) {
            $pending[$k] = $slice;
        }
    }
    foreach (['engineer', 'admin'] as $k) {
        if (is_array($s[$k]) && in_array(null, $s[$k], true)) {
            $pending[$k] = HOME_TILE_SLICES[$k];
        }
    }
    return [
        'first_run' => $s['first_run'],
        'products'  => $s['products'],
        'recent'    => $s['recent'],
        'followed'  => $s['followed'] === null ? null : array_map(static fn (array $r): array => ['dataset_id' => (int) $r['dataset_id'], 'key' => $r['key'], 'title' => $r['title'],
            'zone' => $r['zone'], 'certification' => $r['certification'], 'url' => '/datasets/' . $r['key']], $s['followed']),
        'popular'   => array_map(static fn (array $r): array => ['dataset_id' => (int) $r['dataset_id'], 'key' => $r['key'], 'title' => $r['title'],
            'certification' => $r['certification'], 'queries' => (int) $r['queries'], 'people' => (int) $r['people'], 'url' => '/datasets/' . $r['key']], $s['popular']),
        'incidents' => $s['incidents'],
        'decisions' => $s['decisions'],
        'engineer'  => $s['engineer'],
        'admin'     => $s['admin'],
        'pending'   => $pending,
        'may'       => $s['may'],
    ];
}
