> Research report for `docs/datalake-design.md` §0, written 2026-10-10 by a research agent of the planning session (web sources cited inline; local facts read on the build server). Kept as written; the design document is where its findings become decisions.

# Lakehouse front ends, screen by screen: research for the SMB lakehouse application (2026-10-10)

**Method.** I split the research across four parallel workers (commercial lakehouses; Google, Microsoft and AWS catalogs; open-source catalogs; query and BI tools), each told to cite official documentation or the product's own GitHub UI strings. I then re-checked the most surprising claims myself:
- Snowflake CoWork runs on the user's credentials.
- Dataplex was renamed Knowledge Catalog.
- Amundsen was archived.
- Superset's mobile mode exists, with the limits described below.
- Metabot sees only what the user sees.
- Genie runs data access as the end user.

All six were confirmed. Bracketed keys such as [D5] point to the Sources list at the end. "Unconfirmed" means a name could not be verified.

**Products renamed or retired in 2026.** Use the current names in the design:
- **Databricks:** Databricks Assistant is now **Genie Code** (March 2026) [D32]. Genie spaces are now **Genie Agents**. Databricks One is now **Genie One**, the business-user home; its /one URL still works [D27][D31]. Delta Sharing appears in the UI as **OpenSharing** [D22].
- **Snowflake:** Worksheets are now **Workspaces** [S13]. Copilot is now **Cortex Code** [S16]. The chat product is **Snowflake CoWork**; its objects are still named SNOWFLAKE_INTELLIGENCE_* [S20].
- **Google:** Dataplex Universal Catalog was renamed **Knowledge Catalog** on 10 April 2026 [G1].
- **AWS:** the DataZone portal is now **SageMaker Unified Studio** [A1].
- **Amundsen** was archived on 29 September 2026 [AM1]. Use it only for reference.

---

## Part A: What they do, concern by concern

### A1. Main navigation

- **Databricks** [D1]:
  - Sidebar: Workspace, Recents, Catalog, Jobs & Pipelines, Compute, Discover, Marketplace.
  - SQL group: SQL Editor, Queries, Dashboards, Genie Agents, Alerts, Query History, SQL Warehouses.
  - The home page leads with an **Ask Genie** box (modes Ask / Search / Genie Code), then Suggested, Favorited, Popular and What's new.
- **Databricks consumers** [D27]: someone with only Consumer access lands on **Genie One**. It has a search bar with Search and Ask modes, **For you** (Recently opened, Favorites, Recently shared, Trending), **Domains** and **Scheduled tasks**. Search filters there are Type, Owner, Status (Certified, Favorite), Domains and Last modified.
- **Snowsight** [S1]:
  - *Work with data:* Projects, Ingestion, Transformation, AI & ML, Monitoring, Marketplace.
  - *Horizon Catalog:* Catalog (Explorer, Internal Marketplace, Apps), Data sharing, Governance & security (Trust Center, Tags & policies, Users & roles).
  - *Manage:* Compute, Admin.
- **OpenMetadata** [OM1]:
  - The landing page "My Data" shows an Activity Feed (All / @Mentions / Tasks), My Data, a KPI widget, Total Data Assets, Announcements, Following and Recent Views.
  - Menus: Explore; Observability > Data Quality; Data Marketplace > Domains; Govern > Glossary; Settings > Services.
- **Metabase** [MB19]:
  - Sidebar: Home, Collections, Bookmarks, Library, and Data (Databases / Models / Metrics).
  - **+ New** offers Question, SQL query, Dashboard and Document; Cmd-K opens the command palette.
- **Superset** [SS1]:
  - Top level: Dashboards, Charts, Datasets.
  - SQL menu: SQL Lab, Saved Queries, Query History.
  - Settings: Database Connections, Security (Roles, Users, Row Level Security, Action Log), Alerts & Reports.
- **Marquez** [MQ1]: DATAOPS, JOBS, DATASETS, EVENTS.

**The pattern:** the mature platforms have two front doors. One is for builders (catalog, editor, jobs). The other is a consumer home (search or ask, certified content, domains).

### A2. The dataset (table) page and its tabs

| Product | Tabs and header |
|---|---|
| Databricks | **Overview** (an About panel with **AI generate**, Owner, tags), **Columns** (a **View relationships** ER diagram), **Sample Data** (can take a plain-language question), **Details**, **Permissions** (Grant), **Policies**, **History**, **Lineage**, **Insights**, **Quality** [D2][D3][D4][D10][D11][D12][D24] |
| Snowflake | **Table Details** (owner role, rows, size, definition, Privileges, **Assigned Contacts**), **Columns**, **Data Preview** (first 100 rows), **Lineage**, **Data Quality**; header buttons **Load Data** and ⋮ Edit / Clone / Drop / Transfer Ownership [S2][S4][S10][S12] |
| Dremio | **Data**, **Details** (columns, Markdown wiki, labels), **Lineage**, **Reflections**. Hovering a list row shows a card with path, last updated, column count, a Query icon and the column list [DR2][DR3] |
| Starburst Galaxy | Header: Description, Source comment, Tags, Contacts, Owner, **Query data**, **Set notification**. Panes: **Columns**, **Metrics** (Queries over time, Top users), **Quality**, **Lineage**, **Definition**, **Data preview**, **Query history**, **Audit log**, **Privileges** [SB4] |
| BigQuery / Knowledge Catalog | **Schema**, **Details** (stewards, Aspects), **Preview**, **Lineage**, **Data profile**, **Data quality**, **Insights** [G2][G4][G6][G9] |
| Fabric OneLake | Item details: **Overview** (description, refreshed, owner, sensitivity, endorsement, Tables with **Explore this data**), **Lineage** (with impact analysis), **Monitor**, **Permissions** (with pending access requests) [M2] |
| SageMaker Unified Studio | **Business metadata**, **Schema** (per-column business name, description, terms), **Lineage**, **Data quality**, **Subscription requests**, **History** (every edit is a version) [A1][A2][A8] |
| OpenMetadata | Header: Owner, Tier, Usage, Follow, Version History, Share, Announcements. Tabs: **Schema** (plus "Frequently Joined Tables"), **Activity Feeds & Tasks**, **Sample Data**, **Queries**, **Data Observability** (Table Profile / Column Profile / Data Quality), **Lineage** (with an edit mode), **Custom Properties**, **Contract** [OM3][OM4][OM5][OM16] |
| DataHub | **Documentation**, **Columns**, **View Definition**, **Preview**, **Lineage**, **Access**, **Properties**, **Queries**, **Stats**, **Quality**, **Governance**, **Runs**, **Incidents**. Sidebar: About, Owners, Domain, Data Product, Tags, Glossary Terms, Status. Queries and Stats stay greyed out until a usage source is ingested [DH2][DH3] |
| Amundsen | Header: badges, **Preview**, **Explore**. Body: Description (with a **Request Description** link), Last Updated, Date Range, Tags, Owners, **Frequent Users**, programmatic descriptions. Tabs: Columns, Dashboards, Upstream, Downstream [AM2] |
| Atlas | **Properties**, **Lineage**, **Relationships**, **Classifications**, **Audits**, **Schema**, **Profile**, **Tasks** [AT3] |
| Unity Catalog OSS | Description, columns (Name, Type), Created / Updated / format. No sample, lineage, tags or permissions; those are on the "v0.8+ ❓" roadmap [UC1][UC2] |
| CKAN | Dataset page: **Data and Resources**, **Additional Info** (Source, Author, Maintainer, Version, Last Updated, Created). Resource page: **Data Dictionary**, "Data last updated" kept separate from "Metadata last updated", a filterable datatables view [CK4][CK5][CK6] |
| Hue | **Overview**, **Relationships**, **Partitions**, **Sample**, **View SQL**, **Details**, **Privileges** [HU2] |
| Marquez | **LATEST SCHEMA**, **VERSION HISTORY** (with "CREATED BY RUN"), **COLUMN LINEAGE** [MQ1] |

