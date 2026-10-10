---
name: weekday-steward-report
description: Runbook for the Data Steward's weekday duty (06:40) — steward_report, then at most twenty proposals (descriptions, personal data, owners, checks, drift, unused, promotion), then one note per department's data owners and one to the admin's assistant. Run every weekday or when an engineer asks.
kind: runbook
---
# The weekday steward report

**When**: weekdays 06:40 (the duty). **Writes**: `steward_propose`, non-blocking `check_create`, `message_send` / `notification`.
Nothing else.

1. `steward_report`. Skip anything proposed and dismissed in the last 30 days (`steward_proposals`).
2. **Descriptions** (tier 1, then 2; at most ten datasets): skill `describing-data`; `steward_propose` kind `description` and one
   `column_description` per undescribed column.
3. **Personal data**: for each column awaiting a person, `steward_propose` kind `personal_data` with the kind, the confidence and the
   evidence (name, match share — never a value).
4. **Owners**: for each dataset with none, propose its upstream's owner or its department's manager (`owner`).
5. **Checks**: for each dataset with none, the obvious non-blocking checks (`check_create`: key not null and unique, a row-count range
   from the last ten loads, freshness from the schedule).
6. **Drift / unused / promotion**: one proposal each, with the numbers.
7. **Notes**: per department, its datasets' owners get one short list (links, counts); the admin's assistant gets the coverage
   against the target, the late datasets, the open error incidents, and the age of the last restore drill (more than 30 days → say so).
At most twenty proposals in a day. A short right list beats a long one.
