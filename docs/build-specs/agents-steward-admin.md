# Build spec: agents, the Steward, notifications, home and admin (slice 10 — replicates slices 1, 3, 4 and 8)

What exists at the end: the **ask** window — a conversation with the Data Lake Expert through the kernel's chat endpoint, each answer showing its work
(the interpretation, the result, Show SQL, the datasets and the metric or verified query used, the query run, a note when columns were masked) with
Yes / Fix it / Ask a steward; the **Steward's inbox** (accept, edit with the diff, dismiss) and its write door `steward_propose`; **coverage** by tier
and department; the admin's **settings**, **query limits**, **query audit**, **engine** and **agents** screens; the worker's **outbox** (email through
MaluMail, texts through the kernel — K6), **dispatches** (one chat turn on the kernel's chat endpoint per `agent_dispatches` row) and **engine health**
passes; the home's engineer and admin tiles made real. **Build the inbox exactly like slice 4's `requests` screen (cards with a decision form); the
settings and limits screens exactly like slice 1's `dataset-edit` (a full-page form with the pinned Save); the query audit exactly like slice 3's
`queries` list (filters as chips, a table); the engine screen exactly like slice 8's `maintenance` screen; the ask window's turn exactly like the shell's
command bar (Phase 2, `app/api/kernel.php`'s `ask_assistant()`), on a full page.**

Schema (db/005, 011, 013, 014, 016, 018, never modified): `dl_settings` (every window and limit, `steward_hour/minute`, `certification_target`, `unused_after_days`; db/018 appends
`steward_member_id`, `steward_proposals_per_day` and makes `dl_steward_agent()` prefer the named agent) (db/005, 018); `query_runs` (db/011); `steward_proposals`,
`dl_steward_proposal_decide()`, `agent_dispatches`, `dl_incidents_dispatch()` (db/014: an error incident dispatches the Steward), `notifications`, `notification_prefs`,
`notification_outbox`, `dl_notify()` (db/014); `dl_steward_report()`, `dl_coverage()`, `dl_catalog_gaps()`, `mcp_steward_proposals`, `mcp_agent_dispatches`, `mcp_query_runs`,
`mcp_settings`, `mcp_lake_jobs` (db/016); `dl_engine_state()` (db/018); `dl_lake_snapshots` (db/engine/001). No migration.

## Divergences from the design
1. **The ask window's turn and its feedback are doors, not actions** (like the shell's command bar): `POST /ask/turn` relays the utterance to the kernel's chat endpoint
   as the person (`ask_assistant($pdo, $me, $utterance, ['page' => 'ask', 'dataset' => ?], 'expert')`) and renders the reply; `POST /ask/feedback` records `ask.feedback`
   (`{verdict: yes|fix|steward, query_run_id}`) and, for `steward`, notifies the stewards (else the owners) of the datasets the answer's query run read (`dl_notify` kind
   `mention`, a link to the run; dedupe per run). The conversation lives in the PHP session (the last 20 turns) with a conversation key the kernel continues; nothing of it is
   stored here. **The manifest's "Doors that are not screens" must list `/ask/turn` and `/ask/feedback`** (the planning model's edit).
2. **Show SQL reads the run, not the reply**: the reply's query run ids (the actions' `record_id`s of `query_run`/`run_saved_query`/`metric_value`, and any `/runs/{id}` link in
   the text) are rendered as links; Show SQL expands `mcp_query_runs.sql` for that id (the person's own run — the agent's in their turn carries `on_behalf_of` = them).
3. **The duty's schedule is the kernel's** (the hired Steward's duty, `40 6 * * 1-5`, in Agent HR): `agents_settings_save` records `steward_hour`/`steward_minute` as what the
   lake's notes call "since the last report" and shows a link to the kernel's agent page to change the duty itself; `steward` names the agent (`steward_member_id`).
4. **Accepting a `check`, `drift`, `unused`, `promotion` or `coverage` proposal acts through the slice that owns the verb**: `check` → slice 5's check write (non-blocking; the
   decider must hold `checks.manage`, else refused `needs_checks_manage`); `drift` → slice 5's `drift_decide` write; `unused` → slice 1's `dataset_deprecate` write with the
   proposal's rationale as the note; `promotion` → a `mention` to every `promote.run` holder linking `dataset-add?zone=curated&name=<name>`; `coverage` → recorded only. The
   other kinds are `dl_steward_proposal_decide()`'s (db/014).