**The set they converge on:** About/overview, Columns, Preview, Profile/stats, Quality, Lineage, Usage/queries, Access/permissions, and History/audit.

### A3. Search and facets

- **Databricks** [D6][D8]:
  - Cmd-P opens search; facets are Type, owner, last modified, catalog, schema, Tag, **Certification status**.
  - Keyword syntax works, e.g. `type:table owner:me tag:k:v`.
  - Results can be sorted by popularity; semantic search is in preview.
- **Snowflake universal search** [S5]: searches names, comments and tags, never table contents. Plain-language queries work, and results cover only objects the user's roles can see.
- **Knowledge Catalog** [G1]:
  - Natural-language search by default.
  - Filter sections: Scope, Systems, Projects, Type, locations, datasets, Aspect types. Sections combine with AND; filters within a section combine with OR.
  - "Search results are scoped to your access level."
- **OpenMetadata** [OM2]: quick filters Owner, Tag, Tier, Data Product, Service, Database, Schema, Columns; sorting by Last Updated, **Weekly Usage** or Relevance.
- **DataHub** [DH1][DH8]: facets Platform, Tags, Glossary Terms, Domain, Owners, plus a **Has Active Incidents** filter.
- **Starburst** [SB3]: Cmd-K; facets Asset type, Catalog, Tags, Contact, Owner.
- **CKAN** [CK2]: facets Organizations, Groups, Tags, Formats, Licenses.
- **Ranking signals:**
  - Amundsen ranks "page-rank style … based on usage" [AM1].
  - Metabase ranks Verified items and Official collections higher [MB7][MB8].

### A4. Tags, classification and glossary

- **Tags:**
  - Databricks separates **Governed** tags (lock icon) from **Other**. System tags show a wrench icon and can be hidden [D4].
  - Snowflake writes classification results to system tags (SEMANTIC_CATEGORY, PRIVACY_CATEGORY). Tag-based masking then masks newly found sensitive columns automatically [S6].
- **Automatic PII classification:**
  - Databricks: switched on per catalog; writes `class.*` tags [D25].
  - Snowflake: a review queue in Trust Center (**Objects that need review** → Pending review / Selected) [S7].
  - OpenMetadata: an **Auto Classification Agent** that combines regex on column names with NLP on sample data, at threshold 80 [OM11].
  - Atlas: a classification spreads along lineage, with an off switch per edge [AT1].
- **Glossary:**
  - Knowledge Catalog: categories up to 3 levels deep, 5,000 terms; a term's **Related entries** can be columns [G3].
  - OpenMetadata: a term starts as **Draft**; a Task goes to its Reviewers; with no reviewers it auto-approves. A "Mutually Exclusive" setting allows one term per group [OM10].
  - DataHub: Term Groups contain Terms [DH5].
  - Purview: **Critical data elements**, a logical column mapped to physical columns [M11].
  - Atlas: a term can carry a classification [AT2].
  - Metabase: the Library glossary is fed to Metabot [MB6].
- **Importance tiers:** OpenMetadata **Tier1–Tier5**. Fix Tier 1 first; Tier 5 means unused [OM7].

### A5. Certification, endorsement and deprecation

- **Databricks** [D6]: ⋮ → **Assign certification** → Certified / Deprecated / None. Stored as the system tag `system.certification_status`, shown as an icon and searchable.
- **Fabric** [M4]: **Promoted** (anyone who can write the item), **Certified** (only reviewers an admin names; anyone may request it), **Master data**.
- **Superset** [SS4]: **Certified by** and **Certification details** on datasets, on individual metrics, and on charts and dashboards, plus a Certified column in the dataset list.
- **Metabase** [MB7][MB8]: a blue-check **Verified** badge, set by admins only. **Editing a verified question removes the badge.** Official collections carry a yellow badge.
- **Collate (commercial OpenMetadata)** [OM18]: Gold / Silver / Bronze assigned by a rule workflow over owner, description, tier and custom properties.
- **DataHub** [DH6][DH15]: a Verification form produces "Verified" or "Documented". "Mark as Deprecated" takes a Note and a **Decommission Date**.

### A6. Access requests and approvals

- **Databricks** [D2][D7]:
  - A **Request permissions** button sits on every object. Every permission error (SQL editor, dashboards, Genie) also offers **Request access**, with the objects filled in.
  - Requests route to the object's own destination, then the owner, then a parent's destination, then the metastore default. Destinations can be email, Slack, Teams, a webhook or a redirect.
  - The approver chooses **Add principal to group(s)** or **Grant privileges**, with presets such as **Data Reader**.
- **Snowflake** [S22][S23]:
  - When a query fails, **Resolve Access Error** first checks whether a role the user already holds would work.
  - The request requires a reason; an end date is optional.
  - Approvals arrive in **Governance & security » Requests & Approvals**: Pending / History, Sent to me / Created by me, with **Grant access / Mark as approved / Reject**.
  - Organizational listings follow the same path: **Request access** (role, reason) → **Provider Studio » Requests**.
- **Knowledge Catalog** [G8]: a data product's **Request access** asks for the access group and a justification; it is tracked under **My requests**. Statuses read No Access → Under review → Approved → Access granted / Rejected.
- **Purview** [M6]:
  - A policy states permitted purposes, manager approval, privacy review, approvers, an access provider, attestations and terms of use, and offers **Preview request form**.
  - Statuses: Pending / Declined / Approved / Completed. Provisioning is done by hand.
- **SageMaker Unified Studio** [A4][A5][A6]:
  - **Subscribe** asks for a project and a justification; rules can require metadata forms first.
  - The approver chooses **Full access** or **Approve with row and column filters**.
  - Approval is automatic within the same project or when the asset needs no approval.

### A7. Data products and sharing

- **Starburst Galaxy** [SB1][SB2]:
  - The creation wizard has three steps: Location, then Details (name, summary, Markdown description, links, owner), then Datasets (SQL → **Run and preview dataset**, 10 rows → Table / View / Materialized view).
  - The product page has tabs **Overview / Datasets / Usage examples**, with a right rail for Contacts, default cluster and created/updated.
  - A product can be created from a schema (**Promote to data product**) or straight from query results.
- **Purview** [M5]:
  - Lifecycle: Draft → Published → Expired.
  - The page shows update frequency, terms of use, an aggregate quality score, glossary terms, OKRs and subscribers.
