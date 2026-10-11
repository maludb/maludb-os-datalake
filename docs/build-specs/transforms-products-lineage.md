# Build spec: transforms, products, metrics and lineage (slice 7 — replicates slices 1, 3 and 5)

What exists at the end: transforms — a named SQL over dataset keys that makes a curated, product or sandbox dataset — saved with the owner's level
**frozen**, run by the writer on demand, on a cron schedule or after their inputs change, each run a snapshot with its lineage recorded; the **product
gallery**; **metrics** defined once over a product, cut by grain and dimension, certified, answered by the query service's `metric` request; the
**lineage** tab of a dataset made real and the desk's **lineage graph**. **Build the transform and metric screens exactly like slice 1's datasets
screens; the transform job exactly like slice 5's promotion job (a `lake_jobs` row → the writer's handler → a snapshot with an author and a message →
the run's row → the engine fields on the output dataset); the `metric` request kind exactly like slice 3's `saved_query` kind (query-service §7).**

Schema (db/007, 011, 012, 014, 015, 016, never modified): `datasets` (db/007); `metrics`, `dl_metrics_before()` (a metric is over a product; its certification drops
when the definition changes), `query_runs` (db/011); `transforms`, `dl_transforms_before()` (never raw; a sandbox output is the owner's; new SQL = a new version,
unfrozen), `dl_transform_freeze()`, `transform_runs`, `lineage_runs`, `lineage_edges`, `dl_lineage_edge()`, `dl_lineage()`, `dl_downstream_keys()`, `dl_impact()`
(db/012); `lake_jobs`, `dl_job_enqueue()` (db/009); `dl_notify()` (db/014); `dl_index_metric()` (db/015); `mcp_transforms`, `mcp_transform_runs`, `mcp_metrics`,
`mcp_lineage_edges`, `mcp_datasets` (db/016). No migration was planned; the build found three small gaps and added **db/026** (see "Built and proven", decision 1).

