> Research report for `docs/datalake-design.md` §0, written 2026-10-10 by a research agent of the planning session (web sources cited inline; local facts read on the build server). Kept as written; the design document is where its findings become decisions.

# What a data lake or lakehouse must do: requirements, architecture, and why lakes fail

Research for the self-built SMB data lake with a lakehouse front end. I did not write any files. Each claim cites a URL. Where a source is secondary (a vendor blog or a mirror), I say so.

## 0. The findings, in short

1. **The metadata layer is what makes a lake.** The CIDR 2021 Lakehouse paper defines a lakehouse as low-cost, directly accessible storage plus "a transactional metadata layer on top of the object store that defines which objects are part of a table version". It names that layer as "a natural place to implement data quality enforcement" and "governance features such as access control and audit logging" ([Armbrust et al., CIDR 2021](https://www.cidrdb.org/cidr2021/papers/cidr2021_paper17.pdf)). Every one of the four qualities in the brief lives in that layer.
2. **Swamps come from ingesting data first and never adding metadata.** Gartner warned in 2014: "Without descriptive metadata and a mechanism to maintain it, the data lake risks turning into a data swamp" ([Information Age on Gartner](https://www.information-age.com/beware-data-lake-fallacy-warns-gartner-30035/)).
3. **At SMB scale, one server with a single-node engine, batch loads and a PostgreSQL catalog is realistic.** Most organisations have less than 1 TB, and about 90% of queries scan under 100 MB ([Tigani, "Big Data is Dead"](https://motherduck.com/blog/big-data-is-dead/)). DuckLake 1.0 (April 2026) keeps all lake metadata in PostgreSQL, SQLite or DuckDB, with the data in Parquet ([DuckLake 1.0](https://ducklake.select/2026/04/13/ducklake-10/)). That fits an estate that already runs PostgreSQL 17.
4. **Two rules collide and must be designed for from the start.** Raw data is kept "append-only, never updated or deleted" ([Databricks deployment guide](https://docs.databricks.com/aws/en/lakehouse-architecture/deployment-guide/delta-lake)), but GDPR Art. 17 requires erasure "without undue delay" ([GDPR Art. 17](https://gdpr-info.eu/art-17-gdpr/)). Snapshots and backups extend how long deleted data survives.
5. **Agents must query through the same enforcement point as people, never around it.** OWASP's list for agents: minimise permissions, act in the user's context, "complete mediation" (authorise downstream, not in the model), human approval for high-impact actions, logging and rate limits ([OWASP LLM06:2025](https://owasp.github.io/www-project-top-10-for-large-language-model-applications/2_0_vulns/LLM06_ExcessiveAgency.html)).

---

## 1. Definitions and evolution

**Warehouse, then lake, then lakehouse.** The Lakehouse paper describes three generations ([CIDR 2021](https://www.cidrdb.org/cidr2021/papers/cidr2021_paper17.pdf)):
- **First:** warehouses written "schema-on-write", with compute and storage coupled in an appliance.
- **Second:** data lakes, "low-cost storage systems with a file API that hold data in generic and usually open file formats". A lake is "a schema-on-read architecture" that "punted the problem of data quality and governance downstream". A subset of the data was then copied into a warehouse.
- **Problems of the two-tier lake + warehouse setup:**
  - **reliability:** keeping two systems consistent, where each ETL step is a failure point;
  - **staleness:** it cites a Fivetran survey in which 86% of analysts use out-of-date data;
  - **limited support for ML;**
  - **total cost of ownership:** double storage and proprietary lock-in.
- **Lakehouse:** "a data management system based on low-cost and directly-accessible storage that also provides traditional analytical DBMS management and performance features such as ACID transactions, data versioning, auditing, indexing, caching, and query optimization."
- **Streaming:** the paper notes that streaming pipelines "are still harder to operate than batch jobs".

**Why the table formats exist.** Object stores are key-value stores "with no cross-key consistency guarantees". So when a job updates several files (for example "remove the records about one user across all the table's Parquet files"), "readers will see partial updates" ([Delta Lake, VLDB 2020](https://vldb.org/pvldb/vol13/p3411-armbrust.pdf)). A transaction log gives ACID, time travel and fast metadata. Delta Lake, Iceberg and Hudi each take this approach.

**The academic definition.** A data lake "ingests and stores raw data from heterogeneous sources in their original format" and provides querying "in an on-the-fly manner" with rich metadata ([Hai, Koutras, Quix, Jarke, IEEE TKDE 2023](https://arxiv.org/pdf/2106.09592)). The survey sorts lake functions into three tiers:
- **ingestion:** metadata extraction and metadata modelling;
- **maintenance:** dataset organisation, related-dataset discovery, integration, metadata enrichment, cleaning, schema evolution, provenance;
- **exploration:** query-driven discovery and querying across heterogeneous data.

It warns that ingesting disparate data "might easily turn the data lake into an unusable 'data swamp'" when metadata and governance are missing ([ar5iv copy](https://ar5iv.labs.arxiv.org/html/2106.09592)).

**Causes of a data swamp, from the sources:**
- **Gartner (2014):** a lake "accepts any data, without oversight or governance", has "embryonic" security, and creates "the inability to determine data quality or the lineage of findings" ([Gartner press release](https://www.gartner.com/en/newsroom/press-releases/2014-07-28-gartner-says-beware-of-the-data-lake-fallacy); [Information Age](https://www.information-age.com/beware-data-lake-fallacy-warns-gartner-30035/)).
- **Stonebraker (BLOG@CACM 2014):** ingest is "trivial compared to the other four steps" (transformation, cleaning, integration, consolidation). Up to ~10% of corporate data is incorrect ([CACM blog; quoted via search summary](https://cacmb4.acm.org/blogs/blog-cacm/181547-why-the-data-lake-is-really-a-data-swamp/fulltext)).
- **Dehghani (2019):** names the centralised lake's failure modes as "centralized, monolithic and domain agnostic", pipelines coupled by technical stage, and a hyper-specialised central team without domain knowledge that becomes the bottleneck ([martinfowler.com](https://martinfowler.com/articles/data-monolith-to-mesh.html)).

**Remedies documented in the sources:**
- a catalog with metadata (Gartner, Hai);
- a transactional metadata layer with schema enforcement, constraints and quarantine (Lakehouse paper);
- domain ownership and data-as-a-product (Dehghani);
- quality contracts and observability (section 3).

## 2. The zone and layer model

**Medallion layers (Databricks):**
- **Bronze** holds "raw, unvalidated data" in the source's formats. It is appended incrementally and "enables reprocessing and auditing". Databricks recommends storing most fields as string, VARIANT or binary "to protect against unexpected schema changes", and adding metadata such as the source file name. Bronze is for pipelines, not analysts.
- **Silver** "should always include at least one validated, non-aggregated representation of each record". This is where schema enforcement and evolution, null handling, deduplication, late data, quality checks, type casting and joins happen.
- **Gold** is aggregated, business-aligned and dimensionally modelled, sometimes split per domain ([Databricks medallion](https://docs.databricks.com/aws/en/lakehouse/medallion)).
- The deployment guide makes bronze the source of truth: "Data is append-only, never updated or deleted". "When all downstream data is created from the bronze layer, you can rebuild the subsequent layers from this layer if needed". And: never "skip bronze layer (always preserve raw data as source of truth)" ([deployment guide](https://docs.databricks.com/aws/en/lakehouse-architecture/deployment-guide/delta-lake)).

**AWS layout:**
- At least three layers, each in its own bucket (raw, stage, analytics), because "archiving, versioning, access, and encryption requirements can vary for each layer".
- An **extra landing bucket where sensitive data is masked before it reaches raw**.
- Raw keeps the original format with versioning on.
- An example lifecycle: raw moves to infrequent access after one year and to archive two years after that; derived intermediates are deleted sooner, for example after 90 days ([AWS Prescriptive Guidance](https://docs.aws.amazon.com/prescriptive-guidance/latest/defining-bucket-names-data-lakes/data-layer-definitions.html)).

**What each layer guarantees, in my synthesis of the above:**
- **landing:** received, checksummed, PII-screened;
- **bronze:** complete and replayable, with provenance on every record;
- **silver:** typed, deduplicated, quality-checked, one row per real entity or event;
- **gold:** business definitions, a contract and an owner.

**Reprocessing depends on immutable raw data plus idempotent transforms (section 4).** The tension with erasure is covered in 3.3.

## 3. The four qualities in the brief

### 3.1 Discoverable

**Standards:**
- **FAIR.** Findable: F1 a "globally unique and persistent identifier", F2 rich metadata, F4 "registered or indexed in a searchable resource". Accessible: A1 retrievable by identifier over an open protocol, A1.2 "allows for an authentication and authorisation procedure", A2 "metadata are accessible, even when the data are no longer available". Interoperable: shared vocabularies. Reusable: R1.1 a clear licence, R1.2 "detailed provenance" ([GO FAIR](https://www.gofair.foundation/fair-principles)).
- **W3C DCAT 3** (Recommendation, 22 August 2024) for interoperable catalogues ([DCAT 3](https://www.w3.org/TR/vocab-dcat-3/)):
  - classes: Catalog, Dataset, Distribution, DataService, CatalogRecord, DatasetSeries;
  - dataset properties: title, description, keyword, theme, publisher, contactPoint, temporal/spatial coverage, license, accessRights, conformsTo, version / previousVersion, qualifiedRelation, wasGeneratedBy;
  - distribution properties: accessURL, downloadURL, mediaType, byteSize, checksum.
- **schema.org/Dataset:** `name` and `description` (50–5,000 characters) are required. Recommended: creator, license, temporalCoverage, variableMeasured, identifier, keywords, version, and distribution with contentUrl and encodingFormat ([Google Dataset docs](https://developers.google.com/search/docs/appearance/structured-data/dataset)).

**Metadata types.** DAMA's three types are technical (schemas, lineage, permissions), business (meaning, terms, rules) and operational (job logs, audit results, retention rules). This is a secondary summary of the DMBOK ([Dataversity](https://www.dataversity.net/what-is-metadata/)).

**How established catalogs meet discoverability:**
- **DataHub:** ownership by users and groups; tags for informal discovery; **glossary terms as a controlled vocabulary**; domains as top-level groupings; an audit of metadata changes; usage and query history per dataset ([DataHub features](https://archive.docs.datahub.com/docs/0.12.0/features)).
- **OpenMetadata:** tabs per asset for Sample Data, Queries, and Profiler & Data Quality; a profiler that captures usage statistics; Tier 1–5 importance, with Data Insights flagging unused assets as Tier 5 ([OpenMetadata asset tabs](https://docs.open-metadata.org/v1.13.x/how-to-guides/guide-for-data-users/data-asset-tabs.md); [tiers](https://docs.open-metadata.org/v2.0.x/how-to-guides/data-insights/tiering.md)).
- **Amundsen (Lyft):** ranks search by relevance (names, descriptions, tags, owners, frequent users) *and* popularity (query activity and dashboards, with ad hoc queries weighted above automated ones). Lyft reported a 75% cut in discovery time. These figures are from slides and secondary write-ups ([Data Council slides](https://www.datacouncil.ai/hubfs/DataEngConf/Data%20Council/Slides%20SF%2019/Amundsen%20-%20A%20Data%20Discovery%20Platform%20from%20Lyft.pdf)).
- **Certification badges:**
  - Microsoft Fabric has *Promoted* (any writer can set it), *Certified* (only reviewers named by an admin) and *Master data*. Endorsed items get a badge and are listed first ([Fabric endorsement](https://learn.microsoft.com/en-us/fabric/governance/endorsement-overview)).
  - Collate (the commercial OpenMetadata) certifies assets Gold, Silver or Bronze by rules such as owner present, description present and tier ([Collate](https://docs.getcollate.io/how-to-guides/data-governance/workflows/default-workflows/table-certification)).
- **Dehghani's data-product qualities** include *discoverable* (a registry listing owner, origin and lineage), *addressable*, and *self-describing* (schemas and sample datasets) ([martinfowler.com](https://martinfowler.com/articles/data-monolith-to-mesh.html)).

### 3.2 Trustworthy

**Quality dimensions.** DAMA UK (2013) names six: completeness, uniqueness, timeliness, validity, accuracy, consistency ([summary](https://www.decube.io/post/what-are-the-six-dimensions-of-data-quality-and-why-they-matter); [UK Gov](https://www.gov.uk/government/news/meet-the-data-quality-dimensions)). ISO/IEC 25012 has 15 characteristics, split into inherent and system-dependent views ([arc42](https://quality.arc42.org/standards/iso-iec-25012)).

**The Open Data Contract Standard (ODCS, Bitol, Linux Foundation, Apache 2.0)** makes quality machine-checkable. v3.1.0 is the latest published release; the live docs already default `apiVersion` to v3.2.0, which is still on a dev branch ([ODCS docs](https://bitol-io.github.io/open-data-contract-standard/latest/); [v3.1.0 announcement](https://bitol.io/bitol-announces-odcs-v3-1-0-stronger-smarter-and-stricter/)).
- **Sections:** fundamentals, schema, context, references, data quality, support, pricing, team, roles, SLA, servers, custom properties, authoritative definitions, tags, variables.
- **Fundamentals:** `apiVersion`, `kind: DataContract`, `id`, `version` and `status` (draft, active, deprecated, retired), plus `domain`, `tenant`, and `description.purpose`, `.usage` and `.limitations` ([fundamentals](https://bitol-io.github.io/open-data-contract-standard/latest/fundamentals/)).
- **Schema properties:** logicalType / physicalType, required, unique, primaryKey, partitioned, **classification**, **criticalDataElement**, transformSourceObjects / transformLogic, examples ([schema](https://bitol-io.github.io/open-data-contract-standard/latest/schema/)).
- **Quality rules:** types are text, library, sql and custom. Library metrics are nullValues, missingValues, invalidValues, duplicateValues and rowCount. Dimensions are accuracy, completeness, conformity, consistency, coverage, timeliness and uniqueness. Rules carry operators such as `mustBeBetween`, a severity, a businessImpact and a schedule ([data quality](https://bitol-io.github.io/open-data-contract-standard/latest/data-quality/)).
- **SLA properties:** availability, throughput, errorRate, retention, frequency, latency, **timeToDetect / timeToNotify / timeToRepair**, endOfSupport and endOfLife. Each entry carries property, value, unit, element and driver ([SLA](https://bitol-io.github.io/open-data-contract-standard/latest/service-level-agreement/)).

**Quality checks in practice:**
- **Great Expectations:** Expectation Suites run by Checkpoints produce stored Validation Results; Actions update Data Docs or notify ([GX checkpoint](https://docs.greatexpectations.io/docs/terms/checkpoint/)).
- **Delta Lake:** schema enforcement and constraints. Clients "reject records that violate these expectations or quarantine them in a special location" ([CIDR 2021](https://www.cidrdb.org/cidr2021/papers/cidr2021_paper17.pdf)).
- **Freshness SLAs:** dbt uses `warn_after` and `error_after` with a `loaded_at_field`, set per source and overridden per table ([dbt freshness](https://docs.getdbt.com/reference/resource-properties/freshness)).

**Observability.** Monte Carlo's five pillars ([Barr Moses](https://montecarlo.ai/blog-introducing-the-5-pillars-of-data-observability)):
- **freshness:** "is my data up-to-date?";
- **distribution:** field-level health, such as the null rate;
- **volume;**
- **schema;**
- **lineage:** which upstream sources and downstream consumers a break touched.

**Lineage: the OpenLineage spec** ([object model](https://openlineage.io/docs/spec/object-model)):
- **Entities:**
  - **Job:** a unique name in a namespace;
  - **Run:** a client-generated UUID, UUIDv7 recommended;
  - **Dataset:** namespace + name "enough to uniquely identify it".
- **Event types:** RunEvent (START, RUNNING, COMPLETE, ABORT, FAIL, OTHER), JobEvent and DatasetEvent ([OpenAPI](https://openlineage.io/apidocs/openapi)).
- **Facets:**
  - run: `nominalTime`, `parent`, `errorMessage`, `sql`;
  - job: `sourceCodeLocation`, `ownership`;
  - dataset: `schema`, `lifecycleStateChange`, `version` (for example an Iceberg snapshot id);
  - input datasets: `dataQualityMetrics` and `dataQualityAssertions`.
- **Column lineage:** a column-lineage facet maps each output field to its input fields. DIRECT means the value is derived from the input; INDIRECT means the input affects it, as through a WHERE or JOIN ([column lineage facet](https://openlineage.io/docs/spec/facets/dataset-facets/column_lineage_facet/)).

**Versioning and time travel:**
- **Iceberg:** "Snapshots can be used for time-travel queries, or the table can be rolled back to any valid snapshot". After expiry they "are no longer available" ([Iceberg maintenance](https://iceberg.apache.org/docs/latest/maintenance/)).
- **Delta:** history is kept 30 days by default and records the operation, user and timestamp of every write. VACUUM's default threshold is 7 days, and time travel past it is lost ([Delta utilities](https://docs.delta.io/latest/delta-utility.html)).

**Incidents.** ODCS's `timeToDetect`, `timeToNotify` and `timeToRepair` turn incident handling into contract terms. Lineage gives the list of datasets a failure affects.

### 3.3 Secure

**RBAC versus ABAC.**
- **ABAC** decides by evaluating attributes of the subject, the object, the operation and sometimes the environment against policy ([NIST SP 800-162](https://csrc.nist.gov/pubs/sp/800/162/upd2/final)).
- **Lakehouse vendors have converged on tag-driven ABAC:**
  - **Unity Catalog:** policies attached at catalog, schema or table level match **governed tags** and apply **row filters and column masks**, so new objects "pick up protection as soon as the right tags are in place". "Agentic data classification automatically identifies sensitive data (PII, PHI)" and tags it ([Databricks ABAC](https://docs.databricks.com/en/data-governance/unity-catalog/abac/); [GA post, May 2026](https://www.databricks.com/blog/abac-row-filtering-and-column-masking-policies-governed-tags-and-data-classification-are-now)).
  - **AWS Lake Formation:** recommends tag-based access control as "the best way to scale permissions" over per-name grants. Named and tag grants combine as a union, so the named grants must be revoked afterwards ([LF-TBAC](https://docs.aws.amazon.com/en_us/lake-formation/latest/dg/tag-based-access-control.html)).
  - **Apache Atlas + Ranger:** classifications become Ranger tags that drive masking. **Classifications propagate along lineage** to derived columns ([Cloudera](https://docs.cloudera.com/runtime/7.3.1/cdp-governance-controlling-access-tags/topics/atlas-controlling-data-access-with-tags.html)).
- **PostgreSQL (relevant to a Postgres-cataloged lake):**
  - row security is default-deny once enabled;
  - **superusers, BYPASSRLS roles and normally table owners bypass it**, unless `FORCE ROW LEVEL SECURITY` is set;
  - restrictive policies AND with permissive ones ([PG docs](https://www.postgresql.org/docs/current/ddl-rowsecurity.html)).
  - So a query service must never run as the owner of the tables.

**Enforcement at one point.** The Lakehouse paper's model is that the metadata layer "check[s] whether a client is allowed to access a table before granting it credentials to read the raw data … and can reliably log all accesses". Iceberg REST catalogs (Polaris and others) implement this through **credential vending**: short-lived credentials scoped to one table ([Dremio, secondary](https://www.dremio.com/blog/apache-iceberg-rest-catalog-what-it-is-and-how-to-use-it/)). DuckLake's FAQ is explicit that "DuckLake relies on the authentication of the metadata catalog database". With a PostgreSQL catalog you get its authentication, authorisation and RLS ([DuckLake FAQ](https://ducklake.select/faq)). **Anyone who can read the Parquet files directly bypasses all of it.**

**Encryption.**
- **Parquet modular encryption:** an envelope scheme with per-file or per-column data keys wrapped by master keys in a KMS; AES-GCM ([Arrow docs](https://arrow.apache.org/docs/dev/python/parquet/parquet_encryption.html); [spec](https://apache.googlesource.com/parquet-format/+/HEAD/Encryption.md)).
- **DuckLake's ENCRYPTED mode:** creates a key per file and stores it in the catalog's `ducklake_data_file.encryption_key` ([DuckLake encryption](https://ducklake.select/docs/stable/duckdb/advanced_features/encryption)).
- So the catalog database becomes the single key store. Stolen data files are unreadable without it, but the catalog must be protected and backed up as key material.

**Audit.**
- Every read and write must be attributable.
- Delta's history covers writes only.
- For reads, pgaudit can log each relation a SELECT references, but "it is possible for pgAudit to generate an enormous volume of logging" ([pgAudit](https://access.crunchydata.com/documentation/pgaudit/1.1.2)).
- *Local note, from this estate's memory rather than the web:* on 2026-10-05 pgaudit's `function` logging filled this server's root disk. The audit design must have bounded volume and retention.

**PII detection.**
- Microsoft Presidio (open source) combines regex, checksums, NER and context in its analyser. Its anonymiser redacts, replaces, hashes, masks or encrypts, and it has image and structured-data variants ([Presidio FAQ](https://microsoft.github.io/presidio/faq/)).

**Retention, erasure and residency:**
- **GDPR principles:**
  - Art. 5(1)(c) data minimisation;
  - Art. 5(1)(e) keep identifiable data "for no longer than is necessary";
  - Art. 5(2) accountability: the controller must be able to *demonstrate* compliance ([Art. 5](https://gdpr-info.eu/art-5-gdpr/)).
- **Art. 17:** erasure "without undue delay" ([Art. 17](https://gdpr-info.eu/art-17-gdpr/)).
- **Erasure in an immutable lake:**
  - **Iceberg:** deletes are logical. Position or equality delete files in v2, deletion vectors (Roaring bitmaps in Puffin files) in v3 ([Iceberg spec](https://iceberg.apache.org/spec/)). The deleted bytes stay in the old data files until compaction rewrites them *and* expiring snapshots lets those files be removed ([maintenance](https://iceberg.apache.org/docs/latest/maintenance/)).
  - **Delta:** Databricks' GDPR procedure is DELETE, then `REORG TABLE … APPLY (PURGE)` if deletion vectors are on, then VACUUM. It adds that you "must also remember to delete data in upstream sources, such as queues and cloud storage" ([Databricks GDPR](https://docs.databricks.com/aws/security/privacy/gdpr-delta)).
  - **Crypto-shredding** (per-subject keys, destroy the key) is a common pattern for append-only stores. But I found no regulator ruling that key destruction equals erasure; sources are vendor and blog ([Conduktor](https://www.conduktor.io/glossary/crypto-shredding-for-kafka)).
  - The EDPB's draft blockchain guidelines (02/2025) discourage putting personal data in immutable stores at all ([Mondaq](https://www.mondaq.com/fin-tech/1617044/blockchain-and-gdpr-edpb-guidelines-022025-adopted)).
  - **Implication:** keep PII out of bronze where possible, using AWS's mask-at-landing step, and make erasure a designed, logged pipeline.
- **WORM retention** where wanted: S3 Object Lock in governance or compliance mode, plus legal hold ([AWS](https://docs.aws.amazon.com/AmazonS3/latest/dev/object-lock-overview.html)). Compliance mode *prevents* erasure, so use it only for data that is not personal.
- **Residency:** transfers outside the EU are allowed only under Chapter V ([Art. 44](https://gdpr-info.eu/art-44-gdpr/)). That applies to offsite backups too.

**AI agents and service accounts.**
- **OWASP LLM06** names the root causes as excessive functionality, permissions and autonomy. Fixes: minimise tools and permissions (read-only where possible), "execute extensions in user's context", "require user approval", "complete mediation" in downstream systems. To limit damage: log and monitor, and rate-limit ([OWASP LLM06](https://owasp.github.io/www-project-top-10-for-large-language-model-applications/2_0_vulns/LLM06_ExcessiveAgency.html)).
- **The MCP spec** forbids token passthrough and warns about confused-deputy proxies ([MCP security best practices](https://modelcontextprotocol.io/specification/2025-11-25/basic/security_best_practices)).

### 3.4 Usable

**Data mesh (Dehghani, 2020).** The four principles are "domain-oriented decentralized data ownership", "data as a product", "self-serve data infrastructure as a platform" and "federated computational governance". A data product is an architectural quantum of code, data plus metadata, and infrastructure ([martinfowler.com](https://martinfowler.com/articles/data-mesh-principles.html)). Its qualities include addressable, trustworthy (SLOs plus provenance), interoperable, and "secure and governed by a global access control" ([2019 article](https://martinfowler.com/articles/data-monolith-to-mesh.html)). For an SMB the useful core is: an owner per dataset and gold datasets published as versioned products with contracts. Full decentralisation is not.

**Semantic layer.** dbt Labs' 2026 benchmark (vendor-run, 11 questions × 20 runs) found:
- on a modelled project, Semantic Layer queries scored 98.2% (Sonnet 4.6) and 100% (GPT-5.3-Codex), against 90.0% and 84.1% for text-to-SQL;
- more importantly, "Semantic Layer failures look like errors, while text-to-SQL failures can return plausible but wrong numbers";
- the semantic layer "tells you it can't answer" outside its scope ([dbt](https://docs.getdbt.com/blog/semantic-layer-vs-text-to-sql-2026)).

This is the strongest argument for governed metrics in front of agents.

**Access surfaces:**
- SQL, plus a DataFrame API for ML (Lakehouse paper);
- BI over JDBC/ODBC, or Arrow Flight SQL, a columnar wire protocol with a JDBC driver since Arrow 10 ([Arrow blog](https://arrow.apache.org/blog/2022/11/01/arrow-flight-sql-jdbc/));
- read-only sharing with expiring tokens or OIDC, as in Delta Sharing's open protocol ([Databricks open sharing](https://docs.databricks.com/aws/en/delta-sharing/share-data-open)).

## 4. Ingestion

- **Files and schema inference:**
  - DuckDB's sniffer detects delimiter, quote, escape, header, types and date formats from 20,480 sample rows by default. Its limits: it can miss patterns outside the sample, it misreads ambiguous dates, and an all-VARCHAR file is assumed to have a header ([DuckDB](https://duckdb.org/docs/current/data/csv/auto_detection.html)).
  - Databricks Auto Loader infers JSON, CSV and XML columns as strings unless told otherwise, and records the schema in a schema location. It offers evolution modes `addNewColumns`, `rescue`, `failOnNewColumns` and `none`. Non-conforming values go to `_rescued_data` as JSON with the source file path ([Auto Loader](https://docs.databricks.com/aws/en/ingestion/cloud-object-storage/auto-loader/schema)).
  - The lesson: inference proposes, a person confirms, and nothing is silently dropped.
- **Schema evolution correctness.** Iceberg tracks columns by ID, so "added columns never read existing values from another column" and drops or renames never change other columns. Partition evolution is metadata-only ([Iceberg evolution](https://iceberg.apache.org/docs/latest/evolution/)).
- **Database replication and CDC:**
  - Debezium (log-based) sees each event "at least once" after crashes, and consumers must expect duplicates ([Debezium FAQ](https://debezium.io/documentation/faq/)).
  - The slot captures only changes after it is created, so an initial snapshot is needed (secondary: [Conduktor](https://conduktor.io/glossary/implementing-cdc-with-debezium)).
  - For an SMB, incremental extraction by an `updated_at` watermark plus periodic full reconciliation covers most cases. Log-based CDC can come later.
- **Idempotency, backfills and late data:**
  - Airflow: "the tasks should produce the same outcome on every re-run". Use UPSERT, not INSERT; read a fixed partition keyed on `data_interval_start`; never use `now()` ([Airflow best practices](https://airflow.apache.org/docs/apache-airflow/stable/best-practices.html)).
  - Late data and deduplication: event-time watermarks bound how long you wait. `dropDuplicatesWithinWatermark` drops duplicates only *within* the watermark ([Databricks watermarks](https://docs.databricks.com/structured-streaming/watermarks.html)).
- **Streaming.** Harder to operate than batch (Lakehouse paper), and most queries hit recent data ([Tigani](https://motherduck.com/blog/big-data-is-dead/)). Scheduled micro-batches cover SMB freshness needs. DuckLake's "data inlining" keeps small inserts in the catalog until a checkpoint, so frequent small loads do not create small files ([DuckLake 1.0](https://ducklake.select/2026/04/13/ducklake-10/)).

## 5. Storage and compute

- **Columnar format.** Parquet has row groups of column chunks with footer metadata, so "readers are expected to first read the file metadata to find all the column chunks they are interested in" ([Parquet](https://parquet.apache.org/docs/file-format/)). That enables column pruning and statistics-based skipping.
- **ACID on files.** See section 1. Use a table format (Iceberg, Delta or DuckLake), never "just a bunch of files".
- **The small-files problem.** It inflates metadata and file-open cost; compaction "will combine small files into larger files" ([Iceberg maintenance](https://iceberg.apache.org/docs/latest/maintenance/)). Dremio treats an average file under 32 MB as a warning and targets 128–512 MB (vendor: [Dremio](https://www.dremio.com/blog/apache-iceberg-small-files-problem-causes-fixes-and-prevention/)).
- **Partitioning.** Databricks: "do not partition tables that contain less than a terabyte", and keep partitions ≥1 GB ([Databricks](https://docs.databricks.com/en/tables/partitions)). For an SMB that means sorting and file statistics, rarely partitions.
- **Maintenance.** Iceberg's recommended routine is expire snapshots, remove old metadata, and delete orphan files with a retention longer than any write, default 3 days, "or risk corruption" ([Iceberg maintenance](https://iceberg.apache.org/docs/latest/maintenance/)). Delta's VACUUM is "not triggered automatically" ([Delta](https://docs.delta.io/latest/delta-utility.html)).
- **Separation of storage and compute.** It is the Lakehouse paper's "good fit" for the cloud but "one could also implement a Lakehouse over an on-premise storage system". On one server it means a logical separation (an object-store or filesystem API plus a metadata DB) so compute can move later.
- **Cost.** "Data sizes increase much faster than compute sizes" ([Tigani](https://motherduck.com/blog/big-data-is-dead/)). Retention and tiering are the cost levers (AWS lifecycle above).

## 6. Unstructured data and the AI angle

- **Unstructured data.** The Lakehouse paper stresses images, documents and sensor data that warehouses "do not easily support". Databricks governs non-tabular files with Unity Catalog **volumes** (images, audio, video, PDF) under the same permissions as tables. Files "cannot be registered as tables" ([UC volumes](https://learn.microsoft.com/en-us/azure/databricks/volumes/)). Iceberg v3 adds VARIANT for semi-structured data, row lineage (`_row_id`, `_last_updated_sequence_number`) and deletion vectors (secondary: [Dremio](https://dremio.com/blog/apache-iceberg-v3)).
- **AI-ready data.** Gartner predicts that "through 2026, organizations will abandon 60% of AI projects unsupported by AI-ready data"; 63% of organisations lack or are unsure of the right practices ([Gartner, Feb 2025](https://www.gartner.com/en/newsroom/press-releases/2025-02-26-lack-of-ai-ready-data-puts-ai-projects-at-risk)). Its framing is that readiness is proven by "metadata to align, qualify and govern the data" (via [TechTarget/secondary](https://www.techtarget.com/searchenterpriseai/feature/AI-ready-data-needs-its-own-set-of-rules-experts-say)).
- **RAG over the lake.** OWASP LLM08:2025 lists unauthorised access to embeddings, cross-tenant leaks, embedding inversion and poisoning. Its fixes: "permission-aware vector and embedding stores", classification tags on the knowledge base, validated sources, and "detailed immutable logs of retrieval activities" ([OWASP LLM08](https://genai.owasp.org/llmrisk/llm082025-vector-and-embedding-weaknesses/)). Practitioner consensus is to filter by ACL *at retrieval* and keep ACL metadata fresh (secondary: [tianpan.co](https://tianpan.co/blog/2026-05-04-permission-aware-retrieval-enterprise-rag-access-control)).
- **Governing agents that query a lake.** Combine OWASP LLM06 with the semantic-layer finding:
  - agents get catalog search and describe tools, governed metrics first and text-to-SQL second;
  - they run under the requesting person's identity or a narrow grant of their own;
  - row and column policies are enforced by the engine, not the prompt;
  - exports and writes go through approvals;
  - every query is logged with agent, run and dataset, and rate-limited.

  This matches the kernel's existing pattern: agents never hold keys, approvals stay in the kernel, and every call goes to the ledger.

## 7. Operational requirements

- **Orchestration.** Dependencies, retries, schedules and run history. Dagster backfills partitioned assets, one run per partition or a range per run ([Dagster backfills](https://docs.dagster.io/overview/partitions-backfill/backfill)). Airflow's catch-up and backfill require idempotent tasks (above).
- **Monitoring.** Pipeline failures, freshness (`warn_after`/`error_after`), volume and schema anomalies, disk use, query latency, routed to dataset owners. ODCS `timeToNotify` gives the target.
- **Backup and DR:**
  - **A snapshot is not a backup.** Snapshots share storage with the live table, and expiry deletes them.
  - DuckLake's guide: catalog and storage "have different backup strategies"; "transactions committed … after the metadata backup will not be tracked when recovering"; back up the metadata after each batch job; "Compaction and cleanup jobs should only be done before manual backups" ([DuckLake backups](https://ducklake.select/docs/stable/duckdb/guides/backups_and_recovery)).
  - For a PostgreSQL catalog, base backup plus WAL archiving gives point-in-time recovery ([PG PITR](https://www.postgresql.org/docs/current/continuous-archiving.html)).
  - Keep one offline or immutable copy offsite: 3-2-1 and its 3-2-1-1-0 variant (secondary: [NinjaOne](https://ninjaone.com/blog/3-2-1-backup-rule-explained)).
  - Erasure must reach backups too, by expiring them or crypto-shredding.
- **Cost and usage accounting.** Storage per dataset and layer; queries and bytes scanned per person, agent and dataset; the token cost of agent queries, which in this estate goes to the kernel's ledger. Usage data also feeds discovery ranking.

## 8. What is realistic at SMB scale

**Realistic** ([Tigani](https://motherduck.com/blog/big-data-is-dead/)):
- Data sizes are gigabytes to low terabytes. Most BigQuery customers had under 1 TB; for customers spending over $1,000 a year, 90% of queries processed under 100 MB; data a week old is about 20× less likely to be queried.
- A single-node engine (DuckDB) on one server.
- A transactional catalog in the existing PostgreSQL. DuckLake says its only scale limit is the catalog's performance and cites terabytes and millions of snapshots ([FAQ](https://ducklake.select/faq)).
- Parquet on local disk or an S3-compatible store.
- Scheduled batch and micro-batch loads.
- A catalog, quality checks, lineage and policies built as part of the application rather than as separate products.

**What DuckLake lacks:** it does not support "constraints, keys, or indexes". Deletion vectors are experimental. Roles and permissions are deferred to v2.0, "most likely not coming anytime soon" ([1.0 post](https://ducklake.select/2026/04/13/ducklake-10/)). **The application must supply access control, quality enforcement and audit itself.** Iceberg with a REST catalog is the heavier, more interoperable alternative.

**Can be deferred safely:**
- streaming and log-based CDC;
- multi-node compute;
- partitioning;
- column-level lineage for non-SQL code;
- ML anomaly detection;
- external sharing protocols;
- DCAT export;
- notebooks;
- branching.

**Cannot be deferred** (expensive to retrofit):
- the catalog with ownership;
- the single enforcement point;
- classification;
- audit;
- the erasure path;
- idempotent loads;
- snapshot and compaction maintenance;
- backups that are consistent with the catalog.

---

## Requirements list for an SMB-scale self-built lake

### Discoverable
- **R1 MUST:** every dataset (table, file volume, view) has a catalog entry with a stable ID, title, description, owner, steward, domain or department, layer and status. No data lands without an entry (FAIR F1–F4).
- **R2 MUST:** technical metadata is harvested automatically: columns and types, row count, size, snapshots, last load, source, partition/sort.
- **R3 MUST:** business metadata: business names and descriptions per column, glossary terms linked to columns, and purpose/usage/limitations text (ODCS description fields).
- **R4 MUST:** full-text search over names, columns, descriptions, tags, glossary and owners, trimmed to what the searcher may see.
- **R5 MUST:** a preview of sample rows and a column profile (null %, distinct, min/max, top values), with masking policies applied.
- **R6 MUST:** catalog metadata and search are reachable by agents through the same API or MCP tools as by people.
- **R7 SHOULD:** usage signals (queries, users, last access), popularity ranking, and an "unused" flag.
- **R8 SHOULD:** classification tags from a governed vocabulary (PII, confidential, financial), suggested by a detector and confirmed by a person.
- **R9 SHOULD:** a certification level (draft / promoted / certified) set only by authorised people; certified datasets rank first.
- **R10 LATER:** export the catalog as DCAT 3 / schema.org Dataset.

### Trustworthy
- **R11 MUST:** declared quality rules per dataset over the six dimensions, run on every load, with results stored and shown on the dataset page.
- **R12 MUST:** failing rows or batches are quarantined with a reason and never promoted to silver or gold.
- **R13 MUST:** a freshness expectation (warn/error after) per dataset, with alerts to the owner.
- **R14 MUST:** volume and schema-change monitoring (the five pillars), with alerts.
- **R15 MUST:** table-level lineage recorded automatically for every run (inputs, outputs, run ID, code version, status), OpenLineage-shaped.
- **R16 MUST:** every write is a snapshot; as-of queries; roll back a bad load.
- **R17 MUST:** provenance on every bronze record (source, object or file, checksum, batch ID, ingested_at).
- **R18 MUST:** a history of every write (who or what, when, operation, rows).
- **R19 SHOULD:** data contracts (an ODCS subset: schema, quality, SLA, classification, owner, version) for gold datasets; a breaking change means a new major version.
- **R20 SHOULD:** a failed check opens an incident listing affected downstream datasets (via lineage), with its status badged on those datasets.
- **R21 SHOULD:** column-level lineage for SQL transforms.
- **R22 LATER:** statistical or ML anomaly detection on distributions.

### Secure
- **R23 MUST:** sign-on through the kernel. People, service accounts and agents are distinct principals; there are no local passwords.
- **R24 MUST:** deny by default. Grants on datasets go to people, groups, departments and agents, with least privilege.
- **R25 MUST:** one enforcement point. Nobody, person or agent, reads data files directly. All access goes through the query service. Credentials are never handed out, or only short-lived and scoped.
- **R26 MUST:** row filters and column masks are enforced in the engine. The query role never owns the tables (PostgreSQL RLS bypass).
- **R27 MUST:** TLS in transit; encryption at rest for data files, with keys held outside the readable file path.
- **R28 MUST:** an audit record of every read, write and export (principal, agent/run, dataset, columns, filter, rows, time) that is append-only, with bounded volume and its own retention.
- **R29 MUST:** a retention policy per dataset and layer, enforced by a scheduled job.
- **R30 MUST:** an erasure pipeline: locate a subject across layers through classification and lineage; delete; purge deletion vectors and rewrite; expire snapshots, vacuum and clean orphans; propagate to derived tables and backups; log proof.
- **R31 MUST:** sensitive fields are masked or tokenised at landing, before bronze, where possible.
- **R32 MUST:** agents run in the requester's context or under a narrow grant of their own, read-only by default. Exports and writes need approval; agents are rate-limited; authorisation happens in the engine, never in the prompt.
- **R33 MUST:** data and backups stay in the declared jurisdiction, and the offsite location is declared.
- **R34 SHOULD:** tag-based (ABAC) policies, so a policy on a tag covers new columns automatically; tags propagate along lineage to derived columns.
- **R35 SHOULD:** automatic PII detection at ingestion (Presidio-style) proposes tags.
- **R36 SHOULD:** metadata of restricted datasets is hidden or shown only as a stub.
- **R37 SHOULD:** retrieval indexes built from the lake (RAG) carry the source ACLs and are filtered at query time.

### Usable
- **R38 MUST:** a web SQL editor with saved and shared queries, result limits and query history.
- **R39 MUST:** an API and MCP tools (search, describe, preview, query, metric) for agents and sibling applications.
- **R40 MUST:** governed exports (CSV, XLSX, Parquet) that respect masks and are logged.
- **R41 MUST:** self-service upload: a person drops a CSV, XLSX, JSON or Parquet file, confirms the inferred schema, and gets a cataloged table.
- **R42 SHOULD:** a semantic layer of named metrics and dimensions defined once. Agents use metrics first and fall back to SQL.
- **R43 SHOULD:** BI connectivity over a standard protocol (PostgreSQL wire, ODBC/JDBC or Flight SQL).
- **R44 SHOULD:** gold datasets published as data products (contract, owner, SLA, version) that consumers can subscribe to.
- **R45 LATER:** notebooks (SQL/Python).
- **R46 LATER:** external read-only sharing with expiring tokens.

### Ingestion
- **R47 MUST:** batch files (CSV, XLSX, JSON, Parquet) are kept byte-for-byte in landing or bronze with a checksum, and inference is confirmed by a person.
- **R48 MUST:** extraction from PostgreSQL sources: full load plus incremental by watermark column, with periodic reconciliation.
- **R49 MUST:** idempotent loads. Re-running a batch or partition gives the same result (batch IDs, MERGE/upsert, partition overwrite).
- **R50 MUST:** a schema-evolution mode per dataset (add / rescue / fail). Nothing is silently dropped or retyped, and unknown fields go to a rescued column.
- **R51 MUST:** deduplication rules per dataset (key plus latest-wins or first-wins).
- **R52 SHOULD:** REST API connectors (pagination, rate limits, secrets by reference).
- **R53 SHOULD:** event time versus load time and a reprocessing window for late data.
- **R54 LATER:** log-based CDC with an initial snapshot.
- **R55 LATER:** streaming ingestion. Micro-batches suffice for SMB.

### Storage and compute
- **R56 MUST:** Parquet in an open table format with ACID snapshots (Iceberg, Delta or DuckLake). Never loose files.
- **R57 MUST:** the metadata catalog in a transactional SQL database (PostgreSQL), separate from the data files.
- **R58 MUST:** small-file control through data inlining and scheduled compaction toward about 128–512 MB files.
- **R59 MUST:** scheduled snapshot expiry, metadata cleanup and orphan-file removal, coordinated with retention, erasure and backups.
- **R60 MUST:** storage size and growth per dataset and layer is visible, with quotas.
- **R61 SHOULD:** unstructured files (PDFs, images) held in governed "volumes", with a metadata table (path, hash, type, owner, classification) under the same permissions.
- **R62 SHOULD:** sort and cluster with file statistics; partition only tables over about 1 TB.
- **R63 SHOULD:** logical separation of storage and compute (an object-store or filesystem abstraction) so compute can move later.
- **R64 LATER:** multi-node or distributed compute, and branching.

### Operations
- **R65 MUST:** an orchestrator with dependencies, schedules, triggers, retries and run history.
- **R66 MUST:** backfill a date or partition range idempotently.
- **R67 MUST:** monitoring and alerting to owners for failures, freshness, disk and latency.
- **R68 MUST:** backups with catalog point-in-time recovery plus file copies taken after each batch and before compaction; offsite, encrypted, with restores tested. A snapshot is not a backup.
- **R69 MUST:** every state change is logged to the activity trail.
- **R70 SHOULD:** usage and cost accounting per dataset, person and agent (queries, bytes scanned, storage, AI tokens).
- **R71 SHOULD:** pipelines are tested against a copy or dev catalog before production.

## How lakes fail

1. **Ingest first, metadata never.** No catalog, no descriptions, and the lake becomes a swamp (Gartner 2014; Hai et al.).
2. **No owner.** A central team without domain knowledge becomes a bottleneck, and nobody answers for a dataset (Dehghani).
3. **Unknown quality, so nobody trusts it.** People build shadow copies and spreadsheets (Stonebraker's ~10% incorrect data; 86% of analysts on stale data).
4. **Two tiers drift.** Lake and warehouse copies diverge, and every ETL hop is a failure point (Lakehouse paper).
5. **"Just a bunch of files".** Partial writes are visible, there are no transactions, small files pile up, and nobody compacts or expires (Delta Lake paper; Iceberg maintenance).
6. **Security is bypassable.** Files are readable outside the catalog, per-table grants don't scale, PII spreads into derived tables, and reads are not audited.
7. **Data cannot be deleted.** Immutable raw data, snapshots, upstream copies and backups keep data that GDPR says must go.
8. **Unbounded retention.** Storage and log growth go unchecked, including audit logs (this server's pgaudit disk-fill).
9. **Snapshots mistaken for backups,** or a catalog restored out of step with the files.
10. **Hyperscale tooling at SMB scale.** Clusters, streaming and partitioning tables under 1 TB add operating cost for no benefit.
11. **One metric, many definitions.** Different answers to the same question; text-to-SQL returns plausible wrong numbers.
12. **Agents with excessive agency.** Agents read or export beyond the requester's rights, and embeddings leak what the tables protect.

## Sources

**Papers and standards**
- [Armbrust et al., Lakehouse, CIDR 2021](https://www.cidrdb.org/cidr2021/papers/cidr2021_paper17.pdf)
- [Armbrust et al., Delta Lake, VLDB 2020](https://vldb.org/pvldb/vol13/p3411-armbrust.pdf)
- [Hai et al., Data Lakes: A Survey of Functions and Systems](https://arxiv.org/pdf/2106.09592)
- [GO FAIR principles](https://www.gofair.foundation/fair-principles)
- [W3C DCAT 3](https://www.w3.org/TR/vocab-dcat-3/)
- [Google Dataset structured data](https://developers.google.com/search/docs/appearance/structured-data/dataset)
- [NIST SP 800-162](https://csrc.nist.gov/pubs/sp/800/162/upd2/final)
- GDPR [Art. 5](https://gdpr-info.eu/art-5-gdpr/), [Art. 17](https://gdpr-info.eu/art-17-gdpr/), [Art. 44](https://gdpr-info.eu/art-44-gdpr/)
- [EDPB blockchain guidelines (Mondaq)](https://www.mondaq.com/fin-tech/1617044/blockchain-and-gdpr-edpb-guidelines-022025-adopted)
- [DAMA dimensions (UK Gov)](https://www.gov.uk/government/news/meet-the-data-quality-dimensions)
- [ISO/IEC 25012 (arc42)](https://quality.arc42.org/standards/iso-iec-25012)

**Data contracts and lineage**
- ODCS: [overview](https://bitol-io.github.io/open-data-contract-standard/latest/), [fundamentals](https://bitol-io.github.io/open-data-contract-standard/latest/fundamentals/), [schema](https://bitol-io.github.io/open-data-contract-standard/latest/schema/), [quality](https://bitol-io.github.io/open-data-contract-standard/latest/data-quality/), [SLA](https://bitol-io.github.io/open-data-contract-standard/latest/service-level-agreement/), [v3.1.0 announcement](https://bitol.io/bitol-announces-odcs-v3-1-0-stronger-smarter-and-stricter/)
- OpenLineage: [object model](https://openlineage.io/docs/spec/object-model), [column lineage](https://openlineage.io/docs/spec/facets/dataset-facets/column_lineage_facet/), [OpenAPI](https://openlineage.io/apidocs/openapi)

**Table formats and storage**
- Iceberg: [maintenance](https://iceberg.apache.org/docs/latest/maintenance/), [evolution](https://iceberg.apache.org/docs/latest/evolution/), [spec](https://iceberg.apache.org/spec/), [v3 (Dremio)](https://dremio.com/blog/apache-iceberg-v3)
- [Delta utilities](https://docs.delta.io/latest/delta-utility.html)
- DuckLake: [1.0 release](https://ducklake.select/2026/04/13/ducklake-10/), [FAQ](https://ducklake.select/faq), [encryption](https://ducklake.select/docs/stable/duckdb/advanced_features/encryption), [backups](https://ducklake.select/docs/stable/duckdb/guides/backups_and_recovery)
- Parquet: [file format](https://parquet.apache.org/docs/file-format/), [encryption spec](https://apache.googlesource.com/parquet-format/+/HEAD/Encryption.md)
- [Arrow Parquet encryption](https://arrow.apache.org/docs/dev/python/parquet/parquet_encryption.html)
- [Small files (Dremio)](https://www.dremio.com/blog/apache-iceberg-small-files-problem-causes-fixes-and-prevention/)
- [Iceberg REST catalog (Dremio)](https://www.dremio.com/blog/apache-iceberg-rest-catalog-what-it-is-and-how-to-use-it/)
- [S3 Object Lock](https://docs.aws.amazon.com/AmazonS3/latest/dev/object-lock-overview.html)

**Databricks, AWS and Microsoft docs**
- Databricks: [medallion](https://docs.databricks.com/aws/en/lakehouse/medallion), [deployment guide](https://docs.databricks.com/aws/en/lakehouse-architecture/deployment-guide/delta-lake), [partitions](https://docs.databricks.com/en/tables/partitions), [ABAC](https://docs.databricks.com/en/data-governance/unity-catalog/abac/), [ABAC GA post](https://www.databricks.com/blog/abac-row-filtering-and-column-masking-policies-governed-tags-and-data-classification-are-now), [GDPR](https://docs.databricks.com/aws/security/privacy/gdpr-delta), [Auto Loader schema](https://docs.databricks.com/aws/en/ingestion/cloud-object-storage/auto-loader/schema), [watermarks](https://docs.databricks.com/structured-streaming/watermarks.html), [UC volumes](https://learn.microsoft.com/en-us/azure/databricks/volumes/), [open sharing](https://docs.databricks.com/aws/en/delta-sharing/share-data-open)
- AWS: [data layers](https://docs.aws.amazon.com/prescriptive-guidance/latest/defining-bucket-names-data-lakes/data-layer-definitions.html), [Lake Formation TBAC](https://docs.aws.amazon.com/en_us/lake-formation/latest/dg/tag-based-access-control.html)
- [Cloudera Atlas/Ranger](https://docs.cloudera.com/runtime/7.3.1/cdp-governance-controlling-access-tags/topics/atlas-controlling-data-access-with-tags.html)
- [Fabric endorsement](https://learn.microsoft.com/en-us/fabric/governance/endorsement-overview)

**PostgreSQL**
- [Row security](https://www.postgresql.org/docs/current/ddl-rowsecurity.html)
- [PITR](https://www.postgresql.org/docs/current/continuous-archiving.html)
- [pgAudit](https://access.crunchydata.com/documentation/pgaudit/1.1.2)

**Catalogs and quality tools**
- [DataHub](https://archive.docs.datahub.com/docs/0.12.0/features)
- OpenMetadata: [asset tabs](https://docs.open-metadata.org/v1.13.x/how-to-guides/guide-for-data-users/data-asset-tabs.md), [tiering](https://docs.open-metadata.org/v2.0.x/how-to-guides/data-insights/tiering.md)
- [Collate certification](https://docs.getcollate.io/how-to-guides/data-governance/workflows/default-workflows/table-certification)
- [Amundsen slides](https://www.datacouncil.ai/hubfs/DataEngConf/Data%20Council/Slides%20SF%2019/Amundsen%20-%20A%20Data%20Discovery%20Platform%20from%20Lyft.pdf)
- [Monte Carlo five pillars](https://montecarlo.ai/blog-introducing-the-5-pillars-of-data-observability)
- [dbt freshness](https://docs.getdbt.com/reference/resource-properties/freshness)
- [dbt semantic layer vs text-to-SQL](https://docs.getdbt.com/blog/semantic-layer-vs-text-to-sql-2026)
- [GX checkpoint](https://docs.greatexpectations.io/docs/terms/checkpoint/)
- [Presidio](https://microsoft.github.io/presidio/faq/)

**Ingestion and orchestration**
- [DuckDB CSV sniffer](https://duckdb.org/docs/current/data/csv/auto_detection.html)
- [Debezium FAQ](https://debezium.io/documentation/faq/)
- [Airflow best practices](https://airflow.apache.org/docs/apache-airflow/stable/best-practices.html)
- [Dagster backfills](https://docs.dagster.io/overview/partitions-backfill/backfill)
- [Arrow Flight SQL JDBC](https://arrow.apache.org/blog/2022/11/01/arrow-flight-sql-jdbc/)

**AI and agents**
- [OWASP LLM06](https://owasp.github.io/www-project-top-10-for-large-language-model-applications/2_0_vulns/LLM06_ExcessiveAgency.html)
- [OWASP LLM08](https://genai.owasp.org/llmrisk/llm082025-vector-and-embedding-weaknesses/)
- [MCP security](https://modelcontextprotocol.io/specification/2025-11-25/basic/security_best_practices)
- [Gartner AI-ready data](https://www.gartner.com/en/newsroom/press-releases/2025-02-26-lack-of-ai-ready-data-puts-ai-projects-at-risk)
- [Permission-aware RAG](https://tianpan.co/blog/2026-05-04-permission-aware-retrieval-enterprise-rag-access-control)
- [Crypto-shredding (Conduktor)](https://www.conduktor.io/glossary/crypto-shredding-for-kafka)

**Lake failure and SMB scale**
- [Gartner 2014](https://www.gartner.com/en/newsroom/press-releases/2014-07-28-gartner-says-beware-of-the-data-lake-fallacy)
- [Information Age](https://www.information-age.com/beware-data-lake-fallacy-warns-gartner-30035/)
- [Stonebraker](https://cacmb4.acm.org/blogs/blog-cacm/181547-why-the-data-lake-is-really-a-data-swamp/fulltext)
- Dehghani: [2019](https://martinfowler.com/articles/data-monolith-to-mesh.html), [2020](https://martinfowler.com/articles/data-mesh-principles.html)
- [Tigani, Big Data is Dead](https://motherduck.com/blog/big-data-is-dead/)
- [3-2-1 backup](https://ninjaone.com/blog/3-2-1-backup-rule-explained)