- **Knowledge Catalog** [G7]: a product bundles assets, access groups, a **contract** (refresh cadence, quality thresholds) and documentation.
- **OpenMetadata** domains are Source-aligned, Aggregate or Consumer-aligned, with data products inside them [OM19]. **DataHub** products have **Output Ports** [DH4].
- **Sharing:**
  - Databricks Marketplace listings are **Instantly available** or **By request** [D21]; OpenSharing has **Shared by me / Shared with me** [D22].
  - MotherDuck shares are read-only zero-copy, **Discoverable** or **Hidden** [MD7].

### A8. The SQL editor

| Product | Run default / display cap | Download |
|---|---|---|
| Databricks | **Run (1000)**, LIMIT 1000 on by default; results capped at 64,000 rows; sharing as **Run as owner / Run as viewer** [D14][D15] | CSV/TSV/Excel up to about 5 GB; admin can switch off [D16] |
| Snowflake | No limit (10,000 in some account types). **Contextual statistics** (filled/empty, histograms) over up to 1M result rows [S14] | CSV/TSV; a data-movement policy can cap downloads [S15] |
| Superset | `DEFAULT_SQLLAB_LIMIT` 1,000 / `DISPLAY_MAX_ROW` 10,000 / `SQL_MAX_ROW` 100,000; 30 s synchronous timeout [SS3] | Download to CSV, Copy to Clipboard [SS2] |
| Metabase | 2,000 unaggregated / 10,000 aggregated rows displayed [MB3] | csv/xlsx/json/png up to 1,048,575 rows; a **Download results** permission of No / 10k / 1M [MB4][MB14] |
| Dremio | **Run vs Preview**; 1,000,000-row cap with a truncation warning [DR2] | JSON/CSV/Parquet; admin can switch off [DR2] |
| Starburst | 1,000-row limit removed April 2026; results over 100 MB pause to cancel or download [SB7] | CSV; **Run and download** [SB6] |
| BigQuery | A validator shows the bytes to be processed before the run [G11] | Local CSV capped at 10 MB [G12] |
| Redash / Hue | A **LIMIT 1000** checkbox [RD5]; Hue `download_row_limit=100000` with "Your downloaded results have been truncated" [HU5] | CSV/TSV/Excel |

Features most editors share:
- A schema browser on the left with insert-name and copy-SELECT [SS2].
- Autocomplete, tabs, saved queries and query history.
- Parameters: `{{var}}` with dropdowns (Metabase field filters [MB1]; Redash query-based dropdowns [RD2]).
- Snippets.
- Result to chart.
- **Result to a reusable dataset:** Superset **Save dataset** [SS2]; Starburst **Create data product** [SB1].
- **AI fix on error:** Snowflake **Fix** [S16], BigQuery "Fix it" [G13], MotherDuck **FixIt** [MD3], AWS "Fix with AI" [A9].

### A9. Dashboards, alerts and subscriptions

- **Superset** [SS6][SS10]: Draft/Published dashboards, native filters, cross-filters, **Drill to detail** with CSV/Excel export. Alerts & Reports go by email, Slack or webhook; dashboards send only PDF or PNG.
- **Metabase** [MB11][MB12]:
  - Alerts on a question fire when it returns a result or crosses a goal line.
  - Dashboard subscriptions run Hourly to Monthly with CSV/XLSX/PDF attachments and a **Don't send if there aren't results** option.
- **Redash** [RD3]: alerts are Value column + Condition + Threshold, with states TRIGGERED / OK / UNKNOWN.
- **Notifications about a dataset:**
  - Starburst: **Set notification** on ALTER/DROP or a failed quality check [SB4].
  - DataHub: subscriptions to deprecation, schema change, ownership change and assertion results [DH9].
  - OpenMetadata: alerts by email, Slack, Teams, Google Chat or webhook [OM14].

### A10. Data quality and observability

- **Databricks** [D12][D13]:
  - **Anomaly detection** learns per-table **freshness** and **completeness**.
  - Health labels (Healthy / Unhealthy / Training / Error) appear on schema pages and on lineage nodes.
  - The **Quality** tab plots predicted against observed values and names the upstream job behind a problem. Incidents are handled with **Assign to me / Not an issue**.
  - Profiling writes `_profile_metrics` and `_drift_metrics` tables plus an automatic dashboard.
- **Snowflake** [S9][S10]:
  - Data metric functions do the measuring; **expectations** turn a measurement into pass or fail.
  - The **Data Quality** tab shows a failed count, Checks by dimension, and one row per check with its latest value and a **7-day trend**.
  - A side panel shows **Quality Checks / Impacted Assets / Run History**, with **View failed records**.
- **Knowledge Catalog** [G4][G5]:
  - The profile gives null %, distinct %, top 10 values, quartiles and string lengths.
  - Rule types: Range, Null, Set, Regex, Uniqueness, Statistic, SQL. Dimensions: Completeness, Accuracy, Freshness, Volume, Validity, Consistency, Uniqueness.
  - Score = % of rules passed. Rules can be **recommended from the profile**.
- **Purview** [M7][M8]: scores roll up column → asset → product → domain. Threshold bands: Low 0–40, Medium 40–80, High 80–100. **Suggest rules** uses AI.
- **OpenMetadata** [OM5][OM6]: Column Profile shows null %, distinct % and unique %. A failing test opens an incident automatically, moving **New → Ack → Assigned → Resolved**, and resolving needs a reason.
- **DataHub** [DH7][DH8]: assertions cover Freshness, Volume, Column, Custom SQL and Schema; a **contract** is a chosen subset of them; an incident puts a health badge on the asset.

### A11. Uploads and ingestion

- **Databricks, Create or modify a table** [D23]:
  - CSV/TSV/JSON/Avro/Parquet/text, up to 10 files under 2 GB in total.
  - A 50-row preview; options for header, delimiter and **Automatically detect column types**.
  - Columns can be renamed and retyped; then **Create new / Overwrite**.
- **Snowflake** [S24]: up to 250 files × 250 MB; INFER_SCHEMA; **Append / Replace**; an **Edit Schema** mapping tab and a **Table Preview** tab.
- **Dremio** [DR6]: CSV/JSON/Parquet up to 500 MB, stored as Iceberg.
- **Superset** [SS9][SS3]: database, schema, table, **If Table Already Exists**, delimiter, date columns, preview; 100 MB.
- **Metabase** [MB13]: a CSV upload creates a table **and a model**.
- **Hue** [HU3]: two steps, **Pick data from** and **Move it to**, with type guessing.
- **CKAN** [CK3][CK5]: **Create Dataset** then **Add data**; the file loads into the DataStore and gets a Data Dictionary.
- **Connectors:**
  - OpenMetadata: **Add New Service → Test Connection → Add Metadata Agent** (schedule), then Usage, Lineage and Profiler agents [OM15].
  - DataHub: **Create source → Test Connection → Secrets → schedule → Save and Run**, with Run History [DH13].

### A12. Admin

- **Row and column policies:**
  - Databricks: a **Policies** tab with row filters and column masks that target governed tags [D24].
  - Snowflake: a data protection dashboard with **Policy coverage** [S25].
  - Superset: RLS rules (Regular/Base, group key, clause) [SS5].
  - Starburst: `HAS_TAG` expressions; DENY wins [SB10].
  - Metabase: row and column security by user attribute, but users with native SQL **bypass** it [MB15].
