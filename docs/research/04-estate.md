> Research report for `docs/datalake-design.md` §0, written 2026-10-10 by a research agent of the planning session (web sources cited inline; local facts read on the build server). Kept as written; the design document is where its findings become decisions.

## Survey for maludb-os-datalake: findings

Line numbers are given as file:line. Today's sibling DBs are `subello_<key>`.

### 1. How a lake would read the siblings (K7)

**How it works**
- `POST /api/v1/apps/read.php {provider, tool, arguments, location_id?}` (/var/www/html/api/v1/apps/read.php:7-9). The bearer token is the consumer's application token on the internal port.
- The work is done by `application_read()` in /var/www/app/features/applications/services.php:241-315. In order:
  - checks the share exists and is not withdrawn;
  - checks the `people` rule: only the directory-writes app (HR) may read a people share (:258);
  - checks for an approved connection (:262);
  - strips the reserved args `scope_id, consumer, consumer_agent_id, as_agent` (:231, :268);
  - sends the `X-OS-Consumer` / `X-OS-Consumer-Agent` headers (:271-278);
  - for a scoped share, injects the provider's `scope_id` (:280-295);
  - makes one synchronous MCP call per request (:301).
- Tables:
  - `application_shares` and `application_connections`: /var/www/db/161_app_services.sql:58-92. Connections are proposed, then approved by a super-admin, and revoked rather than deleted.
  - `timeout_seconds` (1–60, default 8): /var/www/db/173_k26_share_consumer_and_timeout.sql.
- The installer proposes a connection for each `reads[]` entry (sms-and-reads.md:74-75), so the lake must list every share it reads in `reads[]`.

**Every share declared today (39). All per-site=false and timeout 8 s unless marked.**

| App | Share | Purpose |
|---|---|---|
| consultant_tracking | invoices_issued | invoices in a period, lines by kind |
| consultant_tracking | credit_notes_issued | same shape, negative |
| consultant_tracking | expenses_by_category | company-card expenses by category and month |
| consultant_tracking | unbilled_summary | work-in-progress (WIP) at bill value as of a date |
| consultant_tracking | reimbursements_approved | **people:true**, so HR only |
| consultant_tracking | engagement_list | active engagements, no money |
| doccloud | folders_index | folders shared with the asking app |
| doccloud | documents_index | paged, `limit` ≤200, `offset`, `since` (mcp/dc_shares.py:17-21) |
| doccloud | document_text | **60 s**, at most 5 MB of text |
| doccloud | document_link | signed 10-minute URL; the bytes never cross the kernel |
| gl | account_balances | balances by account as of a date |
| gl | ap_due_summary | due per week, no vendor named |
| gl | ar_aging_summary | aging buckets, no customer named |
| inventory | sales_closed | period, paged ≤500, totals cover the whole period (db/016_phase1_fixes.sql:118-122) |
| inventory | purchases_received | receipts at cost in a period |
| inventory | stock_valuation | stock at cost as of a date |
| inventory | availability_index | per variant: retail, state, lead time |
| inventory | customer_orders | a customer's open orders, by email |
| knowledge | ask | **60 s**, a cited answer |
| knowledge | search | **60 s**, evidence passages |
| knowledge | bases_index | bases shared with the asking app |
| processcore | shipments_by_period | **15 s** |
| processcore | inventory_valuation | **20 s** |
| processcore | open_orders | a customer's open orders |
| processcore | finished_stock_available | **15 s** |
| reservations | covers_by_service | **per-site** (scoped): covers per date and service |
| sales_crm | accounts_index | paged ≤500, `since` (db/017_phase1_reads.sql:97-106) |
| sales_crm | contacts_index | paged, `since` |
| sales_crm | deals_won | period, paged |
| sales_crm | deals_open_by_account | one account's open deals |
| sales_crm | account_timeline_summary | last ten touches, no bodies |
| shareholder_intelligence | filings_index | an issuer's filings |
| shareholder_intelligence | filing_text | **60 s**, extracted text of one filing |
| shareholder_intelligence | ownership_summary | aggregates, no holder names |
| shareholder_intelligence | signals_open | open signals with severity and evidence class |
| shareholder_intelligence | events_index | events in a window |
| spaces | pages_index | answered as the consumer's expert agent, so the lake needs an expert agent |
| spaces | page_markdown | same as above |
| txtschedules | time_off_taken | **per-site and people:true**, so HR only |

