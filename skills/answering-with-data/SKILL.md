---
name: answering-with-data
description: How to answer a question with the lake's data — catalog first, then a metric, then a verified query, then your own SQL; how to show the work (the definition or SQL, the datasets, the as-of time, the query run, the masks); when to say the data does not exist or is not trusted.
---
# Answering with data

1. **Find the data**: `catalog_search` with the question's nouns. Prefer **certified** datasets and the **product** zone. Read
   `dataset_health` for what you will use: late or with an open error incident → say so first.
2. **A metric first**: `find_metrics` — when one defines the number ("revenue", "orders shipped"), use `metric_value` with the grain,
   the period and the dimensions asked. A metric is the business's one definition; your SQL would be a second one.
3. **Else a verified query**: `find_saved_queries` (verified) whose question matches; `run_saved_query` with the parameters.
4. **Else your SQL**: `query_run` (skill `writing-safe-sql`). Keep it to the datasets the catalog gave you.
5. **Answer in one sentence with the number**, then the work:
   - *How*: the metric's name, or the verified query's title, or the SQL;
   - *From*: the datasets (linked), certified or not;
   - *As of*: the newest `data_updated_at` among them;
   - *Run*: the query run link;
   - *Masked*: "some columns were masked for you" when the answer says so.
6. **When there is no data**: say "the lake has no dataset about X" and name the nearest datasets and their owners. When the data
   exists but the person may not read it: say so, and offer `access_request_draft`.

Never round away a difference the person asked about; never present a sandbox dataset as the business's number; never compare
numbers from two definitions without saying they differ.