- **Audit:**
  - Databricks system tables: audit, lineage, query history, classification [D29].
  - Superset Action Log, including every MCP call [SS7].
  - Starburst and Atlas: an Audit tab per table [SB4][AT3].
- **Governance cockpit:**
  - Databricks Governance Hub tiles plus **Data recommendations** (missing tags or comments, unused assets to deprecate) [D26].
  - Fabric **Recommended Actions** cards: the issue, why it matters, the steps [M3].
  - OpenMetadata KPIs **Completed Description / Completed Ownership**, with a target date [OM12].

### A13. AI assistants in the UI

**Auto-documentation is always a suggestion, never written straight in:**
- **Databricks** [D5]: **AI generate** → Accept / Edit, saved only by the owner or a MODIFY holder. The docs warn: do not rely on these descriptions to find personal data.
- **Snowflake** [S3]: **Generate with Cortex**, up to 50 columns; asks before using a data sample.
- **BigQuery** [G9][G10]: **Generate and publish** or **Generate without publishing**, then **Save to details / Save to schema**. Generating needs read access to every column.
- **SageMaker** [A3]: **Accept / Reject / Accept all**; unreviewed suggestions are **never published**.
- **DataHub** [DH11]: a sparkle icon stays on AI text until a person reviews it.
- **Dremio** [DR4]: **Generate wiki/labels** → Save / Append / Overwrite.

**Ask-your-data agents, compared:**

| Agent | Grounded in | Shows its work | Runs as |
|---|---|---|---|
| Databricks Genie Agents | UC names, descriptions and keys; a per-agent **knowledge store** (synonyms, joins, SQL expressions, values); instructions; example SQL; **trusted assets** (verified, parameterised queries and functions) [D17] | Analysis steps ("Referenced SQL example"), Summary, **Show code**, applied filters; feedback **Yes / Fix it / Request review**; benchmarks scored Good / Bad / Manual review [D18][D19] | Compute on the author's embedded credentials; **data access as the end user**; row filters and masks per user [D17] |
| Snowflake Cortex Analyst / CoWork | **Semantic views** (facts, dimensions, metrics, synonyms) [S28]; a **Verified Query Repository** (`verified_by`, `verified_at`) [S18]; evaluations against those verified queries [S19] | "We interpreted your question as …" plus the SQL [S17]; "traceability to source data and queries" [S20] | "All of the queries … use the user's credentials", starting from the default role, with masking applied [S21]. Cortex Code also starts in the user's default role, and **Restrict this chat** narrows it [S16] |
| BigQuery data agents | Knowledge sources, instructions, **verified queries**, a glossary, a bytes cap [G14] | Reasoning and the sources used [G15] | "Uses your permissions"; read-only [G14][G15] |
| Fabric data agent | Up to 5 sources, instructions, up to 100 example queries per source [M9] | n/a | The user's credentials, with RLS and CLS; read-only; answers capped at 25×25. Copilot for DW reads schema only, never data [M9][M10] |
| Dremio AI agent | Wikis, labels, sampling, project context, custom instructions | Expand to see the exact SQL | Only what the user may access; **pauses for approval before any write** [DR7] |
| Starburst AIDA | **One data product's datasets** | The SQL | The user's role [SB8]. Its separate AI Agent runs queries without asking [SB9] |
| Metabot | Existing questions first; a **"Collection for natural language querying"**; a **"Verified or curated content"** switch; the glossary | Generated SQL (it "won't run the query") [MB16] | "Can only see what that person has permissions to see and do"; usage caps per group [MB17] |

**MCP servers:**
- **Superset** [SS7]: maps a JWT to a Superset user with `MCP_RBAC_ENABLED`, caps results by `SQL_MAX_ROW`, returns `executed_sql`, and writes every call to the Action Log.
- **Metabase** [MB18]: OAuth, "scoped to the connecting person's permissions".
- **DataHub** [DH12]: reads always on; writes only with `TOOLS_IS_MUTATION_ENABLED`; includes `propose_*` tools.
- **OpenMetadata** [OM17]: a personal token carries the user's role, a bot token the bot's role; writes take effect immediately.
- **MotherDuck** [MD5][MD6]: a read-only `query` tool capped at 2,048 rows / 50,000 characters / 55 s; **Guides** (metric and join definitions) are read before writing SQL.
- **Databricks** [D30]: managed MCP enforces Unity Catalog permissions; the Genie MCP is read-only.
- **Snowflake** [S27]: the managed MCP applies RBAC to tool discovery and calls, over OAuth.

---

## Part B: Synthesis

### 1. The common object model

- **Source / connection:** credentials as a secret reference, a schedule, and run history with logs [OM15][DH13].
- **The hierarchy:** catalog → schema (namespace) → dataset (table, or view = "virtual dataset") → column. Beside tables sit volumes or files (UC volumes; CKAN resources) [UC1][CK4].
- **Domain:** a business area that groups assets (Databricks Discover; OpenMetadata domain types) [D9][OM19].
- **Data product:** a curated set of datasets plus owner, contacts, description, usage examples, a contract or SLA (refresh cadence, quality thresholds), an access policy, and a lifecycle of Draft → Published → Deprecated/Expired [SB2][G7][M5].
- **Description / wiki:** with a provenance flag (human, AI-suggested, accepted, programmatic) [D5][DH11][AM2].
- **Glossary → category → term:** synonyms, related terms, linked columns, Draft/Approved; optionally a critical data element (logical column → physical columns).
- **Tag:** free or governed; a system classification such as PII with a review state; may propagate along lineage.
- **Trust status:** Certified / Deprecated / None, with who, when, a note and a decommission date; plus a tier.
- **Party roles on an asset:** owner, steward, approver, support, experts, and *derived* frequent users. Snowflake contacts with a purpose, inherited down the tree, are the cleanest form [S4].
- **Profile:** per-column statistics over time.
- **Quality chain:** check/rule (dimension, threshold, schedule) → check run (value, pass/fail) → incident (New/Ack/Assigned/Resolved, assignee, root cause). A contract is a set of checks plus schema plus SLA.
- **Lineage edge:** table and column level, created by a job, run or query; plus job and run with state and duration [MQ1].
- **Query:** saved, parameterised, with an owner; query history; snippet; **verified query** (question, SQL, verified by and when).
- **Chart, dashboard, alert or subscription.**
- **Access request:** requester, objects or product, privilege or group, reason, expiry, status, decider, decision. Plus the resulting **grant** and **policies** (row filter, column mask, tag-based).
- **Share / listing:** recipient, discoverable or hidden.
- **Task / proposal:** "request description", an AI suggestion → accept / edit / reject. Also announcements, comment threads and the audit event.
- **AI agent ("space"):** scope (tables or one data product), instructions, verified queries, benchmark questions. A conversation holds answers, and each answer holds SQL, result, feedback and review status.

### 2. Screens ranked for an SMB v1

**MUST (v1)**
1. **Home ("For you").** One box with Search and Ask; certified datasets; recently viewed and favourites; popular this month; open incidents on datasets I use; announcements. (Genie One [D27], OpenMetadata My Data [OM1], Amundsen Popular Resources [AM4].)
2. **Search results.**
   - Facets: type, domain, owner, tag, certification, health, glossary term.
   - Ranking: certified first, then usage.
   - Only objects the user can see [S5][G1].
