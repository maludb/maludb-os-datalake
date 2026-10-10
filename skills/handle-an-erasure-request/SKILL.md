---
name: handle-an-erasure-request
description: Runbook for the admin's assistant when a person asks to be forgotten (GDPR Art. 17) — draft the erasure with the identifiers and the reason, wait for the scan's report, explain it, and leave the approval to a Lake admin who is not the requester. Never run or approve an erasure.
kind: runbook
---
# Handling an erasure request

1. **Collect**: the identifiers the person gave (an email, a customer id, a phone — each with its personal kind), the request's
   reference and date. Never paste them into a note, a message or a log — only into the erasure draft.
2. **Draft** the erasure (the admin's screen; scope *all* unless the request says otherwise). The lake **scans first**: every column of
   a matching personal kind in every zone, and every dataset made from those (lineage).
3. **Read the scan's report** to the admin: datasets, rows, zones; datasets that will lose rows downstream; loads whose landing files
   will be purged and become not replayable.
4. **Approval** is a Lake admin's who did not request it — never yours. Approval queues the erasure: delete in every zone, rewrite
   the files, expire every snapshot that held the rows, purge the landing files, then the **proof**.
5. **Tell the person** the proof's dates, including when backups are clear (the backup window).
