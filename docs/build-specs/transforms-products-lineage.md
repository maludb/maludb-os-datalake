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
`mcp_lineage_edges`, `mcp_datasets` (db/016). No migration.

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