3. **Dataset page.**
   - Header: breadcrumb, Certified/Deprecated badge, a health dot, owner and steward, "data updated" separate from "metadata updated", row count, **Query this**, **Request access**, **Follow**.
   - Tabs: **About** (description with provenance, readme, terms, tags, contacts); **Columns** (type, description, PII tag, null %, distinct %); **Preview** (first 100 rows, masked); **Quality** (checks, last run, 7-day trend, incidents); **Lineage** (one hop up and down, as lists); **Usage** (frequent users, queries, dashboards, joined tables); **Access** (who has it and how); **History** (schema versions, metadata changes).
4. **Browse.** Catalog → schema → tables, plus domain cards.
5. **SQL editor (desk).**
   - Schema browser, autocomplete, LIMIT 1000 by default.
   - A visible display cap and truncation notice; per-column result statistics.
   - Save, history, parameters, download limited by permission.
   - **Fix with AI**; **Request access** on a permission error; **Save as dataset**.
6. **Query library.** Saved queries with owner, parameters, a **Verified** badge that drops when the query is edited, and a "Run" page that shows the result only.
7. **Ask (expert conversation).**
   - The interpretation, then the result (table or chart), then "Show SQL", then the datasets and verified queries it used.
   - Feedback **Yes / Fix it / Request review**, routed to the steward.
8. **Upload a file → table.**
   - CSV/XLSX/Parquet/JSON; 50-row preview; header and delimiter.
   - Detected types the user can edit, column renames, target schema; create / append / replace.
   - Then a prompt to describe it, with an AI draft offered.
9. **Sources.** Add a connection, test it, schedule it; run history and logs; new or changed schemas.
10. **Quality and incidents.** Late tables (freshness), failing checks and an incident list with Ack/Assign/Resolve. The check editor uses templates: not null, unique, accepted values, range, row count, freshness, custom SQL.
11. **Access requests.**
    - Tabs: My requests / To approve / History.
    - Approve by adding to a group or granting a preset (Reader); a reason is required and an expiry is optional.
12. **Admin.** People and groups, grants per catalog/schema/table, tag-based masking and row filters, audit log, AI usage caps.
13. **Notifications.** Follow a dataset to hear about schema changes, failed checks, deprecation and access decisions, in-app and by email.

**SHOULD (soon after)**
- **Data products:** a gallery and a product page with Overview / Datasets / Usage examples, the contract, and Request access [SB2].
- **Glossary:** terms with definitions, synonyms, linked columns; Draft → Approved [OM10].
- **Metric definitions:** a light semantic layer. It is the biggest single lever on AI answer quality [S28][MB6].
- **Expert settings:** instructions, verified queries and benchmark questions with a score [D19][S19].
- **Review inbox:** AI-drafted descriptions and tags, description requests, PII classifications waiting for review. Each item is accept / edit / reject with a diff [OM8][S7].
- **Simple dashboards and alerts:** saved queries as tiles; email when a query returns rows or crosses a threshold [MB11].
- **Coverage page:** % of datasets described or owned, by tier, plus recommended-action cards [OM12][M3][D26].
- **Lineage graph (desk).**
- **Deprecation banner and announcements** with start and end dates [OM9][DH15].
- **Automatic PII classification** on names and samples, with a review queue [OM11][S7].

**LATER**
- Outside shares and a marketplace.
- ODCS data contracts.
- Column-level lineage graph.
- Learned anomaly detection.
- Profile drift and time-series monitoring.
- Notebooks and data canvas.
- X-rays and automatic insights.
- Embedded dashboards.
- OKRs and critical data elements.
- Cost and usage analytics.

### 3. Patterns to copy, and anti-patterns to avoid

**Copy these:**
- **Fill the catalog from the machine, not from people:**
  - Profiles computed on load.
  - Usage (frequent users, queries, joined tables) taken from the query log [D11][DH3].
  - Lineage recorded from executed SQL and loads [G6].
  - Freshness taken from commits [D12].
  - Schema versions from each ingestion run [MQ1].
  - Ranking by usage, as Amundsen did, needs no curation at all [AM1].
- **Let AI draft and a person accept.** Mark AI text visibly until reviewed; never publish an unreviewed suggestion [A3][DH11][D5].
- **Make the catalog the way to the data:**
  - **Query this** from the dataset page.
  - **Request access** from inside the error message, with the objects pre-filled [D7][S23].
  - Save a result as a dataset or product [SS2][SB1].
- **Compute trust signals instead of typing them:**
  - Health from checks.
  - Certification that requires an owner, a description and passing checks [OM18].
  - Verification that **drops on edit** [MB7].
- **Ask the owner directly.** A "Request description" task with Current / New / Difference and **Accept the Suggestion** [OM8]. Contacts with a purpose, set once at schema level and inherited [S4].
- **Measure coverage against a target, and start with the top tier** [OM12][OM13][OM7].
- **Be honest about access:**
  - Lineage greys out and groups nodes the user cannot see [S12][D10].
  - Search shows only what the user can see [G1].
  - Amundsen's preview popover says outright "User is forbidden to preview this data" [AM2].
- **Show limits:** the default LIMIT, the display cap, a truncation notice, download tiers [SS3][HU5][MB14].
- **Two dates:** "Data last updated" separate from "Metadata last updated" [CK4].

**Avoid these:**
- **The ghost-town catalog.** Teams write descriptions at launch and never again; stewards' time is reassigned within months. "Stale metadata is often worse than no metadata" [X1]. Documenting everything wastes effort on unused data; start with high-impact assets [X2]. (Both sources are vendor blogs.)
- **Required metadata forms at creation time.** Ask for metadata after the data is useful, and pre-fill it.
- **Empty tabs.** DataHub greys out Queries and Stats until a usage source exists [DH3]. Hide such a tab or say how it gets filled.
- **Sticky manual badges** that survive a change to the query or table.
- **Sending sample rows to the LLM by default.** Cloudera Hue does this with `add_table_data` = True [HU4]. Also, using AI descriptions to find PII, which Databricks warns against [D5].
- **AI that writes without approval.** OpenMetadata's MCP writes take effect immediately [OM17]. Prefer proposals [DH12] or an approval pause [DR7].
- **One shared service identity for every AI user.** With a MotherDuck API key, every user shares one identity [MD5]. This breaks per-user access.
- **Security enforced only in the app.** Metabase's native-SQL bypass of its row and column security is the example [MB15]. Enforce in the database.

### 4. Phone and desk

- **Superset's mobile mode** is the best evidence [SS8]:
  - It is switched on with `MOBILE_CONSUMPTION_MODE` and applies below 768 px.
  - Dashboards (charts stacked, filters in a drawer), the dashboard list as cards, and Home all work.
  - SQL Lab, query history, the chart builder, every create/edit screen and admin show "This view isn't available on mobile".
- **Databricks:** the Genie mobile app covers chat, dashboards ("some … interaction might be limited"), apps and scheduled tasks. It has no catalog or SQL editor [D28].
- **Snowflake:** Snowsight supports only desktop browsers [S26]. The phone experience is the **CoWork iOS app** (chat, citations, role selection) [S20].
- **No product documents a SQL editor for phones.**