5. **`steward_propose` refuses** a re-proposal of a kind on the same dataset and column dismissed in the last 30 days (`dismissed_recently`) and the
   `steward_proposals_per_day`'th + 1 proposal of the day (`daily_cap`), besides the pending-one index.
6. **Engine health is read from the database and the socket**: the pass opens `DL_QUERY_SOCKET` and closes it (no query, no audit row); a job `running` for over 2 hours is
   finished as failed (`stale: the writer did not finish in 2 h` — `dl_job_finish` retries it by its attempts); queued jobs older than 15 minutes with none running notify the
   admins once a day (`mention`, "the writer has not taken a job for 15 minutes"); a socket that refuses notifies likewise.

## Who may do what
steward_propose: the Steward (any agent granted it; `catalog.browse`) · proposal_decide: people — the dataset's owner, its steward, or `catalog.edit` · settings_save: `settings.manage`,
pauses for an agent (`other`) · query_limits_set: `query_limits.manage`, pauses (`other`) · agents_settings_save: `agents.settings`, pauses (`other`) · dispatch_retry:
`agents.settings`, people. The screens: `ask` every Member (`ask.use`); `steward` and `coverage` the owners, stewards and `catalog.edit` (each sees the proposals of what they own or
steward; `catalog.edit` all); `admin-settings` `settings.manage`; `admin-limits` `query_limits.manage`; `query-audit` `audit.read`; `engine` admin; `agents-settings` `agents.settings`.

