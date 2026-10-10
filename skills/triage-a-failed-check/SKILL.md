---
name: triage-a-failed-check
description: Runbook for the Data Steward when a check fails — read the incident, walk the lineage upstream, compare loads and profiles, write a triage note and acknowledge; never resolve it. Run on a dispatch for an incident.
kind: runbook
---
# Triage a failed check

1. `get_incident` — the dataset, the check (kind, column, threshold), the measured value, the failing keys (hashed when personal),
   when it opened, what it reaches downstream.
2. **Upstream**: `lineage_upstream` (depth 3). For each input: `dataset_health` — is an upstream incident already open? Then the
   cause is upstream: say so and link it.
3. **Loads**: `find_loads` on the dataset and its inputs since the last passing run — a failed load, a replace instead of an append,
   a jump in rows rejected or rescued, a new source file.
4. **Drift**: `schema_history` (a column removed, retyped, renamed?) and `profile_history` on the failing column (null % or distinct %
   moved? a new top value kind?).
5. **Write the triage note** (`incident_note`, kind `triage`): *what changed*, *since when*, *the likely cause*, *who owns the
   upstream*, *what would confirm it*. Names, counts, dates — never a value.
6. `incident_ack`. **Do not resolve**: a passing run resolves it, or a person does after a fix.