**For our 375 px reading screens:**
- *Works on a phone:* home and search; the dataset page (columns as stacked cards, preview as a record-at-a-time view or a scroller inside the card, lineage as up/down lists, quality as a status list with sparklines); Ask conversations with the SQL collapsed; incident acknowledge and assign; access-request approve and reject; notifications; glossary terms; data product pages; dashboards stacked in one column.
- *Desk-only:* SQL editor, lineage graph, upload and schema mapping, check and policy editors, connector setup, dashboard editing, admin.

### 5. How the AI assistants are grounded, and whose permissions they use

- **Grounding comes in layers, the same across vendors:**
  1. Catalog metadata: names, descriptions, keys, glossary.
  2. Curated knowledge per agent: instructions, synonyms, join rules, metric definitions (Genie's knowledge store, Snowflake semantic views, MotherDuck Guides, BigQuery agent glossary) [D17][S28][MD6][G14].
  3. **Verified queries / trusted assets**, with who verified them and when, and evaluations or benchmarks against them [S18][S19][D19].
  4. Routing by trust: Metabot's verified-only switch [MB17]; Genie preferring higher-trust sources [D31].
  5. Optional data sampling, with masks applied: Dremio, Starburst Enrich, Snowflake descriptions. Fabric's Copilot for DW deliberately reads schema only [M10].
- **Citation means showing the work.** None cites like a document. They show:
  - An interpretation sentence [S17].
  - The SQL (**Show code**, `executed_sql`) [D18][SS7].
  - The verified example used [D18].
  - The sources and reasoning [G15].
  - A deep link back to the conversation from the Genie MCP [D30].
- **Permissions: the near-universal answer is "run as the asker":**
  - Genie (data access as the end user even on the author's compute), CoWork (the user's credentials and default role), BigQuery and Fabric agents, Metabot, Dremio, Starburst AIDA.
  - The Superset, Metabase, DataHub and OpenMetadata MCP servers use the token holder [D17][S21][G14][M9][MB17][DR7][SB8][SS7][MB18][DH12][OM17].
  - **Exceptions to avoid:** OpenMetadata's bot token (the bot's own role) [OM17]; MotherDuck's shared API-key identity [MD5]; the AWS Data Agent, governed by the project role (per-user filtering unconfirmed) [A9].
  - Metadata visibility follows the same rule: search, lineage and descriptions are filtered by access [S5][D10][G10].
- **Guard rails:**
  - Read-only by default [G15][M9][D30].
  - Writes only after approval [DR7][A9].
  - Row and time caps (Fabric 25×25; MotherDuck 2,048 rows / 55 s; Superset `SQL_MAX_ROW`).
  - Usage caps per group [MB17].
  - Every tool call written to the audit log [SS7].
  - A feedback-to-review loop: Genie's **Request review** and Monitor tab [D18][D19].
- **For our expert over MCP:** every tool call should carry the asking person's identity and run under that person's grants, so row filters and masks apply in the database. The expert's own grant is at most an upper bound. Answers should show the interpretation, the SQL, the datasets and verified queries used, and a feedback button.

---

## Sources

**Databricks**
D1 https://docs.databricks.com/aws/en/workspace/navigate-workspace · D2 https://docs.databricks.com/aws/en/discover/database-objects · D3 https://docs.databricks.com/aws/en/data-governance/unity-catalog/manage-privileges/ownership · D4 https://docs.databricks.com/aws/en/database-objects/tags · D5 https://docs.databricks.com/aws/en/comments/ai-comments · D6 https://docs.databricks.com/aws/en/data-governance/unity-catalog/certify-deprecate-data · D7 https://docs.databricks.com/aws/en/data-governance/unity-catalog/manage-privileges/access-request-destinations · D8 https://docs.databricks.com/aws/en/search/ · D9 https://docs.databricks.com/aws/en/discover/discover-page · D10 https://docs.databricks.com/aws/en/data-governance/unity-catalog/data-lineage · D11 https://docs.databricks.com/aws/en/discover/table-insights · D12 https://docs.databricks.com/aws/en/data-governance/unity-catalog/data-quality-monitoring/anomaly-detection/ · D13 https://docs.databricks.com/aws/en/data-governance/unity-catalog/data-quality-monitoring/data-profiling/monitor-output · D14 https://docs.databricks.com/aws/en/sql/user/sql-editor/run-queries · D15 https://docs.databricks.com/aws/en/sql/user/sql-editor/ · D16 https://docs.databricks.com/aws/en/sql/user/sql-editor/results · D17 https://docs.databricks.com/aws/en/genie-agents/concepts · D18 https://docs.databricks.com/aws/en/genie-agents/talk-to-genie · D19 https://docs.databricks.com/aws/en/genie-agents/monitor · D20 https://docs.databricks.com/aws/en/genie-code/ · D21 https://docs.databricks.com/aws/en/marketplace/get-started-consumer · D22 https://docs.databricks.com/aws/en/opensharing/create-share · D23 https://docs.databricks.com/aws/en/ingestion/create-or-modify-table · D24 https://docs.databricks.com/aws/en/data-governance/unity-catalog/abac/policies · D25 https://docs.databricks.com/aws/en/data-governance/unity-catalog/data-classification · D26 https://docs.databricks.com/aws/en/admin/governance-hub/data · D27 https://docs.databricks.com/aws/en/genie-one · D28 https://docs.databricks.com/aws/en/genie-one/mobile · D29 https://docs.databricks.com/aws/en/admin/system-tables/ · D30 https://docs.databricks.com/aws/en/generative-ai/mcp/managed-mcp · D31 https://www.databricks.com/blog/next-generation-databricks-genie · D32 https://docs.databricks.com/aws/en/release-notes/product/2026/march

**Snowflake**
S1 https://docs.snowflake.com/en/user-guide/ui-snowsight-navigation · S2 https://docs.snowflake.com/en/user-guide/ui-snowsight-data-databases-table · S3 https://docs.snowflake.com/en/user-guide/ui-snowsight-cortex-descriptions · S4 https://docs.snowflake.com/en/user-guide/contacts-using · S5 https://docs.snowflake.com/en/user-guide/ui-snowsight-universal-search · S6 https://docs.snowflake.com/en/user-guide/classify-intro · S7 https://docs.snowflake.com/en/user-guide/classify-results · S8 https://docs.snowflake.com/en/user-guide/trust-center/overview · S9 https://docs.snowflake.com/en/user-guide/data-quality-intro · S10 https://docs.snowflake.com/en/user-guide/data-quality-ui-monitor · S11 https://docs.snowflake.com/en/user-guide/data-quality-notifications · S12 https://docs.snowflake.com/en/user-guide/ui-snowsight-lineage · S13 https://docs.snowflake.com/en/user-guide/ui-snowsight/workspaces · S14 https://docs.snowflake.com/en/user-guide/ui-snowsight-query · S15 https://docs.snowflake.com/en/user-guide/data-movement-policies · S16 https://docs.snowflake.com/en/user-guide/cortex-code/cortex-code-snowsight · S17 https://docs.snowflake.com/en/user-guide/snowflake-cortex/cortex-analyst · S18 https://docs.snowflake.com/en/user-guide/snowflake-cortex/cortex-analyst/verified-query-repository · S19 https://docs.snowflake.com/en/user-guide/snowflake-cortex/cortex-analyst-evaluations · S20 https://docs.snowflake.com/en/user-guide/snowflake-cortex/snowflake-cowork · S21 https://docs.snowflake.com/en/user-guide/snowflake-cortex/snowflake-cowork/deploy-agents · S22 https://docs.snowflake.com/en/user-guide/collaboration/listings/organizational/request-approval-workflow · S23 https://docs.snowflake.com/en/user-guide/access-requests-workspaces · S24 https://docs.snowflake.com/en/user-guide/data-load-web-ui · S25 https://docs.snowflake.com/en/user-guide/data-protection-policies-snowsight · S26 https://docs.snowflake.com/en/user-guide/ui-snowsight-gs · S27 https://docs.snowflake.com/en/user-guide/snowflake-cortex/cortex-agents-mcp · S28 https://docs.snowflake.com/en/user-guide/views-semantic/editor