## Screens
`ask` `/ask?dataset=` (375 first: the conversation, newest at the bottom; the box with Send and the dictation hint; each answer: the sentence, a result table (≤ 20 rows; "see all" opens
the run) or a small server-rendered SVG bar or line when the result is one series over time, Show SQL, the datasets and the metric or verified query as chips, "some columns were masked for
you" when the run says so, Yes · Fix it · Ask a steward; a refusal's Request access link) · `steward` `/steward?kind=&dataset=&status=` (cards: the kind's icon, the dataset and column, the
current value and the proposed one as a word diff (descriptions) or a before/after (owner, kind, check), the rationale, Accept · Edit (the value inline, then Accept) · Dismiss (a note);
pending first, then decided this week) · `coverage` `/coverage` (the target, then by tier and by department: described, owned, classified, checked, certified as bars against the
target; the gaps list from `dl_catalog_gaps` below, tier 1 first) · `admin-settings` `/admin/settings` (the fields of `settings_save` grouped: windows, quotas, uploads, flushing, the
catalog's targets, the business) · `admin-limits` `/admin/limits` (the fields of `query_limits_set`; a note: worker count and memory apply when the owner restarts `datalake-query.target`) ·
`query-audit` `/admin/queries?member=&dataset=&status=&source=&from=&to=` (a table: time, caller, on whose behalf, agent run, source, datasets, masks, rows, ms, status, the refusal's rule;
each row opens `query-run-view` (slice 3)) · `engine` `/admin/engine` (`dl_engine_state()`: the queue, running jobs, today's runs by status, the last snapshot; the last 20 snapshots'
author and message from `dl_lake_snapshots`; the last engine-health pass) · `agents-settings` `/admin/agents` (the agents here (`find_members` kind agent) with their roles; the Steward
picker, its hour, the daily cap; the dispatches table (`mcp_agent_dispatches`): kind, dataset, status, attempts, excerpt, Retry).

**The home's tiles** (the shell's `home` renders placeholders; this slice fills them): engineers — failed loads in 24 h, late datasets (`dl_freshness_due`), the Steward's pending
proposals count; the admin — the engine (`dl_engine_state`), storage (the census's total and growth over 7 days), the last backup and drill (`mcp_backups`).

## The worker (`bin/worker.php` — this slice's passes `outbox`, `dispatches`, `engine_health`)
`outbox`: queued `notification_outbox` rows due (`send_after`) → email through `malumail_send()` (the kit's `app/mail.php`; the kind's body view `app/views/emails/<kind>.php`), texts
through `kernel_send_text()` (K6; `no_sender` → `skipped` with the detail), three attempts with back-off (`send_after = now() + 5, 25 min`), `sent_at`, `provider_ref`; a digest member's
non-urgent kinds batched into one email at 07:00 their time. `dispatches`: `agent_dispatches` with status `sent` and `run_id IS NULL`, or `failed` with `next_attempt_at` past and attempts
< 3 → one chat turn: `ask_assistant($pdo, $acting, $utterance, ['dispatch' => id, 'dataset' => …, 'incident' => …], (string) dl_steward_agent())` — the acting member the dataset's
steward, else its owner, else the first active human Lake admin; the utterance by kind (`incident`: "A blocking check failed on <key>: <title>. Triage incident <id> — runbook
triage-a-failed-check."; `check_failed`, `load_failed`, `drift`, `proposal`, `ask`, `duty_proposal` likewise, one sentence each, fixed in `app/features/agents/dispatch.php`); the reply's
first 200 characters into `reply_excerpt`; `answered` / `refused` / `failed` (`next_attempt_at` = now + 10 min × attempts). `engine_health`: every 5 minutes (§ Divergences 6).

## Files (exactly these)
`html/ask/{index,turn,feedback}.php`, `html/steward/{index,propose,decide}.php`, `html/coverage/index.php`, `html/admin/settings/{index,save}.php`, `html/admin/limits/{index,save}.php`,
`html/admin/queries/index.php`, `html/admin/engine/index.php`, `html/admin/agents/{index,save,retry}.php`; `app/features/ask/{present,write}.php`, `app/features/steward/{queries,present,write}.php`,
`app/features/coverage/{queries,present}.php`, `app/features/admin/{queries,present,write}.php` (settings, limits, audit, engine), `app/features/agents/{queries,write,dispatch}.php`,
`app/features/notify/send.php`; `app/views/ask/index.php` + `app/views/ask/partials/{turn,answer,result-table,result-chart,feedback}.php`, `app/views/steward/index.php` +
`app/views/steward/partials/{card,diff}.php`, `app/views/coverage/index.php`, `app/views/admin/{settings,limits,queries,engine,agents}.php` + `app/views/admin/partials/{dispatches-table,snapshots-table}.php`,
`app/views/home/partials/{tile-failed-loads,tile-late,tile-proposals,tile-engine,tile-storage,tile-backup}.php`, `app/views/emails/{check_failed,incident_assigned,freshness_missed,schema_changed,access_requested,access_decided,certification_lost,deprecated,export_ready,load_failed,proposal,steward_note,admin_read,erasure_done,mention,digest}.php`;
`bin/worker.php` (`outbox`, `dispatches`, `engine_health`); `tests/phase3/slice10/{run.sh,lib.php,ask.php,steward.php,admin.php,worker.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`find_proposals(PDO, array $f, int $page): array` · `proposal_diff(array $p): array` · `coverage_by_department(PDO): array` · `catalog_gaps(PDO, int $limit): array` · `settings_form(PDO): array` ·
`limits_form(PDO): array` · `audit_runs(PDO, array $f, int $page): array` · `engine_state(PDO): array` · `last_snapshots(PDO, int $limit): array` · `agents_here(PDO): array` · `find_dispatches(PDO, array $f): array` ·
`home_tiles(PDO, int $me): array` · `ask_answer(array $reply): array{sentence, run_ids, datasets, masked, chart}` · writes: `steward_propose(PDO, array $f, int $by): int` · `proposal_decide(PDO, int $id, string $decision, ?array $value, ?string $note, int $by)` ·
`settings_save(PDO, array $f, int $by)` · `query_limits_set(PDO, array $f, int $by)` · `agents_settings_save(PDO, array $f, int $by)` · `dispatch_retry(PDO, int $id, int $by)` · `ask_feedback(PDO, string $verdict, ?int $run, int $by)` ·
the worker: `worker_pass_outbox`, `worker_pass_dispatches`, `worker_pass_engine_health`

## MCP tools this slice makes real
`steward_proposals` (the proposal resolver), `steward_report`, `agent_dispatches`, `catalog_gaps`, `coverage`, `engine_state`.

## Manifest rows claimed
Screens (8): `ask`, `steward`, `coverage`, `admin-settings`, `admin-limits`, `query-audit`, `engine`, `agents-settings`
Actions (6): `steward_propose`, `proposal_decide`, `settings_save`, `query_limits_set`, `agents_settings_save`, `dispatch_retry`

## Activity log events
`steward.propose` (`{proposal_id, kind, dataset, column}` — never the proposed text), `steward.decide` (`{proposal_id, decision}`), `settings.save`, `query_limits.set`, `agents_settings.save` (each with the changed
keys and old/new numbers), `agent.dispatch` (`{dispatch_id, kind, agent, acting}`), `agent.dispatch_retry`, `agent.reply` (`{dispatch_id, status}`), `ask.turn` (`{chars, run_ids[]}` — never the utterance's
text), `ask.feedback`, `outbox.send|skip|fail`, `engine.stale_job`, `engine.alert`.

## Status vocabulary
Proposal: pending `warning`, accepted `success`, edited `info`, dismissed `secondary`, superseded `light`. Dispatch: sent `primary`, answered `success`, refused `dark`, failed `danger`,
awaiting_approval `warning`. Engine: green when nothing failed in 24 h and the socket answers, amber when a job failed, red when the socket refuses or the writer is stale. Ids:
`ask-thread`, `ask-turn-{n}`, `ask-form`, `ask-answer-{n}-sql`, `ask-answer-{n}-feedback`, `steward-list`, `proposal-{id}`, `proposal-{id}-accept`, `proposal-{id}-edit`,
`proposal-{id}-dismiss`, `coverage-tiers`, `coverage-departments`, `settings-form`, `limits-form`, `audit-table`, `audit-run-{id}`, `engine-queue`, `engine-snapshots`,
`agents-form`, `dispatches-table`, `dispatch-{id}-retry`.

## Out of scope for this slice
The MCP servers and the agents' tool wiring (Phase 4); the Steward's report's composition (the agent's, through the kernel — runbook `weekday-steward-report`); the shell's command bar
(Phase 2); hiring the agents (the owner's, Phase 5).

## Proof (`tests/phase3/slice10/run.sh`: scratch `dl_dev10`; the fake kernel answers chat turns (a reply carrying a query run id), run-facts and K6 texts; the fake MaluMail; ports 9001–9007)
An ask turn renders the reply with the run linked, Show SQL shows the person's run, a masked note when the run masked, feedback `steward` notifies the steward of the run's dataset; a Steward's
proposal (as the agent) is refused a sixth time over a cap of five and after a dismissal within 30 days; a Member who neither owns nor stewards cannot decide; accepting a description writes it
`accepted`, a `check` creates a non-blocking check for a `checks.manage` decider and is refused for an owner without it, `unused` deprecates, `promotion` mentions the engineers; coverage by tier and
department; settings and limits saved (an agent's pause), the next policy reads the new limit; the audit filters and opens a run; the outbox sends an email and a text, skips `no_sender`, retries; an
error incident's dispatch runs one chat turn as the steward and keeps the excerpt, a failure retries thrice; engine health finishes a stale job and alerts once a day; the home's tiles; JSON mode on
the six actions; 375 and 1280; `php bin/build_action_registry.php --check` (8 screens, 6 actions built, 3 approvals).

## Open questions
None — decisions taken: the ask doors `/ask/turn` and `/ask/feedback` (§ Divergences 1 — the manifest's doors list owes them); Show SQL from the run (2); the duty's schedule stays the kernel's (3);
accepting the non-catalog kinds through their owning slice's write (4); the proposal refusals (5); engine health from the database and the socket (6).
