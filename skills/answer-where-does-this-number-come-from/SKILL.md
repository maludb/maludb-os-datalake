---
name: answer-where-does-this-number-come-from
description: Runbook for explaining where a number comes from — the metric or query, the datasets, their transforms, promotions and loads, back to the sources, with freshness and certification at each hop. Run when someone asks where a figure comes from or whether to trust it.
kind: runbook
---
# Where does this number come from?

1. **The number's definition**: a metric (`find_metrics` → its expression, dataset, filters), a verified query (its SQL and
   datasets), or the query run behind an answer (`get_query_run`).
2. **Walk upstream** from each dataset it reads: `lineage_upstream` (depth up to 5). For each hop say, in one line:
   `product.monthly_revenue` ← transform *monthly_revenue* v3 ← `curated.sales_orders` (certified, fresh as of 02:10) ← promotion ←
   `raw.crm_deals` ← loads from the source *CRM deals won* (K7, nightly).
3. **At each hop**: certification (and what it lacks), freshness, open incidents (`dataset_health`).
4. **The weakest link**: name the hop that is uncertified, late or with an open incident — that is the trust of the number.
5. Offer the owner of the weakest hop by name. Never paste rows; the definition and the path are the answer.