**Dremio**
DR1 https://docs.dremio.com/dremio-cloud/about/concepts/ · DR2 https://docs.dremio.com/current/get-started/quick_tour/ · DR3 https://docs.dremio.com/cloud/sonar/query-manage/data/datasets/ · DR4 https://docs.dremio.com/dremio-cloud/manage-govern/wikis-labels/ · DR5 https://docs.dremio.com/dremio-cloud/admin/performance/manual-reflections/ · DR6 https://docs.dremio.com/dremio-cloud/bring-data/load/ · DR7 https://docs.dremio.com/dremio-cloud/explore-analyze/ai-agent/ · DR8 https://docs.dremio.com/current/data-products/govern/row-column-policies-udf/

**Starburst**
SB1 https://docs.starburst.io/starburst-galaxy/working-with-data/share-data/create-a-data-product.html · SB2 https://docs.starburst.io/starburst-galaxy/working-with-data/explore-data/browse-data-products.html · SB3 https://docs.starburst.io/starburst-galaxy/working-with-data/explore-data/global-search.html · SB4 https://docs.starburst.io/starburst-galaxy/working-with-data/explore-data/table-level.html · SB5 https://docs.starburst.io/starburst-galaxy/working-with-data/explore-data/profile-and-quality.html · SB6 https://docs.starburst.io/starburst-galaxy/working-with-data/query-data/run-queries.html · SB7 https://docs.starburst.io/starburst-galaxy/get-started/release-notes.html · SB8 https://docs.starburst.io/starburst-galaxy/starburst-ai/ai-aida.html · SB9 https://docs.starburst.io/starburst-galaxy/starburst-ai/ai-agent.html · SB10 https://docs.starburst.io/starburst-galaxy/security-and-compliance/manage-data-access/access-control-policy-types.html

**Google**
G1 https://docs.cloud.google.com/dataplex/docs/search-assets · G2 https://docs.cloud.google.com/dataplex/docs/enrich-entries-metadata · G3 https://docs.cloud.google.com/dataplex/docs/manage-glossaries · G4 https://docs.cloud.google.com/dataplex/docs/data-profiling-overview · G5 https://docs.cloud.google.com/dataplex/docs/auto-data-quality-overview · G6 https://docs.cloud.google.com/dataplex/docs/about-data-lineage · G7 https://docs.cloud.google.com/dataplex/docs/data-products-overview · G8 https://docs.cloud.google.com/dataplex/docs/use-data-products · G9 https://docs.cloud.google.com/bigquery/docs/generate-table-insights · G10 https://docs.cloud.google.com/bigquery/docs/data-insights · G11 https://docs.cloud.google.com/bigquery/docs/running-queries · G12 https://cloud.google.com/bigquery/docs/writing-results · G13 https://docs.cloud.google.com/bigquery/docs/write-sql-gemini · G14 https://docs.cloud.google.com/bigquery/docs/create-data-agents · G15 https://docs.cloud.google.com/bigquery/docs/conversational-analytics

**Microsoft**
M2 https://learn.microsoft.com/en-us/fabric/governance/onelake-catalog-item-details · M3 https://learn.microsoft.com/en-us/fabric/governance/onelake-catalog-govern · M4 https://learn.microsoft.com/en-us/fabric/governance/endorsement-overview · M5 https://learn.microsoft.com/en-us/purview/unified-catalog-data-products-create-manage · M6 https://learn.microsoft.com/en-us/purview/unified-catalog-data-product-access-policies · M7 https://learn.microsoft.com/en-us/purview/unified-catalog-data-quality-rules · M8 https://learn.microsoft.com/en-us/purview/unified-catalog-data-quality-threshold · M9 https://learn.microsoft.com/en-us/fabric/data-science/concept-data-agent · M10 https://learn.microsoft.com/en-us/fabric/data-warehouse/copilot · M11 https://learn.microsoft.com/en-us/purview/unified-catalog-critical-data-elements

**AWS**
A1 https://docs.aws.amazon.com/sagemaker-unified-studio/latest/userguide/search-for-data.html · A2 https://docs.aws.amazon.com/sagemaker-unified-studio/latest/userguide/update-metadata.html · A3 https://docs.aws.amazon.com/sagemaker-unified-studio/latest/userguide/autodoc.html · A4 https://docs.aws.amazon.com/sagemaker-unified-studio/latest/userguide/subscribe-to-data-assets-managed.html · A5 https://docs.aws.amazon.com/sagemaker-unified-studio/latest/userguide/approve-reject-subscription-request.html · A6 https://docs.aws.amazon.com/datazone/latest/userguide/metadata-rules.html · A8 https://docs.aws.amazon.com/sagemaker-unified-studio/latest/userguide/data-quality.html · A9 https://docs.aws.amazon.com/sagemaker-unified-studio/latest/userguide/data-agent-query-editor.html

**OpenMetadata**
OM1 https://docs.open-metadata.org/v2.0.x/how-to-guides/guide-for-data-users/basics-openmetadata · OM2 https://docs.open-metadata.org/v2.0.x/how-to-guides/data-discovery/discover · OM3 https://docs.open-metadata.org/latest/how-to-guides/data-discovery/details · OM4 https://docs.open-metadata.org/v2.0.x/how-to-guides/guide-for-data-users/data-asset-tabs · OM5 https://docs.open-metadata.org/v2.0.x/how-to-guides/data-quality-observability/profiler/tab · OM6 https://docs.open-metadata.org/v2.0.x/how-to-guides/data-quality-observability/incident-manager · OM7 https://docs.open-metadata.org/latest/how-to-guides/data-governance/classification/tiers · OM8 https://docs.open-metadata.org/v2.0.x/how-to-guides/data-collaboration/tasks · OM9 https://docs.open-metadata.org/v2.0.x/how-to-guides/data-collaboration/announcements · OM10 https://docs.open-metadata.org/v2.0.x/how-to-guides/data-governance/glossary/approval · OM11 https://docs.open-metadata.org/v2.0.x/how-to-guides/data-governance/classification/auto-classification · OM12 https://docs.open-metadata.org/v2.0.x/how-to-guides/data-insights/kpi · OM13 https://docs.open-metadata.org/v2.0.x/how-to-guides/data-insights/data-culture · OM14 https://docs.open-metadata.org/v2.0.x/how-to-guides/data-quality-observability/alerts-notifications · OM15 https://docs.open-metadata.org/v2.0.x/how-to-guides/admin-guide/how-to-ingest-metadata · OM16 https://docs.open-metadata.org/latest/how-to-guides/data-contracts/spec · OM17 https://docs.open-metadata.org/v2.0.x/how-to-guides/mcp · OM18 https://docs.getcollate.io/how-to-guides/data-governance/workflows/default-workflows/table-certification · OM19 https://docs.open-metadata.org/v2.0.x/how-to-guides/data-marketplace/domains