## Divergences from the design
1. **A transform's inputs come from the query service, not from PHP**: `transform_save` sends the SQL as a `sql` request with `row_limit: 0` and `source:
   "transform"` **as the transform's owner** (query-service §7); the answer's `datasets` are the inputs; a refusal (the owner may not read an input, a
   forbidden form) is the form's error, field `sql`. Then `dl_transform_freeze(transform_id, inputs)` stores the owner's policy — the ceiling of the product
   for good (tests/engine/FINDINGS.md §6: a masked input stays masked).
2. **`incremental_merge` is refused by the writer, not the form**, for SQL with an aggregate, a `GROUP BY`, a `DISTINCT` or a window (sqlglot in the
   handler): the run fails with `incremental_needs_rowwise` and the transform's page says so; the form's help text states the rule.
3. **A cron schedule is matched in PHP** (`cron_due(string $expr, ?DateTimeImmutable $last, DateTimeImmutable $now): bool` — five fields, `*`, lists, ranges,
   steps; the business's time zone); `after_inputs` queues a run when every input's `last_snapshot_id` is newer than the last run's `input_snapshots`.
4. **The lineage graph is a server-rendered SVG** (no chart library): five layered columns (depth −2 … +2), the dataset in the middle, a node per
   discoverable dataset (key, zone, certification dot), sources as rounded grey nodes, edges labelled `load` / `promote` / `transform`; nodes the caller may
   not discover collapse into one grey "n hidden" node per column. At 375 px the SVG scales by its `viewBox` and the hop lists render below it.
5. **The metric's chart on `metric-view` is a server-rendered SVG line** of the last 12 periods at the metric's first grain (`metric_value` through the query
   service as the viewer), the value axis from zero; at 375 the chart is full width.

## Who may do what
transform_save: `transforms.manage` (a curated or product output) or `sandbox.transform` (an output in the caller's own sandbox) · transform_run / transform_enable:
`transforms.manage` or the transform's owner · transform_delete: the same, pauses for an agent (`deletion`) · metric_save / metric_retire: `metrics.manage` (retire pauses
`deletion`) · metric_certify: `metrics.manage`, pauses for an agent (`other`) · the screens: a transform or a metric is shown to whoever discovers its output / its product
(`dl_can_discover`); the SQL to whoever reads every input.

## Screens
`products` `/products` (cards like slice 1's `browse` for the `product` zone: certified first, owner, freshness, the metrics count, Query this) ·
`transform-list` `/transforms?dataset=&enabled=` (cards: name, output key, inputs' keys, mode, schedule, last run's status) · `transform-view` `/transforms/{id}` (header:
name, output link, Run · Enable/Disable · ⋯ Edit · Delete; tabs by anchor — **SQL** (read-only, monospace; hidden behind "you may not read every input" when not), **Inputs and
output**, **Runs** (the last 50 `transform_runs`: status, rows written, input snapshots, output snapshot, error), **Lineage** (the edges it makes)) · `transform-add`
`/transforms/new?output_dataset=` (desk: name, output (the picker over curated, product and my sandbox datasets), the SQL textarea with the keys I may read listed beside it
(slice 3's dataset browser partial), mode, schedule, description; Save runs the query-service validation) · `transform-edit` `/transforms/{id}/edit` (the same; saving new SQL
shows "a new version — refrozen as you") · `metric-list` `/metrics?dataset=&certified=` (cards: title, product, grains, dimensions, the certified badge) · `metric-view` `/metrics/{id}`
(definition, the chart, a grain picker, a dimension picker that re-renders the table below the chart; Certify / Retire / Edit) · `metric-add` `/metrics/new?dataset=` · `metric-edit`
`/metrics/{id}/edit` (name, title, product (the picker over the `product` zone), expression, time column, grains, dimensions (the product's columns as chips), filters, format,
unit, description; Save validates through a `metric` request with `row_limit: 0`) · `lineage-graph` `/datasets/{id}/lineage?depth=` (§ Divergences 4).

**The dataset page's Lineage tab** (slice 1 renders the tab's placeholder): `app/views/datasets/partials/tab-lineage.php` made real here — one hop up and one hop down as two
lists (`lineage_upstream` / `lineage_downstream` reads with depth 1), each item the dataset or source, the edge's `via`, `last_seen`; "n hidden" when some are; Open the graph.

## The writer's transform job (`services/lake/jobs/transform.py`, registered in the handler table slice 2 built — lake-engine §1, §5)
Claim → read the transform (`frozen_policy` NULL → fail `not_frozen`) → `transform_runs` row `running` with `input_snapshots` → `lineage_runs` START (`run_uuid` v7, `job_kind
transform`, `job_name 'transform.<name>'`, inputs = the inputs' keys and snapshots, facets.sql = the SQL) → `services/lake/transform.py`'s `transform(con, output_key, sql,
policy = frozen_policy (+ the salt), author = 'dl:<requested_by or owner>', name, version, mode, key_columns = the output's contract key, since = the last ok run's
input_snapshots)` → the output's engine fields (`row_count`, `byte_size`, `file_count`, `last_snapshot_id`, `data_updated_at`) → `transform_runs` ok (`rows_written`,
`output_snapshot`) → `lineage_runs` COMPLETE (`outputStatistics`) → one `dl_lineage_edge(input, NULL, output, 'transform', transform_id, column_map)` per input (the column map
from sqlglot's lineage of the SQL when it resolves, `{}` when not) → `transforms.last_run_id` → `dl_job_finish`. On failure: `transform_runs` failed with the error (≤ 2000), `lineage_runs`
FAIL with `errorMessage`, `dl_notify(owner, 'load_failed', …)` (dedupe per transform per day).

## Files (exactly these)
`html/transforms/{index,view,form,save,run,enable,delete}.php`, `html/metrics/{index,view,form,save,certify,retire}.php`, `html/products/index.php`, `html/datasets/lineage.php`
(the graph page; `/datasets/{id}/lineage` routed by the vhost and `tests/dev_router.php`); `app/features/transforms/{queries,present,write,cron}.php`,
`app/features/metrics/{queries,present,write}.php`, `app/features/lineage/{queries,present,svg}.php`; `app/views/transforms/{index,view,form}.php`, `app/views/metrics/{index,view,form}.php`,
`app/views/products/index.php`, `app/views/lineage/graph.php`, `app/views/datasets/partials/tab-lineage.php` (made real), `app/views/metrics/partials/chart.php`,
`app/views/transforms/partials/{card,runs-table}.php`; `services/lake/jobs/transform.py`; the `metric` request kind in `services/query/` (beside slice 3's `saved_query`);
`bin/worker.php` (the `schedules` pass's transform part); `tests/phase3/slice7/{run.sh,lib.php,transforms.php,metrics.php,lineage.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`find_transforms(PDO, array $f, int $page): array` · `find_transform(PDO, int $id): ?array` · `transform_runs(PDO, int $id, int $limit): array` · `transforms_due(PDO, DateTimeImmutable $now): array` ·
`transforms_for_pick(…)` · `find_metrics(PDO, array $f, int $page): array` · `find_metric(PDO, int $id): ?array` · `metrics_for_pick(…)` · `products(PDO, int $page): array` ·
`lineage_hops(PDO, int $dataset, string $direction, int $depth): array{nodes, hidden}` · `lineage_svg(array $up, array $down, array $centre): string` · `cron_due(string $expr, ?DateTimeImmutable $last, DateTimeImmutable $now): bool` ·
writes: `transform_save(PDO, ?int $id, array $f, int $by): int` (validates through `lake_query()` — query-service §8 — then saves and freezes) · `transform_run_queue(PDO, int $id, int $by): int` (the run row + the job) ·
`transform_enable(PDO, int $id, bool $on, int $by)` · `transform_delete(PDO, int $id, int $by)` · `metric_save(PDO, ?int $id, array $f, int $by): int` · `metric_certify(PDO, int $id, bool $on, int $by)` · `metric_retire(PDO, int $id, int $by)`

## MCP tools this slice makes real
`find_transforms` (the transform resolver), `get_transform`, `find_metrics` (the metric resolver), `metric_value` (the member side; the K7 side is slice 9's), `lineage_upstream`,
`lineage_downstream`, `impact`.

## Manifest rows claimed
Screens (10): `products`, `transform-list`, `transform-view`, `transform-add`, `transform-edit`, `metric-list`, `metric-view`, `metric-add`, `metric-edit`, `lineage-graph`
Actions (7): `transform_save`, `transform_run`, `transform_enable`, `transform_delete`, `metric_save`, `metric_certify`, `metric_retire`

## Activity log events
`transform.save` (`{transform_id, version, output, inputs[], mode, schedule}` — never the SQL), `transform.run` (queued), `transform.run_done` (`{transform_run_id, status, rows_written,
output_snapshot}`, source `system`), `transform.enable|disable|delete`, `metric.save` (`{metric_id, dataset, grains, dimensions}` — never the expression's literals), `metric.certify|uncertify|retire`.

## Status vocabulary
Transform run: queued `secondary`, running `primary`, ok `success`, no_op `light`, failed `danger`. Transform: enabled `success`, disabled `secondary`, not frozen `warning`. Metric: certified
`success` (the badge), draft `light`, retired `dark`. Edge `via`: load `info`, promote `primary`, transform `success`, export `secondary`.
Ids: `transform-list`, `transform-card-{id}`, `transform-{id}-run`, `transform-runs-table`, `transform-form`, `transform-form-field-{name}`, `metric-list`, `metric-card-{id}`, `metric-chart`,
`metric-form`, `metric-form-field-{name}`, `products-list`, `product-card-{id}`, `lineage-graph-svg`, `lineage-up`, `lineage-down`.

## Out of scope for this slice
Contracts and certification of products (slice 5's — a product is certified there); sharing a product with a sibling (slice 9's `share_application`); the expert's use of metrics (the
skills; slice 10 wires the agents); column-level lineage for anything but SQL transforms (Extended).

## Proof (`tests/phase3/slice7/run.sh`: scratch `dl_dev7` and a scratch lake under `var/dev7/` through `tests/engine/setup_scratch.sh`'s pattern; the query service and the writer
started from `services/` for the run; ports 9001–9007)
A product made by a transform from a curated dataset (the run's snapshot, rows, lineage edge, `lineage_runs` START and COMPLETE); a transform whose owner may not read an input is refused at
save with the input named; new SQL is version 2 and refrozen; `incremental_merge` with a `GROUP BY` fails the run with `incremental_needs_rowwise`; `after_inputs` runs after a load lands and not
before; a cron schedule fires once per due minute; a masked input arrives masked in the product (the owner at `read`); a metric on a curated dataset is refused; `metric_value` by month and by a
dimension equals the same SQL run by hand; the chart renders 12 points; editing the expression drops the certification; an agent's `metric_certify` and `transform_delete` pause; the lineage tab shows
one hop each way and "1 hidden" for an input the viewer may not discover; the graph's SVG has five columns; `impact` lists the metric and the transform downstream; JSON mode; 375 and 1280;
`php bin/build_action_registry.php --check` (10 screens, 7 actions built, 3 approvals).

## Open questions
None — decisions taken: inputs from the query service's zero-row run as the owner (§ Divergences 1); the writer refuses non-row-wise incremental SQL (2); cron matched in PHP (3); server-rendered SVG
for the graph (4) and the metric chart (5).

## Built and proven (2026-10-11)
A transform is a named SQL over dataset keys that makes a curated, product or sandbox dataset, and **the writer runs it as its owner could read** when the SQL was saved. **Saving**
runs the SQL through the one door as the saver (a `sql` request with `dry_run`, source `transform` — the allow-list's sentence on `sql`; an input the saver may not read refused with its
key named; a transform that reads its own output, or an output already upstream of an input — a cycle — refused), then `dl_transform_freeze()` stores the saver's policy over the inputs
(the ceiling of the product for good); new SQL is the next version, **refrozen as whoever saved it**. **Running**: `transform_run` makes the `transform_runs` row (queued) and the writer's
`transform` job (`services/lake/jobs/transform.py`, THE FOURTH EXEMPLAR of the engine pattern) — the run goes `running` with the inputs' snapshots, a lineage START (the SQL as the job's `sql`
facet), `lake/transform.py`'s function (wrapped, not rewritten), the output's columns (new ones added, ones the SQL stopped making marked removed, a column that sqlglot's lineage shows
comes DIRECTLY from an input column keeps its personal kind and classification), the engine's numbers, one `dl_lineage_edge` per input with its column map, the edges of an input no longer
read pruned, the run `ok` or `no_op` (an incremental run that wrote nothing), a lineage COMPLETE, the writer's read audited (`query_runs`, source `transform`), a profile queued. A refusal the job
decides — `not_frozen`, `incremental_needs_rowwise`, `incremental_needs_key`, the allow-list, a timeout — is a `JobRefused`: the run `failed` in words, a lineage FAIL, the owner told once a day,
never retried. The worker's `schedules` pass queues what is due: a **cron** schedule matched in PHP in the business's time zone (`app/features/transforms/cron.php`, once per due minute),
`after_inputs` when an input holds a newer snapshot than the last run read it at. **Metrics**: an aggregate defined once over a product, validated and asked through the new `metric` kind
(`services/query/metric.py` compiles the request to ONE SELECT, `server._metric` runs it exactly as `sql` — the allow-list, the caller's policy for the product, the masks, the audit with the metric's
id); the page draws the last twelve periods as a **server-rendered SVG** (no library) with a grain picker and a "Cut by" picker that re-render the chart and the table in place. **Lineage**: the
dataset page's Lineage tab (one hop each way, "n hidden"), the graph at `/datasets/{id|key}/lineage?depth=` (an SVG of 2 × depth + 1 columns), and the impact of a change. The **product gallery**
and the home's Certified products tile. The registry reads **64 screens and 86 actions built** (10 + 7 here; the three approvals — `transform_delete`, `metric_certify`, `metric_retire` — in step).

**Proven** by `tests/phase3/slice7/run.sh` (scratch `dl_dev7` with the engine real — the writer and the query service on their units' command lines, DuckLake on the scratch catalog; the lake: 1,680
orders over fourteen months, 40 customers with a personal email, a secret table nobody else may discover — `seed.py`): **349 checks under php -S and under Apache** — transforms 92, metrics 73, lineage 46, JSON 68, the browser 70
(at 375 and 1280 and without JavaScript) — plus the schema proof (**225** — db/026's eight checks), the server and engine logs and the registry `--check`. Re-run
green under both modes: slices 6 (428), 5 (310), 4 (332), 3 (220), 2 (262), 1 (229) and Phase 2 (330 / 335); the engine proof (157), Phase 0's schema (225) and kit (83), Phase 1's claims (61).

**Decisions taken in the build** (the spec left them open, or the code showed better — "taken by the worker" where the spec was silent):
1. **db/026 (additive)** — three things the spec's "no migration" did not foresee: `GRANT DELETE ON transforms` (db/012 gave the application SELECT, INSERT, UPDATE; `transform_delete` could not have run — the
   runs go by their foreign key, the output dataset STAYS, its lineage edges and `lineage_runs` lose the transform and stay as history); `dl_lineage_edge_prune(transform, kept inputs)` (a transform that stops
   reading an input must lose that edge — `dl_lineage_edge` only upserts and the writer holds SELECT on `lineage_edges` alone); `dl_transform_failed_notify(transform, words)` (the spec's "dedupe per transform
   per day": `dl_notify` dedupes ten minutes and the writer cannot read `notifications`; the kind is db/014's `load_failed`, the record `transform`).
2. **`row_limit: 0` is the existing `dry_run`** — the spec said a save "sends the SQL with `row_limit: 0`" and a metric "validates through a `metric` request with `row_limit: 0`"; slice 3 already built that
   device as `dry_run: true` (parse, allow-list, policy, `EXPLAIN`, no rows, audited), and both use it. Saving a metric binds the compiled SELECT as the saver, so a column the expression names that the product
   lacks is refused on `expression` and someone who cannot read the product cannot define a metric over it; certifying runs the same dry run as the certifier ("it must run for you").
3. **The `metric` kind compiles, then runs as `sql`** (`services/query/metric.py`, `server._metric`): the expression is ONE aggregate (sqlglot: an `AggFunc` present, no subquery, no table, no window, no `;`
   or comment), the fixed filter a condition with no aggregate, a `grain` the metric does not allow, a `by` or `where` key that is not one of its dimensions and a malformed date are **`invalid_metric` with the
   `field` to fix** (audited as a refusal, with no SQL text). Every value — the window's ends, a `where` value — is a bound parameter. No new column in the audit: `query_runs.metric_id` was already there.
4. **Who owns a transform after a change**: the saver, when the SQL changed (the freeze is the saver's level — "refrozen as you"); the same SQL keeps its owner. **An agent saves a transform only in its own duty**
   (it freezes the agent's own level); in a person's chat turn it is refused — the frozen level is the owner's alone and the intersection (K38) cannot be frozen into it. An output the saver
   cannot discover is "not here" on `output_dataset` — a hidden dataset never confirms itself, its key is not echoed.
5. **A sandbox transform is its owner's alone**: `mcp_transforms` shows a transform to whoever discovers its output, and a sandbox is nobody else's — so "transforms.manage or the owner" is, for a sandbox
   output, the owner (a data engineer gets 404, not 403).
6. **`after_inputs` fires when ANY input holds a newer snapshot** than the one the last finished run read it at (the spec's "every input's `last_snapshot_id` newer" read as "each is checked"), and when it has
   never run and every input has data; the run records the lake's current snapshot at its start for each input, so the transform's own commit never re-triggers it (proven). A transform saved after its last
   run is judged by its cron from its save time (saving at 09:05 does not fire the 09:00 slot); a run `queued` or `running` for over six hours is abandoned (failed) by the worker's pass first.
7. **Incremental**: the key is the OUTPUT dataset's `dedupe_key` (its contract's), refused at the form AND by the writer (`incremental_needs_key`) when it has none; `since` is the last ok/no_op run of the same
   version (new SQL starts again from the whole result); a run that writes nothing is `no_op` — no profile job, "data updated" not moved.
8. **One run at a time**: a run queued or running is that run (`new: false`, no second row, no second job); delete is refused while one is in flight.
9. **Column lineage** is sqlglot's per output column: DIRECT when every step is a column, an alias, a cast; INDIRECT through a function or an aggregate; each edge keeps only the columns of ITS input;
   anything it cannot resolve is left out and the edge is table-level (`{}`).
10. **The chart is drawn twice** — a wide drawing from 768 px and a compact one on a phone (the same numbers, labels every third period): the spec's "scales by its viewBox" left a 640-px drawing's axis labels at
    4.9 px on a 375-px screen. One series, no legend; the value axis from zero; a period with no rows is a gap, never a zero; the last value labelled; each marker's value as its tooltip; the table under it is the chart's table view.
11. **The lineage graph is one SVG with a depth** (1–4, default 2 = five columns); the sources sit at the depth the walk finds them; the hidden ones collapse into ONE dashed node per column; a source is
    named only to someone who may see sources; a stub is the same 404; the hop lists and the impact under it carry everything as text (what a phone reads). `/datasets/{id|key}/lineage` is the graph by an
    explicit rule in both vhost blocks and the dev router — the **tab** stays `?tab=lineage`.
12. **Impact** (`dataset_impact`) applies the wall to `dl_impact`'s answer: datasets, metrics, transforms and saved queries the caller cannot see are counted in `hidden`, never named; the K7 consumers are
    named to the dataset's owner or a `grants.manage` holder and only counted for anyone else.
13. **The keys beside the SQL** are a plain list of the datasets I may read with an insert button each (`tag-editor.js`'s `data-insert-into`), not slice 3's catalog partial (that one belongs to the editor's
    textarea and `sql-editor.js`); no new script.
14. **Four pickers** were added to `app/pickers.php`: `transform`, `transform_output` (the datasets a transform may make — curated or product at write, or my own sandbox, that no transform makes yet; `except`
    keeps the one being edited), `metric`, `product`.
15. **The home's Certified products tile is built** (`certified_products()`, six cards, a link to the gallery; the JSON's `products` is the rows). The phase 2 and slice 1 proofs that asserted it pending or the
    Lineage tab unbuilt, the unbuilt kind (`metric` → `product_rows`, slice 9), the unbuilt job kind (`transform`) and `/transforms/1/run` answering 404 (a handler answers a GET with 405) follow what is built.
16. **Files beyond the spec's list**: `services/query/metric.py`, `db/026_slice7_transforms.sql`, `app/views/metrics/partials/{card,result,columns}.php`, `app/views/lineage/partials/{hops,impact}.php`,
    `tests/phase3/slice7/seed.py`; `frame.lineage_start/_end` gained `facets` and `transform_run_id`; `lake_metric()` in `app/lake/client.php`.

**Reviewed by the planning model (2026-10-11): decisions 4 and 6 accepted.** 4 — a transform runs later, unattended, as its owner; freezing an agent's level
intersected with one person's into it would keep that person's reach in a standing job after the turn ends (and after they lose it), so refusing in a person's turn
is the fail-closed reading (D8) — the person saves it themselves, or the agent in its own duty. 6 — "any input newer" is what keeps a product current; "every
input newer" would leave a product built from a daily and a monthly input stale for a month.

**Found and fixed in slice 7:** a function name already taken (`present_impact` — contracts); a lineage presenter not required by the transform page (JSON mode only); the picker's first page
satisfying a "wait for the row" before the search had filtered (the proof now waits for exactly one row); a chart drawn for 640 px read on a phone.

**What a worker copies for slices 8–10** (by file): **a job that runs a person's frozen policy** — `services/lake/jobs/transform.py` (`_frozen()` from `query_results.py`, the run's row owned by PHP and
updated by the writer, `JobRefused` with a `code — words` message the page splits in `transform_error_parts()`); **a schedule the worker decides in PHP** — `app/features/transforms/cron.php` +
`transforms_due()` + `worker_schedule_transforms()`; **a query kind that compiles a request to SQL** — `services/query/metric.py` + `server._metric` (compile → `_sql`); **an SVG drawn on the server** —
`app/features/lineage/svg.php` and `app/views/metrics/partials/chart.php` (classes for colour, `viewBox`, a text alternative, the facts also as lists); **a screen family with a polled table** —
`transforms/partials/runs-table.php` (as sources' pulls); **a wall that counts what it hides** — `lineage_hops()`, `dataset_impact()`; **the proofs** — `tests/phase3/slice7/lib.php`
(`standard_transforms()`, `worker_pass()` — the worker as a child process with a fake clock —, `metric_req()` straight on the socket, `append_orders()` — an input changing), `run.sh`, `browser.mjs`.
