---
name: describing-data
description: How to describe a dataset and its columns for the catalog — ODCS's purpose, usage and limitations, one line per column — from the schema, the profile and the lineage, never from personal values.
---
# Describing data

A description is written for the person who will find the dataset in a search a year from now.

**The dataset** (ODCS `description`):
- **Purpose** — what one row is and why the dataset exists: "One row per sales order since 2024, deduplicated by order id."
- **Usage** — what it is good for and how to use it: "Revenue and order counts by day, region and product; join to
  `curated.products` on `product_id`."
- **Limitations** — what it must not be used for, and its known gaps: "Excludes orders cancelled before shipment; refunds are in
  `curated.refunds`. Updated nightly at 02:00 — not for intraday questions."

**Each column**: one line — what the value means, its unit or format, and when it is empty. "Order total in USD including tax;
empty for draft orders." Give a business name when the physical name is cryptic (`amt_net` → "Net amount").

**Sources of truth, in order**: the contract (types, key), the profile (null %, distinct %, ranges, top values — masked for personal
and confidential columns), the lineage (which source, which transform), the column names. **Never** quote a value from the data, never
infer a person's details, never guess a unit — write "unit unknown" and ask the owner in your rationale.