**DataHub**
DH1 https://docs.datahub.com/docs/how/search · DH2 https://github.com/datahub-project/datahub/blob/master/datahub-web-react/src/app/entityV2/dataset/DatasetEntity.tsx · DH3 https://docs.datahub.com/docs/features/dataset-usage-and-query-history · DH4 https://docs.datahub.com/docs/dataproducts · DH5 https://docs.datahub.com/docs/glossary/business-glossary · DH6 https://docs.datahub.com/docs/features/feature-guides/compliance-forms/overview · DH7 https://docs.datahub.com/docs/managed-datahub/observe/assertions · DH8 https://docs.datahub.com/docs/incidents/incidents · DH9 https://docs.datahub.com/docs/managed-datahub/subscription-and-notification · DH11 https://docs.datahub.com/docs/automations/ai-docs · DH12 https://docs.datahub.com/docs/features/feature-guides/mcp · DH13 https://docs.datahub.com/docs/ui-ingestion · DH15 https://github.com/datahub-project/datahub/blob/master/datahub-web-react/src/i18n/locales/en/entity.shared.entityDropdown.json

**Other open-source catalogs**
AM1 https://github.com/amundsen-io/amundsen · AM2 https://github.com/amundsen-io/amundsen/blob/main/frontend/amundsen_application/static/js/pages/TableDetailPage/index.tsx · AM4 https://github.com/amundsen-io/amundsen/blob/main/frontend/amundsen_application/static/js/config/config-default.ts · UC1 https://docs.unitycatalog.io/usage/ui/ · UC2 https://github.com/unitycatalog/unitycatalog/blob/main/roadmap.md · AT1 https://github.com/apache/atlas/blob/master/docs/src/documents/ClassificationPropagation.md · AT2 https://github.com/apache/atlas/blob/master/docs/src/documents/Glossary.md · AT3 https://github.com/apache/atlas/blob/master/dashboardv2/public/js/templates/detail_page/DetailPageLayoutView_tmpl.html · CK2 https://github.com/ckan/ckan/blob/master/ckan/views/dataset.py · CK3 https://github.com/ckan/ckan/blob/master/ckan/templates/package/snippets/stages.html · CK4 https://github.com/ckan/ckan/blob/master/ckan/templates/package/resource_read.html · CK5 https://docs.ckan.org/en/latest/maintaining/datastore.html · CK6 https://docs.ckan.org/en/latest/maintaining/data-viewer.html · MQ1 https://github.com/MarquezProject/marquez/blob/main/web/src/i18n/config.ts

**Superset**
SS1 https://github.com/apache/superset/blob/master/superset/initialization/__init__.py · SS2 https://github.com/apache/superset/tree/master/superset-frontend/src/SqlLab/components · SS3 https://github.com/apache/superset/blob/master/superset/config.py · SS4 https://github.com/apache/superset/tree/master/superset-frontend/src/components/Datasource · SS5 https://superset.apache.org/admin-docs/security/ · SS6 https://superset.apache.org/admin-docs/configuration/alerts-reports · SS7 https://superset.apache.org/admin-docs/configuration/mcp-server · SS8 https://superset.apache.org/user-docs/using-superset/mobile-experience · SS9 https://github.com/apache/superset/tree/master/superset-frontend/src/features/databases/UploadDataModel · SS10 https://superset.apache.org/user-docs/using-superset/creating-your-first-dashboard

**Metabase**
MB1 https://www.metabase.com/docs/latest/questions/native-editor/sql-parameters · MB3 https://www.metabase.com/docs/latest/configuring-metabase/environment-variables · MB4 https://www.metabase.com/docs/latest/questions/exporting-results · MB6 https://www.metabase.com/docs/latest/data-modeling/semantic-layer/library · MB7 https://www.metabase.com/docs/latest/exploration-and-organization/content-verification · MB8 https://www.metabase.com/docs/latest/exploration-and-organization/collections · MB11 https://www.metabase.com/docs/latest/questions/alerts · MB12 https://www.metabase.com/docs/latest/dashboards/subscriptions · MB13 https://www.metabase.com/docs/latest/databases/uploads · MB14 https://www.metabase.com/docs/latest/permissions/data · MB15 https://www.metabase.com/docs/latest/permissions/row-and-column-security · MB16 https://www.metabase.com/docs/latest/ai/metabot · MB17 https://www.metabase.com/docs/latest/ai/settings · MB18 https://www.metabase.com/docs/latest/ai/mcp · MB19 https://github.com/metabase/metabase/tree/master/frontend/src/metabase/nav/containers

**Redash, Hue, DuckDB and MotherDuck**
RD2 https://redash.io/help/user-guide/querying/query-parameters · RD3 https://redash.io/help/user-guide/alerts/setting-up-an-alert · RD5 https://github.com/getredash/redash/blob/master/client/app/components/queries/QueryEditor/AutoLimitCheckbox.jsx · HU2 https://github.com/cloudera/hue/blob/master/apps/metastore/src/metastore/templates/metastore.mako · HU3 https://github.com/cloudera/hue/blob/master/desktop/libs/indexer/src/indexer/templates/importer.mako · HU4 https://docs.cloudera.com/cdp-private-cloud-base/7.3.2/administering-hue/topics/dw-sql-ai-assistant-service-model-related-configurations.html · HU5 https://github.com/cloudera/hue/blob/master/desktop/conf.dist/hue.ini · MD3 https://motherduck.com/docs/getting-started/interfaces/motherduck-quick-tour · MD5 https://motherduck.com/docs/sql-reference/mcp/core/query · MD6 https://motherduck.com/docs/sql-reference/mcp/guides/get-query-guide · MD7 https://motherduck.com/docs/key-tasks/sharing-data/sharing-overview

**Commentary on catalog failure (vendor blogs, treat with care)**
X1 https://www.getdbt.com/discover/understanding-data-catalogs · X2 https://www.castordoc.com/blog/why-most-data-catalogs-fail--and-how-to-get-yours-right

**Unconfirmed items**
- Whether the Databricks column list is its own tab or part of Overview (the docs disagree).
- A literal "Trusted" badge on Genie answers.
- An on-screen badge in Snowflake showing that a verified query was used (only the API's `confidence` field is documented).
- Galaxy data-product access requests and lifecycle.
- Whether OpenMetadata's Gold/Silver/Bronze workflow exists in the open-source edition.
- A literal "Certified" badge in DataHub.
- Per-user filtering for the AWS Data Agent.