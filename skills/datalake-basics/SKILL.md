---
name: datalake-basics
description: What the Data Lake is and which tool answers what — zones (landing, raw, curated, product, sandbox, quarantine), datasets keyed <zone>.<name>, columns with classifications and personal kinds, the levels (discover, read masked, read_unmasked, write, own), grants, masks and row filters, checks, incidents, certification, lineage, query runs, metrics. Load before any Data Lake work.
---
# The Data Lake — the basics

**A dataset** is one table in one zone with its catalog entry; its key is `<zone>.<name>` (`curated.sales_orders`) and never changes.
**Zones**: *landing* (files as received, kept 30 days after a good load) → *raw* (a table as received: every column text, every row
with its provenance `_dl_*`) → *curated* (typed by the dataset's **contract**, deduplicated, checked; rows that fail a blocking check
go to *quarantine* with a reason) → *product* (owned, certified, what people and agents use by default). A person's *sandbox* holds
what they saved from the editor — theirs alone, never certified, as classified as its inputs.

**Who sees what** (the database decides; you never filter by hand):
- Levels: `discover` (the entry, no rows) < `read` (rows with personal and confidential columns **masked**) < `read_unmasked` <
  `write` < `own`. The owner owns; the steward reads.
- Data is deny-by-default: a **grant** (on a dataset, a zone or a tag; to a member, group, department, agent or application) gives a
  level, maybe a **row filter** and an expiry.
- The catalog is open by classification: everyone inside discovers `public` and `internal` datasets; a `confidential` or
  `restricted` one shows as a **stub** (name, owner, "request access").
- **An agent in a person's chat turn reads only what both the agent and that person may read.**

**Trust**: `checks` run on every load (ODCS's vocabulary: not null, unique, accepted values, range, regex, row count, freshness,
referential, schema, custom SQL); a failure opens an **incident**; **certification** is earned (owner, steward, descriptions, a
passing blocking check, an active contract, fresh, no open error incident) and **lost by itself**. A **verified query** loses its
badge when its SQL changes. **Lineage** is recorded on every load, promotion and transform.

**Which tool answers what**: find → `catalog_search`, `find_datasets`, `get_dataset`; describe → `dataset_describe`, `dataset_columns`;
trust → `dataset_health`, `open_incidents`, `get_incident`; where from → `lineage_upstream`; what breaks → `lineage_downstream`,
`impact`; numbers → `find_metrics`/`metric_value`, `find_saved_queries`/`run_saved_query`, `query_run`; access → `my_access`,
`access_explain`, `access_list`.

**Never**: export, grant, decide access, change a classification or a mask, certify, verify, publish a contract, roll back, erase —
those pause for a person or are a person's alone.