- **No shares at all:** cidery, helpdesk, hr, pro_appointments, projects.
- **Owed but not yet shared** (named in other apps' `reads[]`): GL `invoice_receipts` (G1), `customer_by_crm_account` (G3), `items_index` (G4); Help Desk `articles_published` (HD2), `tickets_by_organization` (HD5); HR `reimbursements_paid` (H2).
- DocCloud and Knowledge answer only for folders or bases shared with the asking app.

**Does K7 suit bulk extraction? No — it suits small, summary or incremental reads only.**
- One synchronous call per request.
- Each answer is capped at 256 kB. The refusal says "ask for a shorter period" (services.php:19, :302-304; sms-and-reads.md:53).
- The timeout is at most 60 s.
- There is no paging or cursor at the kernel level. Paging exists only inside a few shares: the `*_index` shares with limit/offset/since, and sales_closed.
- Every call is written to the kernel's activity log (`application.read`).
- A bulk feed would need each provider to add new paged `since` shares, following the shape of CRM `accounts_index` and DocCloud `documents_index`.

**Bulk, export and period endpoints that exist**
- GL `/exports.php`: 12 files per period (ledger-detail, journal, chart, trial-balance, P&L, balance sheet, AR/AP aging, invoices, bills, receipts, payments) as CSV or ZIP (gl/html/exports.php:1-3; app/features/exports/queries.php:10-26). It is a session-authenticated screen behind the `exports.download` right, not a machine API.
- ProcessCore `period-reports/csv.php`: a screen download, built from `app.period_reports` / `period_report_lines` / `report_line_map` (db/011_shipping_reports.sql:103).
- Kernel `GET /api/v1/ledger/periods.php?period=YYYY-MM`: the AI-statement period document `os.ledger-period/1` (html/api/v1/ledger/periods.php:4-9). It uses the application token on the internal port.
- HR `GET /api/v1/pay-runs`: document `hr.pay-runs/1`, needs an HR staff member's API token (hr/html/api/v1/pay-runs.php:3).
- Inventory `/api/v1/availability` (api_key).
- Bearer-token `/api/v1/` APIs in sales_crm, doccloud, knowledge, shareholder_intelligence, pro_appointments and reservations.
- ICS calendar feeds in sales_crm and txtschedules.

### 2. Databases and roles
- The DB name is `<tenant>_<database.suffix>` (bin/app_install.php:211, :236; registration.md:134). Every suffix equals its app key.
- Existing DBs: subello_{cidery, consultant_tracking, doccloud, doccloud_memory, gl, helpdesk, hr, pro_appointments, processcore, projects, reservations, spaces, txtschedules}. Inventory, knowledge, sales_crm and shareholder_intelligence are not applied yet.
- Roles per app: `<key>_rw` (except cidery and processcore, which use `<key>_app`), `<key>_records_ro` and `<key>_activity_ro`. All exist in pg_roles.
- `records_ro` is not a bulk-read role. It reads `security_barrier` `mcp_*` views gated on `app.member_id` (memory.md:20-32).
- **The contract forbids reading another application's database directly:**
  - shared-schema.md:65-68: "never `\i` or `\include` another application's file, never `REFERENCES` a table in another database, never read `/srv/apps/<other>` at install or at run time, never make a `database.provision` script look for a sibling. The only cross-application dependency allowed is a K7 read, and its absence is the `no_connection` answer the application already handles."
  - :13-14: "If another application **owns that data**, do not copy its table — read it through the kernel (K7 `reads[]`, the directory mirror)."
  - :45: "Hold at most the provider's id and the display fields you need … refreshed from a K7 read".
  - SKILL.md:80-82: "**Never** connect an application to the kernel's database, or the kernel to the application's … MaluDB episodes, MCP, the hand-off token and the directory, ledger and chat endpoints are the only crossings."
  - SKILL.md:31 audit question: "reach into another application any way but the kernel?"
- **Granting the lake read-only roles on sibling databases would therefore need an owner-approved contract change (a new K-item).** So would the lake keeping copies of owned data, which goes beyond ":45 ids + display fields". This is the first decision the design must put to the owner.

### 3. Models to copy rather than reinvent
- **Inventory `sources` / `source_credentials` / `source_pulls` / `source_templates`** (inventory/db/009_suppliers_sources.sql:76-190).
  - Fields: connector, settings jsonb, schedule, rate, robots state, back-off/pause; a pull carries counts, policy and status; credentials are libsodium-sealed with last4.
  - Fetch layer: app/sources/{Connector.php (probe/pull/search/lookup/capabilities), http.php (the one outbound client), robots.php, credentials.php (the only decryptor), pulls.php (worker lifecycle), normalize.php, connectors/}.
  - SI copied it verbatim with roles widened (si/db/009:24-30; app/si/fetch).
  - Lake use: the canonical "external source + pull" model. A connector kind `k7` could wrap kernel reads.
- **Knowledge `feeds`** (knowledge/db/012_feeds_agents_notifications.sql:17-37): a provider/tool/arguments row read on a schedule (every_minutes, next_sync_at, last_status including no_connection). Lake use: the existing pattern for a scheduled K7 ingestion.
- **Knowledge extractor and sectioner** (app/knowledge/extract/{extract,detect,pdf,ooxml,html,plain,text,section}.php).
  - `kn_extract_result`: one shape covering pdf/docx/pptx/xlsx/html/md/csv/json, with pages, blocks and character offsets.
  - `kn_section_document`: section.php:20.
  - Tables: `sources`, `source_text`, `source_pages`, `sections` (knowledge/db/007:16-72).
  - DocCloud copied the code renamed to `dc_` (doccloud/app/doccloud/extract/extract.php header). Lake use: file → text → profiling.
- **DocCloud store** (doccloud/db/007_documents_versions_blobs.sql:17-150).
  - `blobs`: sha256 UNIQUE, path `blobs/<aa>/<bb>/<sha>`, `dc_blob_claim` at :247.
  - `documents`, `document_versions`, `document_text`.
  - `upload_sessions` / `upload_parts`: part_bytes 1–64 MB, default 8 MB (db/005_settings.sql:24), resumable, 24 h expiry.
  - Code: app/features/files/upload.php; app/doccloud/store.php (`DC_STORAGE_DIR` at :21).
  - Lake use: raw-zone landing for files.
- **Spaces**
  - `databases` (schema in `properties` jsonb; relations and rollups, with rollups computed at read time and never stored), `database_views` (filter/sort/group/layout), `row_relations`: spaces/db/009_databases.sql:3-75.
  - `search_index` canonical: spaces/db/013_search.sql:9-25. Reused by DocCloud db/010 (entity_kind widened, columns appended), CRM db/014 and SI db/015.
  - Lake use: dataset schema as properties, saved views, and the cross-entity catalog search.
- **Tags**
  - Help Desk `tags` (slug/label/usage_count) at helpdesk/db/009_vocabulary.sql:107.
  - The polymorphic `record_tags` from Sales CRM db/006:39-53, which is the shape to copy.
  - Lake use: tagging datasets and columns.
- **Import with rollback**
  - Sales CRM db/012_campaigns_lists_views.sql:121-174: `imports` (mapping, dedupe_policy, dry_run, rollback_until, rolled_back_at), `import_templates`, `import_rows` (per-row result).
  - SI `imports` with control-total tie-out (`si_import_tie`: control_totals vs derived_totals; nothing downstream until tied or overridden) at si/db/007:3-18. Its own `import_templates` (connector, mapping, sample) is at si/db/014:301.
  - Lake use: CSV/XLSX ingestion with rollback, and the tie-out as a data-quality gate.
- **Exports**: Spaces db/016 (+023 params), copied in CRM db/012:177 and SI db/014:222. Async csv/json, expires_at. Lake use: dataset downloads.
- **Saved views**
  - Help Desk `saved_views` (db/011), copied verbatim in CRM db/012:88.
  - DocCloud `saved_searches` (db/013).
  - Projects `saved_filters`.
- **Jobs and workers**: there is no generic `jobs` table.
  - `worker_passes` (Spaces db/016:53; copied in DocCloud, CRM, SI).
  - DocCloud `graph_jobs` / `graph_documents` / `graph_usage`: a per-document queue with a monthly budget and retries (db/012_graph.sql:15-45, next job at :166).
  - Knowledge `builds` / `build_items`.
  - `agent_dispatches` (GL db/014 canonical).
- **GL period exports** (above) and the GL share functions `gl_share_*` (gl/db/020_shares.sql): the pattern of SECURITY DEFINER share functions that only the kernel token may call.
- **Kernel reporting** (/var/www/db/062_reporting.sql:1-8, tables :12-107): `report_definitions` name an MCP tool plus parameters, never SQL, and run as the member. Also `report_runs`, `report_schedules`, `dashboards`, `dashboard_widgets`. This is the model for lakehouse dashboards.
- **SI `signal_rules` / `signals`** with an evidence class (si/db/013): a model for data-quality rules.

### 4. Catalogue tables a lake would touch, and name collisions (shared-schema.md:70-125)
- **Lake would reuse**:
  - `sources` / `source_pulls` / `source_credentials` (:103, Inventory canonical);
  - `search_index` (:104);
  - `reports` and `import_templates` (:117, SI);
  - `imports` / `exports` (:118, "stay Spaces'");
  - `import_rows` (:118, CRM);
  - `record_tags` / `tags` (:119);
  - attachments, notes, notifications*, agent_dispatches, app_settings (:91-99).
- There is no `datasets`, `catalog`, `lineage`, `schemas` or `columns` table anywhere in the estate, so these would be new canonical tables.
- **Collisions to avoid**:
  - `sources`: Knowledge's means an ingested document (:103). Inventory's is the one to copy.
  - `documents`: DocCloud's is a versioned file record.
  - `databases` / `database_views`: Spaces' are Notion-style databases.
  - `pipelines`: Sales CRM's are sales pipelines; the kernel also has one in db/031.
  - `reports`: SI's is an approved pack; the kernel has `report_definitions` / `report_runs`.
  - `events` (SI and reservations), `runs` (ProcessCore production runs), `feeds` (Knowledge), `builds` / `sections` (Knowledge), `watches` (Inventory), `catalog_items` / `application_catalog` (kernel), `time_entries` (already a recorded collision).
- **A divergence to flag**: `import_templates` is listed canonical twice, with different shapes — CRM (dedupe_policy, is_shared, owner) versus SI (connector, sample, name UNIQUE).
- **Owned data, read and never copied** (:122-125): HR employment; GL chart, parties and books; the CRM relationship; Projects issues; Help Desk tickets; Consultant Tracking engagements and time; Reservations covers; txtSchedules shifts; the kernel's directory.

### 5. MaluDB
- The API runs on port 8000 from /home/maludb/maludb-python-api-server/app/routers/.
- **Data model** (graph.py:922-992; maludb_core 0.104.0+):
  - `POST /v1/datamodel/refresh` (`maludb_datamodel_refresh`): introspects tables, views, matviews, columns, primary keys, routines, triggers, `fk_references` / `depends_on` / `triggers_on` / `belongs_to` edges, and inferred reads/writes into a graph namespace `datamodel`.
  - `GET /v1/datamodel/describe?relation=` returns live columns, primary key and foreign keys in and out.
  - **Limit:** it only sees the tenant's own memory schema plus `maludb_core` / `public` (maludb-core/sql/extension/maludb_core--0.106.0.sql:51858-51878 — "schema % is not visible to this tenant"). It cannot introspect sibling app databases.
- **Graph**:
  - `POST /v1/graph/import`: node-link JSON into a namespace; idempotent; caps of 50k nodes and 200k links (graph.py:40-41, :584). This is how a lake could load its own catalog of sibling schemas as a graph.
  - `GET /v1/graph/neighbors | walk | path | stats | query` (lexical seed plus bounded walk) `| god-nodes` (highest degree) `| surprises` (cross-community edges).
  - `GET /v1/communities` and `GET /v1/communities/{id}/members`.
  - `GET /v1/edges`.
- **MCP tools**: implemented in the `maludb` CLI (Rust binary /home/maludb/.local/bin/maludb, run as `maludb mcp`; source not on disk), as wrappers over those routes:
  - `db_schema`: describe a relation.
  - `db_related`: FK neighbours, dependent views, routines that read or write it.
  - `db_join_path`: the FK path between two tables, shortest first, hop budget 6.
  - `db_impact`: reverse-dependency walk before ALTER/DROP.
  - `db_model_refresh`: re-introspect.
  - `graph_walk`, `shortest_path`, `god_nodes`, `get_communities` / `get_community`, `graph_surprises`, `query_graph`, `graph_import`.
- **Documents**:
  - `/v1/documents` CRUD and `/v1/documents-backfill`.
  - `POST /v1/memory/documents` (title + text into a namespace), `/v1/memory/ingest` (extraction), `/v1/memory/search`, `/v1/memory/answer` (cited answer), `/v1/memory/remember` and `/recall` (index without a model), `/v1/memory/reindex/run`, `/v1/memory/embeddings/run`.
- **Episodes**: `/v1/episodes` CRUD and `/{id}/statements`. Every app's `activity_log` is shipped here as `activity` episodes carrying `application` (memory.md:9, :105-144). This is a cross-application activity source, subject to 0.106.0 principal scopes.
- **Other**: subjects, verbs, statements, attributes and attribute-templates, document-types and episode-types, projects, pools, notes, skills, tokens (`POST /v1/tokens`).
- **Precedent**: Knowledge and DocCloud each keep a memory database of their own (`subello_doccloud_memory`; K24 db/171).

### 6. Ports, names, migration, category
- **Kernel ports**: 8080 (PHP), 8811–8816 (MCPs and runner), 8000 (MaluDB), 3000 (React).
- **App ports (app / records MCP / activity MCP)**:

| App | Ports | Note |
|---|---|---|
| HR | 8180/8821/8822 | |
| Projects | 8181/8823/8824 | |
| Help Desk | 8182/8825/8826 | |
| Cidery | 8183/8827/8828 planned | installed on 8104/8105/8106 |
| GL | 8184/8829/8830 | |
| Consultant Tracking | 8185/8831/8832 planned | installed on 8107/8108/8109 |
| Spaces | 8186/8833/8834 | |
| Knowledge | 8187/8835/8836 | |
| Inventory | 8188/8837/8838 | |
| ProcessCore | 8189/8839/8840 | |
| DocCloud | 8190/8841/8842 | |
| Sales CRM | 8191/8843/8844 | |
| SI | 8192/8845/8846 | |
| txtSchedules | 8101/8102/8103 | test ports 8194/8195/8197 |
| Reservations | 8100 | |
| Pro Appointments | 8110 | |

- **Scratch ranges in use**: 8291–8297 (Help Desk), 8301–8307/8310 (Consultant Tracking), 8391–8400 (GL), 8401–8407/8410 (Spaces), 8501–8507, 8601–8607, 8701–8707, 8801–8807, 8901–8907.
- **Next free block**: **8193 / 8847 / 8848**, scratch **9001–9007**. All are unreferenced anywhere, and nothing listens on them.
- **Latest kernel migration**: **db/178** (178_k34_shareholder_intelligence_catalog.sql). db/drafts is empty, so the lake's catalog row would be db/179.
- **Categories**: `APPLICATION_CATEGORIES` (/var/www/app/features/applications/queries.php:30-32) = platform, accounting, crm, calendar, email, documents, storage, **database**, communication, automation, development, security, inventory, manufacturing, investor_relations, other.
  - The two CHECKs are `application_catalog_category_check` (db/172:10-13, then db/178:14-18) and `applications_category_check` (db/175:6-9, then db/178:20-24).
  - **`data` or `analytics` would need widening in BOTH CHECKs plus the PHP list in one file** (the K30 lesson recorded at db/175:2-5 and db/178:6-8).
  - **`database` is already admitted** and needs no change.

### 7. Design-doc template
- **SI** (shareholder-intelligence-design.md) and **DocCloud** (doccloud-design.md) share the same skeleton:
  - 0 The research: 0.1 the field / 0.2 (SI: owner's reference impl, plus 0.2b real files; DocCloud: what the estate gives) / 0.3 (SI: data inventory; DocCloud: what MaluDB gives) / SI 0.4 estate survey and 0.5 MaluDB
  - 1 What it is, and is not
  - 2 Who uses it
  - 3 Who sees what
  - 4 Doors for those not inside
  - 5 Agents and the application
  - 6 Memory model (subsections per feature; the last is **"Tables against the estate — read, reuse, new"**: SI §6.8, DocCloud §6.4)
  - 7 The question inventory
  - 8 The kernel's part and the app's doors
  - 9 Screens
  - 10 Build order, gates and size
  - 11 Extended, later, and what others owe
  - 12 What the kernel, MaluDB and siblings owe
  - 13 The decisions
  - 14 Ports, names and files
  - 15 The owner's answers
  - 16 State
- **Where decisions and answers are recorded**:
  - Decisions are a §13 table (`# | Decision | Recommendation | Alternative`): SI :846, DocCloud :639. Same in Sales CRM :606 and Knowledge :536; ProcessCore uses §12 :228.
  - Owner's answers are a §15 table (`# | Decision | Where it lives`): SI :903, DocCloud :691. Older apps use "§12/14/15 The owner's answers — rules, not questions" (GL :489, Consultant Tracking :551, Spaces :606, Help Desk :351, Projects :282, txtSchedules :261).
  - Ports and names go in §14 (SI :875, DocCloud :664).
  - The kernel's /var/www/CLAUDE.md:39 keeps a one-paragraph status per app (approval, ports, K-items).
