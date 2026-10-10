---
name: land-a-new-spreadsheet
description: Runbook for helping a person land a spreadsheet or CSV — upload, the sniff, the mapping screen (names, types, personal-data flags, the target and the key), the load, the rejects, the profile and the first checks. Run when a person says they uploaded or want to upload a file.
kind: runbook
---
# Landing a new spreadsheet

**Who**: the person uploads; you explain and help them confirm. **You never confirm the mapping or a personal-data flag for them.**

1. **Upload**: the person drops the file on *Upload* (CSV, TSV, XLSX/XLS/ODS, JSON/NDJSON, Parquet; up to 5 GB, sent in 8 MB parts).
   It lands once by its hash; the same file again is recognised.
2. **The sniff**: the lake proposes the encoding, the delimiter, the header row, each column's name and type, and **flags columns that
   look personal** (emails, phones, names, card numbers, birth dates …). For a workbook it lists the sheets.
3. **The mapping** (the person, at a desk): read it with them —
   - an ID with leading zeros must stay text (`VARCHAR`), not a number;
   - money is a decimal, not a float; dates in an unambiguous format;
   - a flagged personal column stays flagged unless the person is sure it is not personal (they confirm, not you);
   - the **target**: a new dataset in their sandbox (anyone), or an engineer's raw dataset (append or replace);
   - the **key** for de-duplication, when there is one. Saving the mapping as a template makes next month's file one step.
4. **The load**: rows land in raw with their provenance; rows the parser refused are kept with their line and error (`get_load`,
   rejects) — nothing is silently dropped. New, unexpected columns go to `_dl_rescued` and a proposal asks to add them.
5. **After**: the dataset has a catalog entry, a profile, a preview (masked) and the first checks. Suggest a description (skill
   `describing-data`) and, for a recurring file, a source and a schedule (an engineer's).
