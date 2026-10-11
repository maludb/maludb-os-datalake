-- Phase 0 proof of the schema (docs/datalake-design.md §10). Everything happens inside one transaction that is ROLLED BACK: fixtures written
-- as datalake_rw (the web application) and datalake_lake (the writer — the engine's fields), reads as the read roles and the query service's
-- role, acting members set through app.member_id exactly as PHP and the MCP servers set it. Fixtures are synthetic (example.invalid).
-- Run as postgres on a SCRATCH database — never the installed one — after loading every migration in order (tests/phase0/schema.sh):
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d dl_proof -f db/proof/phase0_proof.sql; echo "exit $?"
-- Prints one line per check (ok / FAIL) and the count; exits non-zero when any check failed.
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
        INSERT INTO proof (ok, label) VALUES (false, l || ' (was NOT refused)');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE '%' || p_like || '%', l || CASE WHEN SQLERRM ILIKE '%' || p_like || '%' THEN '' ELSE ' (said: ' || SQLERRM || ')' END);
    END;
END$$;
CREATE OR REPLACE FUNCTION pg_temp.as_member(p bigint) RETURNS void LANGUAGE sql AS $$ SELECT set_config('app.member_id', COALESCE(p::text, ''), true) $$;
CREATE TEMP TABLE ids (k text PRIMARY KEY, i bigint);
GRANT ALL ON ids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.i(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT i FROM ids WHERE ids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.keep(k text, v bigint) RETURNS void LANGUAGE sql AS $$ INSERT INTO ids (k, i) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET i = EXCLUDED.i $$;
CREATE OR REPLACE FUNCTION pg_temp.lvl(d text, m bigint, b bigint DEFAULT NULL) RETURNS text LANGUAGE sql STABLE SECURITY DEFINER AS $$
    SELECT level FROM dl_access_of((SELECT id FROM datasets WHERE key = d), m, b) $$;
CREATE OR REPLACE FUNCTION pg_temp.flt(d text, m bigint, b bigint DEFAULT NULL) RETURNS text LANGUAGE sql STABLE SECURITY DEFINER AS $$
    SELECT row_filter FROM dl_access_of((SELECT id FROM datasets WHERE key = d), m, b) $$;
CREATE OR REPLACE FUNCTION pg_temp.ds(k text) RETURNS bigint LANGUAGE sql STABLE SECURITY DEFINER AS $$ SELECT id FROM datasets WHERE key = k $$;
GRANT EXECUTE ON FUNCTION pg_temp.check(boolean, text), pg_temp.refused(text, text, text), pg_temp.as_member(bigint), pg_temp.i(text), pg_temp.keep(text, bigint),
    pg_temp.lvl(text, bigint, bigint), pg_temp.flt(text, bigint, bigint), pg_temp.ds(text) TO PUBLIC;

-- ================================================================ fixtures: every actor of §2
SET ROLE datalake_rw;
SELECT pg_temp.as_member(1);
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',   'owner@example.invalid', 'super_admin', false, 'active', 'admin', '{admin}'),     -- a super-admin: Lake admin
 (2,  'human', 'SMOKE Ada',     'ada@example.invalid',   'user',        false, 'active', 'admin', '{admin}'),     -- a second Lake admin (approves erasures)
 (26, 'human', 'SMOKE Priya',   'priya@example.invalid', 'user',        false, 'active', 'write', '{user}'),      -- a Member, Sales
 (27, 'human', 'SMOKE Marco',   'marco@example.invalid', 'user',        false, 'active', 'write', '{analyst}'),   -- an Analyst, Finance
 (28, 'human', 'SMOKE Eve',     'eve@example.invalid',   'user',        false, 'active', 'write', '{engineer}'),  -- a Data engineer, IT
 (29, 'human', 'SMOKE Xena',    'xena@example.invalid',  'user',        true,  'active', 'write', '{user}'),      -- an EXTERNAL Member (the accountant)
 (30, 'human', 'SMOKE Sam',     'sam@example.invalid',   'user',        false, 'active', NULL,    '{}'),          -- an insider with no grant here
 (40, 'agent', 'SMOKE Data Lake Expert', NULL,           'user',        false, 'active', 'write', '{user}'),      -- the expert
 (41, 'agent', 'SMOKE Data Steward',     NULL,           'user',        false, 'active', 'write', '{user}'),      -- the Steward
 (42, 'agent', 'SMOKE Watcher',          NULL,           'user',        false, 'active', NULL,    '{}');          -- an agent not admitted
INSERT INTO departments (id, name, is_system, system_key, manager_member_id) VALUES (3, 'Finance', false, NULL, NULL), (5, 'IT', true, 'it', 28), (7, 'Sales', false, NULL, NULL);
INSERT INTO department_members (member_id, department_id, is_admin, is_primary) VALUES (26, 7, false, true), (27, 3, false, true), (28, 5, true, true), (2, 5, false, true), (29, 3, false, true);
INSERT INTO groups (key, name, kind) VALUES ('finance-analysts', 'Finance analysts', 'custom');
INSERT INTO group_members (group_id, member_id, role) SELECT id, 27, 'owner' FROM groups WHERE key = 'finance-analysts';
INSERT INTO groups (key, name, kind, department_id) VALUES ('sales', 'Sales', 'department', 7);
INSERT INTO tags (slug, label, kind) VALUES ('finance', 'Finance', 'managed');

SELECT pg_temp.as_member(28);
INSERT INTO datasets (key, zone, name, title, owner_member_id, steward_member_id, department_id, status) VALUES
 ('curated.sales_orders', 'curated', 'sales_orders', 'Sales orders', 28, 27, 7, 'active'),
 ('curated.products', 'curated', 'products', 'Products', 28, NULL, NULL, 'active'),
 ('curated.payroll', 'curated', 'payroll', 'Payroll', 2, NULL, 3, 'active'),
 ('product.monthly_revenue', 'product', 'monthly_revenue', 'Monthly revenue', 28, 27, 7, 'active'),
 ('sandbox.m26_my_upload', 'sandbox', 'm26_my_upload', 'My upload', 26, NULL, NULL, 'active'),
 ('raw.crm_deals', 'raw', 'crm_deals', 'CRM deals (raw)', 28, NULL, NULL, 'active');
INSERT INTO record_tags (record_type, record_id, tag_id, added_by) SELECT 'dataset', pg_temp.ds('curated.payroll'), id, 28 FROM tags WHERE slug = 'finance';

-- the writer records the columns (the engine's fields are the writer's)
RESET ROLE; SET ROLE datalake_lake;
INSERT INTO dataset_columns (dataset_id, name, ordinal, physical_type, logical_type, is_key, personal_kind, classification) VALUES
 (pg_temp.ds('curated.sales_orders'), 'order_id', 0, 'VARCHAR', 'string', true, NULL, 'internal'),
 (pg_temp.ds('curated.sales_orders'), 'amount', 1, 'DECIMAL(12,2)', 'number', false, NULL, 'internal'),
 (pg_temp.ds('curated.sales_orders'), 'region', 2, 'VARCHAR', 'string', false, NULL, 'internal'),
 (pg_temp.ds('curated.sales_orders'), 'customer_email', 3, 'VARCHAR', 'string', false, 'email', 'internal'),
 (pg_temp.ds('curated.sales_orders'), 'department_id', 4, 'BIGINT', 'integer', false, NULL, 'internal'),
 (pg_temp.ds('curated.products'), 'product_id', 0, 'VARCHAR', 'string', true, NULL, 'internal'),
 (pg_temp.ds('curated.products'), 'name', 1, 'VARCHAR', 'string', false, NULL, 'internal'),
 (pg_temp.ds('curated.payroll'), 'employee_name', 0, 'VARCHAR', 'string', false, 'name', 'internal'),
 (pg_temp.ds('curated.payroll'), 'salary', 1, 'DECIMAL(12,2)', 'number', false, NULL, 'confidential'),
 (pg_temp.ds('product.monthly_revenue'), 'month', 0, 'DATE', 'date', true, NULL, 'internal'),
 (pg_temp.ds('product.monthly_revenue'), 'revenue', 1, 'DECIMAL(14,2)', 'number', false, NULL, 'internal'),
 (pg_temp.ds('sandbox.m26_my_upload'), 'x', 0, 'VARCHAR', 'string', false, NULL, 'internal'),
 (pg_temp.ds('raw.crm_deals'), 'deal_id', 0, 'VARCHAR', 'string', false, NULL, 'internal'),
 (pg_temp.ds('raw.crm_deals'), 'buyer_email', 1, 'VARCHAR', 'string', false, 'email', 'internal');
INSERT INTO dataset_columns (dataset_id, name, ordinal, physical_type, is_system) VALUES (pg_temp.ds('raw.crm_deals'), '_dl_rescued', 9, 'JSON', true);
UPDATE datasets SET row_count = 1200, byte_size = 64000, data_updated_at = now() - interval '2 hours', last_snapshot_id = 7 WHERE key = 'curated.sales_orders';
RESET ROLE;

-- ================================================================ roles and rights (the kit, as the design says)
SELECT pg_temp.check((SELECT string_agg(role_key, ',' ORDER BY sort_order) FROM mcp_app_roles) = 'user,analyst,engineer,admin', 'four roles, in order (os.app-roles/1)');
SELECT pg_temp.check((SELECT count(*) FROM dl_roles WHERE is_admin) = 1, 'exactly one admin role');
SELECT pg_temp.check(dl_member_has_right(26, 'catalog.browse') AND NOT dl_member_has_right(26, 'sql.editor'), 'a Member browses the catalog and has no SQL editor');
SELECT pg_temp.check(dl_member_has_right(27, 'sql.editor') AND NOT dl_member_has_right(27, 'sources.manage'), 'an Analyst has the editor and manages no source');
SELECT pg_temp.check(dl_member_has_right(28, 'checks.manage') AND NOT dl_member_has_right(28, 'grants.manage'), 'a Data engineer manages checks and grants nothing beyond a dataset''s owner');
SELECT pg_temp.check(dl_member_has_right(1, 'erasure.manage') AND dl_member_has_right(2, 'lake.admin_read'), 'a Lake admin holds erasure and the break-glass');
SELECT pg_temp.check(dl_member_roles(30) = '{}' AND dl_member_roles(42) = '{}', 'an insider with no grant and an unadmitted agent hold no role here');

-- ================================================================ the catalog's rules (§6.2)
SELECT pg_temp.check((SELECT classification FROM datasets WHERE key = 'curated.sales_orders') = 'confidential'
                     AND (SELECT has_personal_data FROM datasets WHERE key = 'curated.sales_orders'), 'a dataset with a personal column is confidential and flagged personal — derived, never typed');
SELECT pg_temp.check((SELECT classification FROM dataset_columns WHERE name = 'customer_email') = 'confidential', 'a personal column is at least confidential');
SELECT pg_temp.check((SELECT classification FROM datasets WHERE key = 'curated.products') = 'internal' AND NOT (SELECT has_personal_data FROM datasets WHERE key = 'curated.products'),
                     'a dataset without personal or confidential columns stays internal');
SELECT pg_temp.check((SELECT classification FROM datasets WHERE key = 'raw.crm_deals') = 'confidential', 'a system column does not count, the personal one does');
SET ROLE datalake_rw; SELECT pg_temp.as_member(28);
SELECT pg_temp.refused($$UPDATE datasets SET row_count = 5 WHERE key = 'curated.products'$$, 'never typed', 'the web application cannot type a row count');
SELECT pg_temp.refused($$UPDATE datasets SET key = 'curated.products2', name = 'products2' WHERE key = 'curated.products'$$, 'never changes', 'a dataset''s key never changes');
SELECT pg_temp.refused($$INSERT INTO datasets (key, zone, name, title) VALUES ('stage.x', 'raw', 'x', 'X')$$, 'check', 'a key outside the four zones is refused');
SELECT pg_temp.refused($$INSERT INTO datasets (key, zone, name, title, owner_member_id) VALUES ('sandbox.m27_x', 'sandbox', 'm27_x', 'X', 26)$$, 'check', 'a sandbox dataset is named for its owner');
INSERT INTO datasets (key, zone, name, title, row_count, owner_member_id) VALUES ('curated.typed_by_hand', 'curated', 'typed_by_hand', 'Typed', 999, 28);
SELECT pg_temp.check((SELECT row_count FROM datasets WHERE key = 'curated.typed_by_hand') IS NULL, 'a row count typed at insert is dropped (R2)');
UPDATE datasets SET status = 'retired' WHERE key = 'curated.typed_by_hand';
SELECT pg_temp.refused($$INSERT INTO datasets (key, zone, name, title) VALUES ('curated.typed_by_hand', 'curated', 'typed_by_hand', 'Again')$$, 'duplicate', 'a retired key is never reused');
SELECT pg_temp.check((SELECT retired_at FROM datasets WHERE key = 'curated.typed_by_hand') IS NOT NULL, 'retiring dates it');
SELECT pg_temp.keep('meta_before', (SELECT extract(epoch FROM metadata_updated_at)::bigint FROM datasets WHERE key = 'curated.products'));
RESET ROLE;
SELECT pg_temp.check((SELECT row_count FROM datasets WHERE key = 'curated.sales_orders') = 1200, 'the writer records the row count');
SET ROLE datalake_rw;
UPDATE datasets SET title = 'Products (catalog)' WHERE key = 'curated.products';
RESET ROLE;
SELECT pg_temp.check((SELECT metadata_updated_at FROM datasets WHERE key = 'curated.products') >= (SELECT data_updated_at FROM datasets WHERE key = 'curated.sales_orders'),
                     '"metadata updated" moves with a person''s change; "data updated" is the engine''s');

-- ================================================================ grants
SET ROLE datalake_rw; SELECT pg_temp.as_member(2);
INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, row_filter, reason, granted_by)
VALUES ('dataset', pg_temp.ds('curated.sales_orders'), 'department', 7, 'read', 'department_id = ANY({departments})', 'Sales reads its own orders', 2);
INSERT INTO grants (target_kind, dataset_id, principal_kind, level, reason, granted_by) VALUES ('dataset', pg_temp.ds('product.monthly_revenue'), 'everyone', 'read', 'a product for all', 2);
INSERT INTO grants (target_kind, tag_id, principal_kind, principal_id, level, reason, granted_by)
SELECT 'tag', t.id, 'group', g.id, 'read_unmasked', 'finance analysts read finance data', 2 FROM tags t, groups g WHERE t.slug = 'finance' AND g.key = 'finance-analysts';
INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, reason, granted_by) VALUES ('dataset', pg_temp.ds('curated.sales_orders'), 'agent', 40, 'read', 'the expert reads orders', 2);
INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_ref, level, reason, granted_by) VALUES ('dataset', pg_temp.ds('product.monthly_revenue'), 'application', 'gl', 'read', 'the ledger reads revenue', 2);
INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, expires_at, reason, granted_by)
VALUES ('dataset', pg_temp.ds('curated.products'), 'member', 29, 'read', now() - interval '1 day', 'expired yesterday', 2);
SELECT pg_temp.refused($$INSERT INTO grants (target_kind, zone, principal_kind, principal_id, level) VALUES ('zone', 'sandbox', 'member', 27, 'read')$$, 'check', 'a zone grant never names the sandbox');
SELECT pg_temp.refused($$INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_ref, level) VALUES ('dataset', 1, 'application', 'gl', 'write')$$, 'check', 'a sibling application reads, never writes');
SELECT pg_temp.refused($$INSERT INTO grants (target_kind, dataset_id, principal_kind, level) VALUES ('dataset', 1, 'everyone', 'read_unmasked')$$, 'check', 'everyone never reads unmasked');
RESET ROLE;

-- ================================================================ THE WALLS (§3) — ten callers
SELECT pg_temp.check(pg_temp.lvl('curated.sales_orders', 26) = 'read' AND pg_temp.flt('curated.sales_orders', 26) LIKE '%department_id = ANY(ARRAY[7]::BIGINT[])%',
                     'Priya (Sales) reads orders through her department''s grant, filtered to her department — the template bound to literals');
SELECT pg_temp.check(pg_temp.lvl('curated.products', 26) = 'discover', 'an insider discovers an internal dataset with no grant (the catalog is open by classification)');
SELECT pg_temp.check(pg_temp.lvl('curated.payroll', 26) = 'none', 'a confidential dataset without a grant: nothing');
SELECT pg_temp.check(pg_temp.lvl('curated.payroll', 27) = 'read_unmasked', 'Marco reads payroll unmasked through the tag grant to his group');
SELECT pg_temp.check(pg_temp.lvl('curated.sales_orders', 27) = 'read' AND pg_temp.flt('curated.sales_orders', 27) IS NULL, 'the steward reads, unfiltered');
SELECT pg_temp.check(pg_temp.lvl('curated.sales_orders', 28) = 'own', 'the owner owns');
SELECT pg_temp.check(pg_temp.lvl('curated.products', 29) = 'none' AND pg_temp.lvl('product.monthly_revenue', 29) = 'none',
                     'an external discovers nothing unnamed — the expired grant gives nothing, and "everyone" is not for an external');
SELECT pg_temp.check(pg_temp.lvl('product.monthly_revenue', 30) = 'none' AND pg_temp.lvl('product.monthly_revenue', 42) = 'none', 'no role here, no access — even to an everyone grant');
SELECT pg_temp.check(pg_temp.lvl('product.monthly_revenue', 26) = 'read', 'an everyone grant reaches an insider');
SELECT pg_temp.check(pg_temp.lvl('sandbox.m26_my_upload', 26) = 'own' AND pg_temp.lvl('sandbox.m26_my_upload', 27) = 'none' AND pg_temp.lvl('sandbox.m26_my_upload', 28) = 'none',
                     'a sandbox dataset is its owner''s alone (rule 6)');
SELECT pg_temp.check(pg_temp.lvl('curated.payroll', 1) = 'discover', 'the admin discovers every entry and reads no rows (rule 7)');
SELECT pg_temp.check(pg_temp.lvl('curated.sales_orders', 40) = 'read' AND pg_temp.flt('curated.sales_orders', 40) IS NULL, 'the expert alone reads orders through its own grant');
SELECT pg_temp.check(pg_temp.lvl('curated.sales_orders', 40, 26) = 'read' AND pg_temp.flt('curated.sales_orders', 40, 26) LIKE '%ARRAY[7]%',
                     'the expert acting for Priya: the intersection carries her row filter (rule 8)');
SELECT pg_temp.check(pg_temp.lvl('curated.sales_orders', 40, 29) = 'none', 'the expert acting for the external reads nothing she may not');
SELECT pg_temp.check(pg_temp.lvl('curated.payroll', 40, 27) = 'none', 'the expert acting for Marco reads nothing the expert may not — the intersection both ways');
SELECT pg_temp.check((dl_masks_for(pg_temp.ds('curated.sales_orders'), 'read') ->> 'customer_email') = 'partial_email'
                     AND dl_masks_for(pg_temp.ds('curated.sales_orders'), 'read_unmasked') = '{}'::jsonb, 'masks follow the level: read masks the email partially, read_unmasked masks nothing');
SELECT pg_temp.check((dl_masks_for(pg_temp.ds('curated.payroll'), 'read') ->> 'salary') = 'full' AND (dl_masks_for(pg_temp.ds('curated.payroll'), 'read') ->> 'employee_name') = 'partial',
                     'a confidential column masked full, a name partially');
SELECT pg_temp.check((dl_masks_for(pg_temp.ds('raw.crm_deals'), 'read') ->> '_dl_rescued') = 'null', 'rescued fields are null below read_unmasked (nothing has classified them)');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(dl_can_see_stub(pg_temp.ds('curated.payroll')) AND NOT dl_can_discover(pg_temp.ds('curated.payroll')), 'Priya sees payroll as a stub, not its entry (R36)');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check(NOT dl_can_see_stub(pg_temp.ds('curated.payroll')), 'an external sees no stub');
SELECT pg_temp.as_member(1);
SELECT pg_temp.refused($$SELECT dl_admin_read_open(1, 'short')$$, 'check', 'the break-glass needs a reason');
SELECT dl_admin_read_open(pg_temp.ds('curated.payroll'), 'payroll query from the auditors, ticket 1234');
SELECT pg_temp.check(pg_temp.lvl('curated.payroll', 1) = 'read_unmasked' AND (SELECT source FROM dl_access_of(pg_temp.ds('curated.payroll'), 1)) = 'break_glass',
                     'the admin''s break-glass opens rows for an hour, named as such');
SELECT pg_temp.as_member(26);
SELECT pg_temp.refused($$SELECT dl_admin_read_open(1, 'I would like to see everything')$$, 'Lake admin', 'a Member has no break-glass');

-- ================================================================ the policy the query service receives (§6.5)
SELECT pg_temp.keep('pol', 0);
CREATE TEMP TABLE pol AS SELECT dl_query_policy(26, NULL, ARRAY['curated.sales_orders', 'curated.payroll', 'curated.products', 'curated.nothing'], 'screen') AS p;
GRANT SELECT ON pol TO PUBLIC;
SELECT pg_temp.check((SELECT p #>> '{datasets,curated.sales_orders,level}' FROM pol) = 'read'
                     AND (SELECT p #>> '{datasets,curated.sales_orders,masks,customer_email}' FROM pol) = 'partial_email'
                     AND (SELECT p #>> '{datasets,curated.sales_orders,row_filter}' FROM pol) LIKE '%ARRAY[7]%', 'the policy: orders readable, the email masked, the row filter bound');
SELECT pg_temp.check((SELECT jsonb_array_length(p #> '{datasets,curated.sales_orders,columns}') FROM pol) = 5, 'the policy lists the dataset''s live columns');
SELECT pg_temp.check((SELECT p #>> '{refused,curated.payroll,rule}' FROM pol) = 'not_readable' AND (SELECT p #>> '{refused,curated.payroll,message}' FROM pol) LIKE '%may not read%'
                     AND (SELECT p #>> '{refused,curated.products,rule}' FROM pol) = 'discover_only'
                     AND (SELECT p #>> '{refused,curated.nothing,rule}' FROM pol) = 'no_such_dataset', 'refusals say why, by rule: not_readable, discover_only, no_such_dataset');
SELECT pg_temp.check((SELECT (p #>> '{limits,timeout_s}')::int FROM pol) = 30 AND (SELECT (p #>> '{limits,row_limit}')::int FROM pol) = 1000, 'a screen: 30 s and 1,000 rows (D7)');
SELECT pg_temp.check((dl_query_policy(26, NULL, ARRAY['curated.sales_orders'], 'export') #>> '{limits,timeout_s}')::int = 300
                     AND (dl_query_policy(26, NULL, ARRAY['curated.sales_orders'], 'export') #>> '{limits,row_limit}')::int = 100000
                     AND (dl_query_policy(26, NULL, ARRAY['curated.sales_orders'], 'preview') #>> '{limits,row_limit}')::int = 100
                     AND (dl_query_policy(40, 26, ARRAY['curated.sales_orders'], 'agent') #>> '{limits,timeout_s}')::int = 60, 'an export 300 s / 100,000; a preview 100 rows; an agent 60 s');
SELECT pg_temp.check((dl_query_policy(40, 26, ARRAY['curated.sales_orders'], 'agent') #>> '{datasets,curated.sales_orders,row_filter}') LIKE '%ARRAY[7]%'
                     AND (dl_query_policy(40, 29, ARRAY['curated.sales_orders'], 'agent') -> 'datasets') = '{}'::jsonb, 'the policy for an agent in a chat turn is the intersection');
SELECT pg_temp.check((dl_query_policy(27, NULL, ARRAY['curated.payroll'], 'screen') #> '{datasets,curated.payroll,masks}') = '{}'::jsonb
                     AND (dl_query_policy(27, NULL, ARRAY['curated.payroll'], 'screen') #>> '{datasets,curated.payroll,level}') = 'read_unmasked', 'read_unmasked: no masks');
SELECT pg_temp.refused($$SELECT dl_query_policy(26, NULL, ARRAY['curated.products'], 'shell')$$, 'unknown query source', 'an unknown source is refused');
SELECT pg_temp.check((dl_query_policy_app('gl', ARRAY['product.monthly_revenue', 'curated.sales_orders']) #>> '{datasets,product.monthly_revenue,level}') = 'read'
                     AND (dl_query_policy_app('gl', ARRAY['product.monthly_revenue', 'curated.sales_orders']) #>> '{refused,curated.sales_orders,rule}') = 'not_shared'
                     AND (dl_query_policy_app('gl', ARRAY['product.monthly_revenue']) #>> '{limits,result_bytes}')::int = 262144, 'a K7 consumer reads what is granted to it, within K7''s 256 kB');
SET ROLE datalake_query;
SELECT pg_temp.check((dl_query_policy(26, NULL, ARRAY['curated.sales_orders'], 'screen') #>> '{datasets,curated.sales_orders,level}') = 'read', 'the query service''s role may compile a policy');
SELECT pg_temp.keep('qr', dl_query_run_log(jsonb_build_object('caller', 26, 'source', 'screen', 'sql_sha256', repeat('a', 64), 'sql', 'SELECT region, sum(amount) FROM curated.sales_orders GROUP BY 1',
                         'datasets', jsonb_build_array('curated.sales_orders'), 'rows_returned', 4, 'ms', 12, 'status', 'ok', 'masked_columns', '{"curated.sales_orders":{"customer_email":"partial_email"}}'::jsonb)));
SELECT pg_temp.refused($$SELECT * FROM datasets$$, 'permission denied', 'the query service''s role reads no application table but the catalog it needs');
RESET ROLE;
SELECT pg_temp.check((SELECT dataset_ids = ARRAY[pg_temp.ds('curated.sales_orders')] AND status = 'ok' FROM query_runs WHERE id = pg_temp.i('qr')), 'the audit row resolves the dataset keys to ids');
SET ROLE datalake_records_ro;
SELECT pg_temp.refused($$SELECT dl_query_policy(26, NULL, ARRAY['curated.sales_orders'], 'screen')$$, 'permission denied', 'a read role cannot compile a person''s policy');
RESET ROLE;

-- ================================================================ the views, as the read role (§3: the mcp_* views are the only window)
SET ROLE datalake_records_ro;
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM mcp_datasets WHERE NOT is_stub) = 4 AND (SELECT string_agg(key, ',' ORDER BY key) FROM mcp_datasets WHERE is_stub) = 'curated.payroll,raw.crm_deals'
                     AND (SELECT description IS NULL AND tier IS NULL FROM mcp_datasets WHERE key = 'curated.payroll'),
                     'Priya''s catalog: four entries; payroll and the raw deals (a personal column) as stubs with no description');
SELECT pg_temp.check((SELECT my_level FROM mcp_datasets WHERE key = 'curated.sales_orders') = 'read', 'the catalog row carries the caller''s level');
SELECT pg_temp.check((SELECT count(*) FROM mcp_dataset_columns WHERE dataset_id = pg_temp.ds('curated.payroll')) = 0, 'a stub shows no columns');
SELECT pg_temp.refused($$SELECT * FROM datasets$$, 'permission denied', 'the read role cannot read the table — only the view');
SELECT pg_temp.check((SELECT count(*) FROM mcp_sources) = 0, 'a Member sees no source');
SELECT pg_temp.check((SELECT count(*) FROM mcp_query_runs WHERE query_run_id = pg_temp.i('qr')) = 1, 'Priya sees her own query run');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT count(*) FROM mcp_datasets) = 0 AND (SELECT count(*) FROM mcp_members) = 1, 'the external: an empty catalog, and herself alone in the directory');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT count(*) FROM mcp_query_runs WHERE query_run_id = pg_temp.i('qr')) = 0, 'the steward does not see Priya''s query run');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check((SELECT sql IS NULL FROM mcp_query_runs WHERE query_run_id = pg_temp.i('qr')), 'the dataset''s owner sees who read it, not the SQL');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT sql IS NOT NULL FROM mcp_query_runs WHERE query_run_id = pg_temp.i('qr')), 'audit.read sees the SQL');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'mcp_sources' AND column_name IN ('ciphertext', 'credential_id')),
                     'a credential never reaches a view');
RESET ROLE;

-- ================================================================ access requests (§3, §5)
SET ROLE datalake_rw; SELECT pg_temp.as_member(26);
INSERT INTO access_requests (requester_id, dataset_id, level, reason) VALUES (26, pg_temp.ds('curated.payroll'), 'read', 'I reconcile commissions against payroll');
SELECT pg_temp.keep('req', (SELECT id FROM access_requests WHERE requester_id = 26));
SELECT pg_temp.refused($$INSERT INTO access_requests (requester_id, dataset_id, level, reason) VALUES (26, (SELECT id FROM datasets WHERE key = 'curated.payroll'), 'read', 'again please')$$,
                       'duplicate', 'one pending request per person and dataset');
SELECT pg_temp.refused(format('SELECT dl_access_decide(%s, true)', pg_temp.i('req')), 'owner', 'the requester cannot decide');
SELECT pg_temp.as_member(2);
SELECT pg_temp.refused(format('SELECT dl_access_decide(%s, false)', pg_temp.i('req')), 'says why', 'a denial says why');
SELECT dl_access_decide(pg_temp.i('req'), true, NULL, now() + interval '30 days', 'for the quarter');
SELECT pg_temp.check((SELECT status = 'approved' AND grant_id IS NOT NULL FROM access_requests WHERE id = pg_temp.i('req')), 'the owner''s approval makes a grant');
SELECT pg_temp.check(pg_temp.lvl('curated.payroll', 26) = 'read' AND (dl_query_policy(26, NULL, ARRAY['curated.payroll'], 'screen') #>> '{datasets,curated.payroll,masks,salary}') = 'full',
                     'Priya now reads payroll — masked');
SELECT pg_temp.as_member(1);
INSERT INTO access_requests (requester_id, dataset_id, level, reason) VALUES (1, pg_temp.ds('curated.products'), 'read', 'admin requesting for himself');
SELECT pg_temp.refused($$SELECT dl_access_decide((SELECT id FROM access_requests WHERE requester_id = 1), true)$$, 'other than its requester', 'nobody decides their own request');
SELECT pg_temp.check((SELECT jsonb_array_length(dl_access_explain(pg_temp.ds('curated.payroll'), 27) -> 'reasons')) >= 1
                     AND (dl_access_explain(pg_temp.ds('curated.payroll'), 27) #>> '{reasons,0,via}') = 'group', 'the explanation names the grant and the group it came through');
UPDATE grants SET revoked_at = now(), revoked_by = 2 WHERE target_kind = 'dataset' AND principal_kind = 'everyone';
SELECT pg_temp.check(pg_temp.lvl('product.monthly_revenue', 26) = 'discover', 'a revoked grant gives nothing (the catalog rule remains)');
SELECT pg_temp.check((SELECT count(*) FROM access_changes WHERE principal_kind = 'everyone' AND new_level IS NULL) = 1
                     AND (SELECT count(*) FROM access_changes) >= 7, 'every grant and revocation is in the trail');
SELECT pg_temp.refused($$DELETE FROM grants WHERE principal_kind = 'everyone'$$, 'permission denied', 'a grant is revoked, never deleted (the web application holds no DELETE; a trigger backs it)');
RESET ROLE;

-- ================================================================ search (§6.2)
SET ROLE datalake_records_ro; SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT count(*) FROM dl_search('sales orders') WHERE entity_kind = 'dataset' AND dataset_id = pg_temp.ds('curated.sales_orders') AND visibility = 'full') = 1,
                     'a search for "sales orders" finds the dataset by its key''s words');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check((SELECT count(*) FROM dl_search('payroll')) = 0, 'someone with no role here finds nothing');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT count(*) FROM dl_search('products')) = 0, 'the external finds nothing unnamed');
RESET ROLE;
SET ROLE datalake_rw; SELECT pg_temp.as_member(28);
INSERT INTO datasets (key, zone, name, title, owner_member_id, status) VALUES ('curated.payroll_summary', 'curated', 'payroll_summary', 'Payroll summary', 2, 'active');
INSERT INTO dataset_columns (dataset_id, name, ordinal, classification) VALUES (pg_temp.ds('curated.payroll_summary'), 'total', 0, 'restricted');
RESET ROLE;
SET ROLE datalake_records_ro; SELECT pg_temp.as_member(30); RESET ROLE;
UPDATE members SET capability = 'write', roles = '{user}' WHERE id = 30;
SET ROLE datalake_records_ro; SELECT pg_temp.as_member(30);
SELECT pg_temp.check((SELECT count(*) FROM dl_search('payroll') WHERE visibility = 'stub' AND excerpt IS NULL AND classification IS NULL) = 2,
                     'a Member without access finds both payroll datasets as stubs — no excerpt, no classification');
RESET ROLE;

-- ================================================================ ingestion (§6.3)
SET ROLE datalake_rw; SELECT pg_temp.as_member(28);
SELECT pg_temp.refused($$INSERT INTO sources (name, connector, settings) VALUES ('the kernel', 'postgres', '{"host": "", "dbname": "dl_proof"}')$$, 'never one of this server', 'a postgres source never reads a database of this server');
SELECT pg_temp.refused($$INSERT INTO sources (name, connector, settings) VALUES ('a sibling', 'postgres', '{"host": "127.0.0.1", "dbname": "postgres"}')$$, 'never one of this server', '…nor over loopback');
INSERT INTO sources (name, connector, settings) VALUES ('legacy ERP', 'postgres', '{"host": "erp.example.invalid", "dbname": "erp", "tables": ["orders"]}');
SELECT pg_temp.check((SELECT role FROM sources WHERE name = 'legacy ERP') = 'outside_db', 'an outside database is an outside_db source');
SELECT pg_temp.refused($$INSERT INTO sources (name, connector, settings) VALUES ('GL', 'k7', '{}')$$, 'provider', 'a K7 source names its provider');
INSERT INTO sources (name, connector, settings, schedule_minutes) VALUES ('GL balances', 'k7', '{"provider": "gl", "tool": "account_balances"}', 1440);
SELECT pg_temp.check((SELECT role FROM sources WHERE name = 'GL balances') = 'internal_app', 'a K7 source is internal_app');
SELECT pg_temp.refused($$INSERT INTO sources (name, connector) VALUES ('x', 'hadoop')$$, 'check', 'an unknown connector is refused');
INSERT INTO blobs (sha256, byte_size, storage_path) VALUES (repeat('b', 64), 2048, 'landing/bb/bb/' || repeat('b', 64));
INSERT INTO upload_sessions (member_id, filename, byte_size, part_bytes, parts_total, target_dataset_id, blob_id)
VALUES (28, 'deals.csv', 2048, 8388608, 1, pg_temp.ds('raw.crm_deals'), (SELECT id FROM blobs WHERE sha256 = repeat('b', 64)));
INSERT INTO loads (dataset_id, blob_id, landing_sha256, idempotency_key, requested_by)
VALUES (pg_temp.ds('raw.crm_deals'), (SELECT id FROM blobs WHERE sha256 = repeat('b', 64)), repeat('b', 64), pg_temp.ds('raw.crm_deals') || ':' || repeat('b', 64), 28);
SELECT pg_temp.refused(format($$INSERT INTO loads (dataset_id, idempotency_key) VALUES (%s, '%s:%s')$$, pg_temp.ds('raw.crm_deals'), pg_temp.ds('raw.crm_deals'), repeat('b', 64)),
                       'duplicate', 'the same file into the same dataset twice is refused by its key (idempotent)');
SELECT pg_temp.check((SELECT ref_count FROM blobs WHERE sha256 = repeat('b', 64)) = 1, 'a load references its landing blob');
RESET ROLE; SET ROLE datalake_lake;
UPDATE loads SET status = 'loaded', rows_read = 10, rows_loaded = 9, rows_rejected = 1, started_at = now(), finished_at = now() WHERE landing_sha256 = repeat('b', 64);
INSERT INTO load_rejects (load_id, line, error_type, message, raw_line) SELECT id, 7, 'CAST', 'not a number', 'D-7,"seven",buyer7@example.invalid' FROM loads WHERE landing_sha256 = repeat('b', 64);
RESET ROLE;
SELECT pg_temp.check((SELECT purge_after BETWEEN now() + interval '29 days' AND now() + interval '31 days' FROM blobs WHERE sha256 = repeat('b', 64)),
                     'a good load starts the landing window (30 days, D11)');
SET ROLE datalake_records_ro; SELECT pg_temp.as_member(28);
SELECT pg_temp.check((SELECT raw_line IS NOT NULL FROM mcp_load_rejects LIMIT 1), 'the owner sees a rejected line');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM mcp_load_rejects) = 0 AND (SELECT count(*) FROM mcp_loads WHERE dataset_id = pg_temp.ds('raw.crm_deals')) = 0,
                     'someone who only discovers the dataset sees neither its loads nor a rejected line');
RESET ROLE;
-- the writer's queue
SET ROLE datalake_rw; SELECT pg_temp.as_member(28);
SELECT pg_temp.keep('j1', dl_job_enqueue('promote', pg_temp.ds('curated.sales_orders'), NULL, '{}'::jsonb, 5::smallint));
SELECT pg_temp.check(dl_job_enqueue('promote', pg_temp.ds('curated.sales_orders'), NULL, '{}'::jsonb, 5::smallint) = pg_temp.i('j1'), 'one queued job of a kind per dataset');
SELECT pg_temp.keep('j2', dl_job_enqueue('profile', pg_temp.ds('curated.sales_orders'), NULL, '{}'::jsonb, 5::smallint));
RESET ROLE; SET ROLE datalake_lake;
SELECT pg_temp.check((dl_job_claim('w1')).id = pg_temp.i('j1'), 'the writer claims the first job');
SELECT pg_temp.check((dl_job_claim('w2')).id IS NULL, 'never a second running job on the same dataset');
SELECT dl_job_finish(pg_temp.i('j1'), false, NULL, 'transient');
RESET ROLE;
SELECT pg_temp.check((SELECT status = 'queued' AND run_after > now() FROM lake_jobs WHERE id = pg_temp.i('j1')), 'a failed job is retried later');
UPDATE lake_jobs SET attempts = max_attempts, status = 'running' WHERE id = pg_temp.i('j1');
SET ROLE datalake_lake; SELECT dl_job_finish(pg_temp.i('j1'), false, NULL, 'still failing'); RESET ROLE;
SELECT pg_temp.check((SELECT status FROM lake_jobs WHERE id = pg_temp.i('j1')) = 'failed', '…until its attempts are spent');
-- slice 2 (db/021): the writer's own activity rows, its lineage runs, the incident a refused load opens; what it may read
SET ROLE datalake_lake;
SELECT pg_temp.keep('inc', dl_incident_open_load(pg_temp.ds('raw.crm_deals'), (SELECT id FROM loads WHERE landing_sha256 = repeat('b', 64)), 'A load into raw.crm_deals was refused: new columns: x'));
SELECT pg_temp.check(dl_incident_open_load(pg_temp.ds('raw.crm_deals'), NULL, 'again') = pg_temp.i('inc'), 'a refused load opens ONE load incident (a second refusal answers the open one)');
INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, dataset_id) VALUES (28, 'system', 'load.run', 'load', 1, pg_temp.ds('raw.crm_deals'));
INSERT INTO lineage_runs (run_uuid, job_kind, job_name, event) VALUES (gen_random_uuid(), 'rollback', 'rollback.crm_deals', 'START');
RESET ROLE;
SELECT pg_temp.check((SELECT kind || '/' || status || '/' || severity FROM incidents WHERE id = pg_temp.i('inc')) = 'load/new/error'
                     AND (SELECT count(*) FROM agent_dispatches WHERE kind = 'incident' AND dataset_id = pg_temp.ds('raw.crm_deals')) = 1, '…a load incident, new, an error — the Steward dispatched to it');
SELECT pg_temp.check((SELECT count(*) FROM activity_log WHERE action = 'load.run' AND source = 'system' AND actor_member_id = 28) = 1, 'the writer writes its own activity rows (source system, the requester)');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM lineage_runs WHERE job_kind = 'rollback'), 'a rollback is a lineage run of its own');
SELECT pg_temp.check(has_column_privilege('datalake_lake', 'members', 'member_kind', 'SELECT') AND NOT has_column_privilege('datalake_lake', 'members', 'email', 'SELECT'),
                     'the writer reads a member''s kind (a commit''s author), never their email');
SELECT pg_temp.check(NOT has_function_privilege('datalake_rw', 'dl_incident_open_load(bigint, bigint, text)', 'EXECUTE'), 'only the writer opens a load incident');
SELECT pg_temp.check((SELECT data_type FROM information_schema.columns WHERE table_name = 'loads' AND column_name = 'mapping') = 'jsonb', 'a load keeps its mapping (a replay reads the file the same way)');
SELECT pg_temp.check(pg_get_functiondef('dl_job_enqueue(text, bigint, bigint, jsonb, smallint)'::regprocedure) LIKE '%pg_notify(''lake_jobs''%', 'enqueueing a job wakes the writer (pg_notify lake_jobs)');

-- slice 3 (db/022): the admission (an agent's rate, a person's concurrency) in advisory slots; a hidden dataset never confirms itself to the
-- query service either; the writer's sandbox_save kind; the admins told once a day of a policy the service could not honour
SELECT pg_temp.check(dl_query_policy(29, NULL, ARRAY['curated.payroll', 'curated.no_such_thing'], 'screen') #>> '{refused,curated.payroll,rule}' = 'no_such_dataset'
                     AND dl_query_policy(29, NULL, ARRAY['curated.no_such_thing'], 'screen') #>> '{refused,curated.no_such_thing,rule}' = 'no_such_dataset',
                     'to an external a hidden dataset answers exactly as one that does not exist (no_such_dataset) — a key typed in the editor confirms nothing');
SELECT pg_temp.check(dl_query_policy(27, NULL, ARRAY['sandbox.m26_my_upload'], 'screen') #>> '{refused,sandbox.m26_my_upload,rule}' = 'no_such_dataset',
                     '…nor does another''s sandbox dataset to an insider (no stub there); a stub''s dataset answers not_readable (the policy above)');
SET ROLE datalake_query;
SELECT pg_temp.keep('s0', (dl_query_admit(26, 'screen', 'p-1') ->> 'slot')::bigint);
SELECT pg_temp.keep('s1', (dl_query_admit(26, 'screen', 'p-2') ->> 'slot')::bigint);
SELECT pg_temp.check(dl_query_admit(26, 'screen', 'p-3') ->> 'rule' = 'concurrency' AND pg_temp.i('s0') = 0 AND pg_temp.i('s1') = 1,
                     'a person runs two queries at once (query_concurrency_person) — the third is refused `concurrency`');
SELECT pg_temp.check(dl_query_release(26, 0) AND (dl_query_admit(26, 'screen', 'p-4') ->> 'slot') = '0', '…a slot released admits the next');
SELECT dl_query_release(26, 0), dl_query_release(26, 1);
RESET ROLE;
UPDATE dl_settings SET query_rate_agent_per_minute = 1 WHERE id = 1;
INSERT INTO query_runs (caller_member_id, source, sql_sha256, status) VALUES (40, 'agent', repeat('c', 64), 'ok');
SET ROLE datalake_query;
SELECT pg_temp.check(dl_query_admit(40, 'agent', 'a-1') ->> 'rule' = 'rate' AND (dl_query_admit(26, 'screen', 'p-5') ->> 'admitted')::boolean,
                     'an agent over query_rate_agent_per_minute is refused `rate`; a person is not rated');
SELECT dl_query_release(26, 0);
SELECT pg_temp.keep('pf', dl_query_policy_fault('curated.sales_orders', 'row filter may not contain Anonymous'));
SELECT dl_query_policy_fault('curated.sales_orders', 'again');
RESET ROLE;
UPDATE dl_settings SET query_rate_agent_per_minute = 30 WHERE id = 1;
SELECT pg_temp.check(pg_temp.i('pf') >= 1 AND (SELECT count(*) FROM notifications WHERE kind = 'policy_fault' AND record_id = pg_temp.ds('curated.sales_orders'))
                     = (SELECT count(*) FROM members WHERE status = 'active' AND member_kind = 'human' AND dl_member_has_right(id, 'settings.manage')),
                     'a policy the service could not honour tells each Lake admin ONCE a day');
SELECT pg_temp.check(NOT has_function_privilege('datalake_rw', 'dl_query_admit(bigint, text, text)', 'EXECUTE')
                     AND has_function_privilege('datalake_query', 'dl_query_admit(bigint, text, text)', 'EXECUTE'), 'only the query service admits a run');
SET ROLE datalake_rw;
SELECT pg_temp.keep('ss', dl_job_enqueue('sandbox_save', pg_temp.ds('curated.sales_orders'), NULL, '{}'::jsonb));
RESET ROLE;
SELECT pg_temp.check((SELECT kind FROM lake_jobs WHERE id = pg_temp.i('ss')) = 'sandbox_save', 'a result saved as a sandbox dataset is a writer job (sandbox_save)');

-- slice 4 (db/023): whom a request goes to, the words for what a grant reaches through, a waiting request expiring, the grant_expiring notice
SET ROLE datalake_rw; SELECT pg_temp.as_member(1);
SELECT pg_temp.check(ARRAY(SELECT dl_access_deciders(pg_temp.ds('curated.payroll'))) = ARRAY[2]::bigint[], 'a request goes to the dataset''s owner (Ada owns payroll)');
RESET ROLE;
UPDATE datasets SET owner_member_id = NULL WHERE id = pg_temp.ds('curated.products');
SET ROLE datalake_rw;
SELECT pg_temp.check(ARRAY(SELECT dl_access_deciders(pg_temp.ds('curated.products'))) = ARRAY[1, 2]::bigint[],
                     'a dataset with no owner sends its requests to every holder of grants.manage (the two Lake admins — never an external, never an agent)');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(dl_grant_via((SELECT id FROM grants WHERE principal_kind = 'department' AND principal_id = 7 AND dataset_id = pg_temp.ds('curated.sales_orders'))) = 'Sales (your department)',
                     'My access names what a grant reaches through: Priya reads orders through "Sales (your department)"');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(dl_grant_via((SELECT id FROM grants WHERE principal_kind = 'department' AND principal_id = 7 AND dataset_id = pg_temp.ds('curated.sales_orders'))) IS NULL,
                     '…and answers nothing about a grant to someone it does not reach');
RESET ROLE;
UPDATE datasets SET owner_member_id = 28 WHERE id = pg_temp.ds('curated.products');
INSERT INTO access_requests (requester_id, dataset_id, level, reason, created_at) VALUES (27, pg_temp.ds('raw.crm_deals'), 'read', 'an old request nobody decided', now() - interval '31 days');
SET ROLE datalake_rw;
SELECT pg_temp.keep('xp', (SELECT count(*) FROM dl_access_request_expire(now(), 100) WHERE requester_id = 27 AND why = 'thirty_days'));
SELECT pg_temp.check(pg_temp.i('xp') = 1 AND (SELECT status FROM access_requests WHERE requester_id = 27 AND dataset_id = pg_temp.ds('raw.crm_deals')) = 'expired',
                     'a request nobody decided in 30 days expires (the worker''s expiry pass)');
SELECT pg_temp.check(dl_notify(26, 'grant_expiring', 'A grant on curated.sales_orders ends soon', NULL, 'grant', 1) IS NOT NULL, 'a notice of kind grant_expiring is admitted (db/023)');
RESET ROLE;
SELECT pg_temp.check(has_function_privilege('datalake_rw', 'dl_access_deciders(bigint)', 'EXECUTE') AND NOT has_function_privilege('datalake_records_ro', 'dl_access_deciders(bigint)', 'EXECUTE')
                     AND NOT has_function_privilege('datalake_query', 'dl_access_request_expire(timestamptz, integer)', 'EXECUTE'),
                     'the application asks whom a request goes to and expires requests; the read roles and the query service do not');

-- ================================================================ lineage (§6.6)
SET ROLE datalake_lake;
SELECT dl_lineage_edge(NULL, (SELECT id FROM sources WHERE name = 'GL balances'), pg_temp.ds('raw.crm_deals'), 'load');
SELECT dl_lineage_edge(pg_temp.ds('raw.crm_deals'), NULL, pg_temp.ds('curated.sales_orders'), 'promote');
SELECT dl_lineage_edge(pg_temp.ds('curated.sales_orders'), NULL, pg_temp.ds('product.monthly_revenue'), 'transform');
SELECT dl_lineage_edge(pg_temp.ds('curated.sales_orders'), NULL, pg_temp.ds('product.monthly_revenue'), 'transform');
RESET ROLE;
SELECT pg_temp.check((SELECT count(*) FROM lineage_edges) = 3, 'an edge recorded twice is one edge (last_seen moves)');
SELECT pg_temp.check((SELECT count(*) FROM dl_lineage(pg_temp.ds('product.monthly_revenue'), 'up', 5)) = 3, 'upstream of the product: orders, the raw deals, the source');
SELECT pg_temp.check(dl_downstream_keys(pg_temp.ds('raw.crm_deals')) = '["curated.sales_orders", "product.monthly_revenue"]'::jsonb, 'downstream of the raw deals, nearest first');

-- ================================================================ trust: contracts, checks, incidents, certification (§6.4)
SET ROLE datalake_rw; SELECT pg_temp.as_member(28);
INSERT INTO contracts (dataset_id, version, schema, key) VALUES (pg_temp.ds('curated.sales_orders'), '1.0.0',
    '[{"name":"order_id","physical_type":"VARCHAR"},{"name":"amount","physical_type":"DECIMAL(12,2)"},{"name":"region","physical_type":"VARCHAR"},{"name":"customer_email","physical_type":"VARCHAR"},{"name":"department_id","physical_type":"BIGINT"}]', '{order_id}');
SELECT dl_contract_publish((SELECT id FROM contracts WHERE version = '1.0.0'));
SELECT pg_temp.check((SELECT contract_version FROM datasets WHERE key = 'curated.sales_orders') = '1.0.0' AND (SELECT dedupe_key FROM datasets WHERE key = 'curated.sales_orders') = '{order_id}',
                     'publishing records the version and the key on the dataset');
SELECT pg_temp.refused($$UPDATE contracts SET schema = '[]' WHERE version = '1.0.0'$$, 'frozen', 'a published contract is frozen');
INSERT INTO contracts (dataset_id, version, schema, key) VALUES (pg_temp.ds('curated.sales_orders'), '1.1.0',
    '[{"name":"order_id","physical_type":"VARCHAR"},{"name":"amount","physical_type":"DECIMAL(12,2)"},{"name":"customer_email","physical_type":"VARCHAR"},{"name":"department_id","physical_type":"BIGINT"}]', '{order_id}');
SELECT pg_temp.refused($$SELECT dl_contract_publish((SELECT id FROM contracts WHERE version = '1.1.0'))$$, 'new major version', 'removing a column is breaking — a minor version is refused');
INSERT INTO contracts (dataset_id, version, schema, key) VALUES (pg_temp.ds('curated.sales_orders'), '2.0.0',
    '[{"name":"order_id","physical_type":"VARCHAR"},{"name":"amount","physical_type":"DECIMAL(12,2)"},{"name":"region","physical_type":"VARCHAR"},{"name":"customer_email","physical_type":"VARCHAR"},{"name":"department_id","physical_type":"BIGINT"}]', '{order_id}');
SELECT dl_contract_publish((SELECT id FROM contracts WHERE version = '2.0.0'));
SELECT pg_temp.check((SELECT string_agg(version || ':' || status, ',' ORDER BY version) FROM contracts) = '1.0.0:deprecated,1.1.0:draft,2.0.0:active', 'one active contract; the previous deprecated');
INSERT INTO checks (dataset_id, column_name, kind, dimension, severity, blocking) VALUES (pg_temp.ds('curated.sales_orders'), 'order_id', 'not_null', 'completeness', 'error', true);
SELECT pg_temp.as_member(41);
SELECT pg_temp.refused($$INSERT INTO checks (dataset_id, column_name, kind, dimension, severity, blocking) VALUES ((SELECT id FROM datasets WHERE key = 'curated.sales_orders'), 'amount', 'not_null', 'completeness', 'error', true)$$,
                       'agent proposes', 'an agent cannot make a blocking check');
INSERT INTO checks (dataset_id, column_name, kind, dimension, severity, blocking, source) VALUES (pg_temp.ds('curated.sales_orders'), 'amount', 'range', 'validity', 'warn', false, 'agent');
SELECT pg_temp.as_member(28);
-- describe it all, as the owner (eligibility)
UPDATE datasets SET description = 'Every sales order since 2024, one row per order, deduplicated by order id.' WHERE key = 'curated.sales_orders';
UPDATE dataset_columns SET description = 'Described.' WHERE dataset_id = pg_temp.ds('curated.sales_orders');
RESET ROLE; SET ROLE datalake_lake;
SELECT dl_check_result_record(k.id, NULL, 8, 0, '= 0', true, 0, '[]', 3) FROM checks k WHERE k.dataset_id = pg_temp.ds('curated.sales_orders');
RESET ROLE;
SELECT pg_temp.check(dl_certification_eligible(pg_temp.ds('curated.sales_orders')) = '{}', 'eligible: owner, steward, descriptions, a passing blocking check, an active contract');
SELECT pg_temp.check(dl_certification_eligible(pg_temp.ds('curated.products')) @> '{"a steward","a description","a blocking check","an active contract"}',
                     'an undocumented dataset says what it lacks');
SET ROLE datalake_rw; SELECT pg_temp.as_member(26);
SELECT pg_temp.refused($$SELECT dl_certify((SELECT id FROM datasets WHERE key = 'curated.sales_orders'))$$, 'Data engineer', 'a Member cannot certify');
SELECT pg_temp.as_member(28);
SELECT dl_certify(pg_temp.ds('curated.sales_orders'));
SELECT pg_temp.check((SELECT certification FROM datasets WHERE key = 'curated.sales_orders') = 'certified', 'an engineer certifies an eligible dataset');
SELECT pg_temp.refused($$SELECT dl_certify((SELECT id FROM datasets WHERE key = 'curated.products'))$$, 'lacks', 'an ineligible one is refused with what it lacks');
RESET ROLE;
SET ROLE datalake_records_ro; SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT dataset_id FROM dl_search('orders') ORDER BY (certification = 'certified') DESC LIMIT 1) = pg_temp.ds('curated.sales_orders')
                     AND (SELECT certification FROM dl_search('') WHERE entity_kind = 'dataset' LIMIT 1) = 'certified', 'search ranks the certified dataset first');
RESET ROLE;
-- a blocking check fails: the incident opens with its downstream, the owner and the Steward hear of it, the badge drops by itself
SET ROLE datalake_lake;
SELECT dl_check_result_record(k.id, NULL, 9, 3, '= 0', false, 3, '["h:1","h:2","h:3"]', 4) FROM checks k WHERE k.dataset_id = pg_temp.ds('curated.sales_orders') AND k.blocking;
RESET ROLE;
SELECT pg_temp.check((SELECT count(*) FROM incidents WHERE dataset_id = pg_temp.ds('curated.sales_orders') AND status = 'new') = 1, 'a failure opens one incident');
SELECT pg_temp.check((SELECT downstream FROM incidents WHERE dataset_id = pg_temp.ds('curated.sales_orders')) = '["product.monthly_revenue"]'::jsonb, 'the incident names what it reaches downstream');
SELECT pg_temp.check((SELECT certification = 'none' AND certification_lost_reason LIKE '%every check passing%' FROM datasets WHERE key = 'curated.sales_orders'),
                     'the certification is lost by itself, with the reason');
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE member_id = 28 AND kind IN ('check_failed', 'certification_lost')) = 2, 'the owner hears of the failure and of the lost badge');
SELECT pg_temp.check((SELECT count(*) FROM agent_dispatches WHERE agent_member_id = 41 AND kind = 'incident' AND dataset_id = pg_temp.ds('curated.sales_orders')) = 1, 'the Steward is dispatched to triage an error incident');
SET ROLE datalake_lake;
SELECT dl_check_result_record(k.id, NULL, 9, 3, '= 0', false, 3, '[]', 4) FROM checks k WHERE k.dataset_id = pg_temp.ds('curated.sales_orders') AND k.blocking;
SELECT dl_check_result_record(k.id, NULL, 10, 0, '= 0', true, 0, '[]', 4) FROM checks k WHERE k.dataset_id = pg_temp.ds('curated.sales_orders') AND k.blocking;
RESET ROLE;
SELECT pg_temp.check((SELECT count(*) FROM incidents WHERE dataset_id = pg_temp.ds('curated.sales_orders')) = 1
                     AND (SELECT status FROM incidents WHERE dataset_id = pg_temp.ds('curated.sales_orders')) = 'resolved', 'a second failure keeps the one incident; a pass resolves it');
SET ROLE datalake_rw; SELECT pg_temp.as_member(28);
UPDATE datasets SET freshness_warn_after = interval '1 day', freshness_error_after = interval '3 days' WHERE key = 'curated.products';
RESET ROLE; SET ROLE datalake_lake;
UPDATE datasets SET data_updated_at = now() - interval '2 days' WHERE key = 'curated.products';
RESET ROLE;
SELECT pg_temp.check((SELECT state FROM dl_freshness_due() WHERE key = 'curated.products') = 'warn', 'a dataset past its warning is late (R13)');

-- slice 5 (db/024): the breaking rule over a schema not saved yet (the contract form's live list) is the publish's rule; the quarantine's policy
RESET ROLE;
SELECT pg_temp.check(dl_contract_breaking_preview(pg_temp.ds('curated.sales_orders'),
                         '[{"name":"order_id","physical_type":"VARCHAR"},{"name":"amount","physical_type":"DECIMAL(12,2)"},{"name":"customer_email","physical_type":"VARCHAR"},{"name":"department_id","physical_type":"BIGINT"}]',
                         '{order_id}') = '[{"kind": "removed", "column": "region"}]'::jsonb
                     AND dl_contract_breaking_changes((SELECT id FROM contracts WHERE version = '1.1.0')) = '[{"kind": "removed", "column": "region"}]'::jsonb,
                     'the live breaking list and the draft''s are one rule (a column removed against the active 2.0.0)');
SELECT pg_temp.check(dl_contract_breaking_preview(pg_temp.ds('curated.sales_orders'),
                         '[{"name":"order_id","physical_type":"BIGINT"},{"name":"amount","physical_type":"DECIMAL(12,2)"},{"name":"region","physical_type":"VARCHAR"},{"name":"customer_email","physical_type":"VARCHAR"},{"name":"department_id","physical_type":"BIGINT"}]',
                         '{amount}') @> '[{"kind": "retyped", "column": "order_id"}, {"kind": "key_changed"}]'::jsonb, '…a column retyped and the key changed are breaking too');
SET ROLE datalake_query;
SELECT pg_temp.check((dl_quarantine_policy(28, NULL, 'curated.sales_orders') -> 'datasets' -> 'quarantine.sales_orders' -> 'columns') @> '["order_id", "_dl_reason", "_dl_row"]'::jsonb
                     AND (dl_quarantine_policy(28, NULL, 'curated.sales_orders') -> 'datasets' -> 'quarantine.sales_orders' -> 'masks') = '{}'::jsonb,
                     'the quarantine''s policy for its owner: the contract''s columns, the reason, the provenance; nothing masked at own');
SELECT pg_temp.check(dl_quarantine_policy(26, NULL, 'curated.sales_orders') -> 'refused' -> 'curated.sales_orders' ->> 'rule' = 'not_readable'
                     AND dl_quarantine_policy(26, NULL, 'curated.sales_orders') -> 'datasets' = '{}'::jsonb, '…for a reader (Priya): refused — it is read at write');
SELECT pg_temp.check(dl_quarantine_policy(29, NULL, 'curated.sales_orders') -> 'refused' -> 'curated.sales_orders' ->> 'rule' = 'no_such_dataset'
                     AND dl_quarantine_policy(28, NULL, 'raw.crm_deals') -> 'refused' -> 'raw.crm_deals' ->> 'rule' = 'no_such_dataset',
                     '…for the external: no such dataset; a raw key has no quarantine');
SELECT pg_temp.check((dl_quarantine_policy(28, NULL, 'curated.sales_orders') -> 'limits' ->> 'row_limit')::int = 100, '…with a preview''s limits (100 rows)');
RESET ROLE;
SELECT pg_temp.check(has_table_privilege('datalake_rw', 'checks', 'DELETE') AND has_function_privilege('datalake_query', 'dl_quarantine_policy(bigint, bigint, text, text)', 'EXECUTE')
                     AND NOT has_function_privilege('datalake_records_ro', 'dl_quarantine_policy(bigint, bigint, text, text)', 'EXECUTE'),
                     'the application deletes checks (check_delete); the query service compiles the quarantine; the read roles do not');
SET ROLE datalake_records_ro; SELECT pg_temp.as_member(28);
SELECT pg_temp.check((SELECT opened_by_result_id IS NOT NULL AND resolved_by_result_id IS NOT NULL FROM mcp_incidents WHERE dataset_id = pg_temp.ds('curated.sales_orders') LIMIT 1),
                     'the incident view names the results that opened and resolved it');
RESET ROLE;

-- ================================================================ queries, metrics, transforms (§6.5, §6.6)
SET ROLE datalake_rw; SELECT pg_temp.as_member(27);
INSERT INTO saved_queries (owner_member_id, title, sql, dataset_ids) VALUES (27, 'Revenue by region', 'SELECT region, sum(amount) FROM curated.sales_orders GROUP BY 1', ARRAY[pg_temp.ds('curated.sales_orders')]);
SELECT pg_temp.as_member(28);
SELECT dl_saved_query_verify((SELECT id FROM saved_queries WHERE title = 'Revenue by region'), true);
SELECT pg_temp.check((SELECT verified_at IS NOT NULL FROM saved_queries WHERE title = 'Revenue by region'), 'an engineer verifies a query');
UPDATE saved_queries SET sql = 'SELECT region, sum(amount) * 2 FROM curated.sales_orders GROUP BY 1' WHERE title = 'Revenue by region';
SELECT pg_temp.check((SELECT verified_at IS NULL AND unverified_reason LIKE '%SQL changed%' FROM saved_queries WHERE title = 'Revenue by region'), 'the badge drops when the SQL changes (D9)');
SELECT pg_temp.refused($$INSERT INTO metrics (name, title, dataset_id, expression) VALUES ('revenue', 'Revenue', (SELECT id FROM datasets WHERE key = 'curated.sales_orders'), 'sum(amount)')$$,
                       'over a product', 'a metric is defined over a product');
INSERT INTO metrics (name, title, dataset_id, expression, time_column, dimensions, certified, certified_at, certified_by)
VALUES ('revenue', 'Revenue', pg_temp.ds('product.monthly_revenue'), 'sum(revenue)', 'month', '{}', true, now(), 28);
UPDATE metrics SET expression = 'sum(revenue) * 1.1' WHERE name = 'revenue';
SELECT pg_temp.check(NOT (SELECT certified FROM metrics WHERE name = 'revenue'), 'a certified metric loses its badge when its definition changes');
SELECT pg_temp.refused($$INSERT INTO transforms (name, output_dataset_id, sql, owner_member_id) VALUES ('to_raw', (SELECT id FROM datasets WHERE key = 'raw.crm_deals'), 'SELECT 1', 28)$$,
                       'never raw', 'a transform never writes raw');
INSERT INTO transforms (name, output_dataset_id, sql, owner_member_id) VALUES ('monthly_revenue', pg_temp.ds('product.monthly_revenue'),
    'SELECT date_trunc(''month'', ordered_at) AS month, sum(amount) AS revenue FROM curated.sales_orders GROUP BY 1', 28);
SELECT dl_transform_freeze((SELECT id FROM transforms WHERE name = 'monthly_revenue'), ARRAY['curated.sales_orders']);
SELECT pg_temp.check((SELECT frozen_policy #>> '{datasets,curated.sales_orders,level}' FROM transforms WHERE name = 'monthly_revenue') = 'read_unmasked'
                     AND (SELECT classification FROM datasets WHERE key = 'product.monthly_revenue') = 'internal',
                     'a transform freezes its owner''s level; a product is classified by its own columns');
UPDATE transforms SET sql = 'SELECT 1 AS month, 2 AS revenue' WHERE name = 'monthly_revenue';
SELECT pg_temp.check((SELECT version = 2 AND frozen_policy IS NULL FROM transforms WHERE name = 'monthly_revenue'), 'new SQL is a new version, unfrozen until refrozen');
SELECT pg_temp.as_member(26);
INSERT INTO transforms (name, output_dataset_id, sql, owner_member_id) VALUES ('my_payroll_cut', pg_temp.ds('sandbox.m26_my_upload'), 'SELECT employee_name FROM curated.payroll', 26);
SELECT pg_temp.refused($$SELECT dl_transform_freeze((SELECT id FROM transforms WHERE name = 'my_payroll_cut'), ARRAY['curated.payroll', 'raw.crm_deals'])$$, 'may not read', 'a transform cannot read what its owner may not');
SELECT dl_transform_freeze((SELECT id FROM transforms WHERE name = 'my_payroll_cut'), ARRAY['curated.payroll']);
SELECT pg_temp.check((SELECT classification FROM datasets WHERE key = 'sandbox.m26_my_upload') = 'confidential', 'a sandbox result keeps the highest classification of its inputs (rule 6)');
RESET ROLE;
SELECT pg_temp.check((dl_impact(pg_temp.ds('curated.sales_orders')) -> 'metrics' -> 0 ->> 'name') = 'revenue'
                     AND (dl_impact(pg_temp.ds('curated.sales_orders')) -> 'consumers') = '["gl"]'::jsonb
                     AND jsonb_array_length(dl_impact(pg_temp.ds('curated.sales_orders')) -> 'saved_queries') = 1, 'impact names the metric, the K7 consumer and the saved query downstream');

-- ================================================================ personal data and the Steward's proposals (§5, §6.3)
SET ROLE datalake_lake;
UPDATE dataset_columns SET proposed_personal_kind = 'name', proposed_confidence = 0.92 WHERE dataset_id = pg_temp.ds('curated.products') AND name = 'name';
RESET ROLE;
SET ROLE datalake_rw; SELECT pg_temp.as_member(41);
INSERT INTO steward_proposals (kind, dataset_id, proposed_value, rationale, proposed_by)
VALUES ('description', pg_temp.ds('curated.products'), '{"description": "The products the business sells, one row per product id."}', 'from the schema and the profile', 41);
INSERT INTO steward_proposals (kind, dataset_id, column_id, proposed_value, rationale, proposed_by)
SELECT 'personal_data', pg_temp.ds('curated.products'), id, '{"personal_kind": "name"}', '92% of values match the names dictionary', 41 FROM dataset_columns WHERE dataset_id = pg_temp.ds('curated.products') AND name = 'name';
SELECT pg_temp.refused($$SELECT dl_steward_proposal_decide((SELECT id FROM steward_proposals WHERE kind = 'description'), 'accepted')$$, 'A person decides', 'the agent never decides its own proposal');
SELECT pg_temp.as_member(26);
SELECT pg_temp.refused($$SELECT dl_steward_proposal_decide((SELECT id FROM steward_proposals WHERE kind = 'description'), 'accepted')$$, 'owner', 'a Member who neither owns nor stewards it cannot decide');
SELECT pg_temp.as_member(28);
SELECT dl_steward_proposal_decide((SELECT id FROM steward_proposals WHERE kind = 'description'), 'accepted');
SELECT dl_steward_proposal_decide((SELECT id FROM steward_proposals WHERE kind = 'personal_data'), 'accepted');
RESET ROLE;
SELECT pg_temp.check((SELECT description_source = 'accepted' AND description LIKE 'The products%' FROM datasets WHERE key = 'curated.products'), 'an accepted description is marked accepted');
SELECT pg_temp.check((SELECT personal_kind = 'name' AND classification = 'confidential' AND classified_by = 'person' FROM dataset_columns WHERE dataset_id = pg_temp.ds('curated.products') AND name = 'name')
                     AND (SELECT classification FROM datasets WHERE key = 'curated.products') = 'confidential', 'a confirmed personal kind masks from now on and reclassifies the dataset');

-- ================================================================ notifications keep the wall
SELECT pg_temp.check(dl_notify(29, 'deprecated', 'Payroll is deprecated', NULL, 'dataset', pg_temp.ds('curated.payroll'), pg_temp.ds('curated.payroll')) IS NULL,
                     'no notice about a dataset the member may not discover');
SELECT pg_temp.check(dl_notify(27, 'deprecated', 'Payroll is deprecated', NULL, 'dataset', pg_temp.ds('curated.payroll'), pg_temp.ds('curated.payroll')) IS NOT NULL
                     AND (SELECT count(*) FROM notification_outbox WHERE member_id = 27 AND channel = 'email') = 1, 'a member who may see it gets the bell and the email');

-- ================================================================ retention and erasure (§6.7, D11)
SET ROLE datalake_rw; SELECT pg_temp.as_member(1);
INSERT INTO retention_policies (zone, keep_days) VALUES ('curated', 365);
INSERT INTO retention_policies (dataset_id, keep_days) VALUES (pg_temp.ds('curated.sales_orders'), 30);
SELECT pg_temp.check((dl_retention_of(pg_temp.ds('curated.sales_orders'))).keep_days = 30 AND (dl_retention_of(pg_temp.ds('curated.products'))).keep_days = 365,
                     'a dataset''s own retention wins over its zone''s');
SELECT pg_temp.refused($$INSERT INTO erasure_requests (identifiers, reason, requested_by) VALUES ('[{"kind": "shoe_size", "value": "44"}]', 'test', 1)$$, 'personal kind', 'an identifier is a personal kind');
INSERT INTO erasure_requests (identifiers, reason, requested_by) VALUES ('[{"kind": "email", "value": "buyer7@example.invalid"}]', 'GDPR Art. 17 request #12', 1);
SELECT pg_temp.keep('er', (SELECT id FROM erasure_requests));
RESET ROLE; SET ROLE datalake_lake;
INSERT INTO datasets (key, zone, name, title, owner_member_id, status) VALUES ('curated.order_lines', 'curated', 'order_lines', 'Order lines', 28, 'active');
INSERT INTO dataset_columns (dataset_id, name, ordinal, physical_type, references_dataset_id, references_column)
VALUES (pg_temp.ds('curated.order_lines'), 'order_ref', 0, 'VARCHAR', pg_temp.ds('curated.sales_orders'), 'order_id');
RESET ROLE; SET ROLE datalake_rw; SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT count(*) FROM dl_erasure_targets(pg_temp.i('er')) WHERE via = 'column') = 2
                     AND (SELECT string_agg(key || '.' || column_name, ',' ORDER BY key) FROM dl_erasure_targets(pg_temp.i('er')) WHERE via = 'column') = 'curated.sales_orders.customer_email,raw.crm_deals.buyer_email',
                     'the scan looks in every email column, in every zone');
SELECT pg_temp.check((SELECT key || '.' || column_name || ' → ' || references_column FROM dl_erasure_targets(pg_temp.i('er')) WHERE via = 'reference')
                     = 'curated.order_lines.order_ref → order_id', 'the scan follows a declared reference: the subject''s order lines, keyed by order id (FINDINGS §7)');
SELECT pg_temp.refused(format('SELECT dl_erasure_approve(%s, true)', pg_temp.i('er')), 'after its scan reports', 'an erasure is approved only after its scan reports');
RESET ROLE; UPDATE erasure_requests SET status = 'awaiting_approval', found = '{"curated.sales_orders": {"rows": 1}, "raw.crm_deals": {"rows": 1}}' WHERE id = pg_temp.i('er');
SET ROLE datalake_rw; SELECT pg_temp.as_member(1);
SELECT pg_temp.refused(format('SELECT dl_erasure_approve(%s, true)', pg_temp.i('er')), 'other than its requester', 'nobody approves their own erasure');
SELECT pg_temp.as_member(28);
SELECT pg_temp.refused(format('SELECT dl_erasure_approve(%s, true)', pg_temp.i('er')), 'Lake admin', 'an engineer cannot approve an erasure');
SELECT pg_temp.as_member(2);
SELECT pg_temp.refused(format('SELECT dl_erasure_approve(%s, false)', pg_temp.i('er')), 'time travel', 'an approval acknowledges that time travel ends lake-wide (FINDINGS §7)');
SELECT dl_erasure_approve(pg_temp.i('er'), true);
SELECT pg_temp.check((SELECT status = 'erasing' AND approved_by = 2 AND time_travel_acknowledged FROM erasure_requests WHERE id = pg_temp.i('er'))
                     AND (SELECT count(*) FROM lake_jobs WHERE kind = 'erasure' AND ref_id = pg_temp.i('er')) = 1, 'approval queues the erasure for the writer');
RESET ROLE;
SET ROLE datalake_records_ro; SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT identifier_kinds::text NOT LIKE '%example.invalid%' AND identifier_count = 1 FROM mcp_erasure_requests), 'the view shows an erasure''s kinds, never its values');
RESET ROLE;
UPDATE erasure_requests SET status = 'done', finished_at = now() WHERE id = pg_temp.i('er');
SELECT dl_erasure_seal(pg_temp.i('er'));
SELECT pg_temp.check((SELECT identifiers::text NOT LIKE '%buyer7%' AND identifiers -> 0 ? 'sha256' FROM erasure_requests WHERE id = pg_temp.i('er')), 'a done erasure keeps its identifiers only as hashes');

-- ================================================================ Phase 1 reads (db/018)
SELECT pg_temp.check((dl_query_policy(26, NULL, ARRAY['curated.sales_orders'], 'screen') #>> '{datasets,curated.sales_orders,classification}') = 'confidential'
                     AND (dl_query_policy(26, NULL, ARRAY['curated.sales_orders'], 'screen') #>> '{datasets,curated.sales_orders,has_personal}')::boolean,
                     'the policy carries each dataset''s classification and personal flag');
SELECT pg_temp.check((dl_query_policy(40, NULL, ARRAY['curated.sales_orders', 'product.monthly_revenue'], 'agent', 'internal_unpersonal') #>> '{refused,curated.sales_orders,rule}') = 'k38_pending'
                     AND (dl_query_policy(40, NULL, ARRAY['product.monthly_revenue'], 'agent', 'internal_unpersonal') #>> '{datasets,product.monthly_revenue,level}') IS NOT DISTINCT FROM
                         (dl_query_policy(40, NULL, ARRAY['product.monthly_revenue'], 'agent') #>> '{datasets,product.monthly_revenue,level}'),
                     'before K38 an agent''s chat turn is refused personal and confidential data (k38_pending) and keeps the rest — fail closed');
SELECT pg_temp.refused($$SELECT dl_query_policy(26, NULL, ARRAY['curated.products'], 'screen', 'open_everything')$$, 'unknown ceiling', 'an unknown ceiling is refused');
INSERT INTO dataset_usage_daily (dataset_id, day, queries, people, agents, rows_read) VALUES (pg_temp.ds('curated.sales_orders'), current_date, 9, 2, 1, 900),
       (pg_temp.ds('curated.payroll'), current_date, 50, 1, 0, 50);
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT key FROM dl_popular_datasets(30) LIMIT 1) = 'curated.payroll' AND (SELECT count(*) FROM dl_popular_datasets(30)) = 2, 'popular datasets, the most used first — Priya now reads payroll');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT count(*) FROM dl_popular_datasets(30)) = 0, 'the external sees no popular dataset she may not discover');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM dl_dataset_frequent_users(pg_temp.ds('curated.sales_orders'))) = 1
                     AND (SELECT name FROM dl_dataset_frequent_users(pg_temp.ds('curated.sales_orders'))) = 'SMOKE Priya', 'frequent users come from the audit');
SELECT pg_temp.check((SELECT string_agg(key, ',' ORDER BY key) FROM dl_my_access('read')) = 'curated.payroll,curated.sales_orders,sandbox.m26_my_upload',
                     'what Priya may read: payroll (her approved request), orders (her department), her sandbox');
SELECT pg_temp.check((SELECT row_filtered FROM dl_my_access('read') WHERE key = 'curated.sales_orders') AND (SELECT expires_at IS NOT NULL FROM dl_my_access('read') WHERE key = 'curated.payroll'),
                     'it says which reads are filtered and which grants expire');
SELECT pg_temp.check((SELECT count(*) FROM dl_access_list(pg_temp.ds('curated.sales_orders'))) = 0, 'a reader who is not the owner sees no access list');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check((SELECT count(*) FROM dl_access_list(pg_temp.ds('curated.sales_orders'))) = 4
                     AND (SELECT string_agg(via, ',' ORDER BY via) FROM dl_access_list(pg_temp.ds('curated.sales_orders'))) = 'dataset,dataset,owner,steward',
                     'the owner sees who reads orders: herself, the steward, Sales and the expert');
SELECT pg_temp.check((SELECT kind = 'person' AND queries = 1 FROM dl_dataset_readers(pg_temp.ds('curated.sales_orders'))), 'the owner sees who read it, from the audit');
SELECT pg_temp.check((SELECT count(*) FROM dl_access_history(pg_temp.ds('curated.sales_orders'), now())) = 0, 'access history is grants.manage''s');
SELECT pg_temp.check(dl_engine_state() IS NULL, 'the engine''s state is the admin''s');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT count(*) FROM dl_access_history(pg_temp.ds('curated.sales_orders'), now())) = 3, 'who could read orders now: the owner, Sales, the expert');
RESET ROLE; UPDATE grants SET created_at = now() - interval '1 day'; UPDATE datasets SET created_at = now() - interval '2 days'; SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT count(*) FROM dl_access_history(pg_temp.ds('product.monthly_revenue'), now() - interval '1 hour')) = 3
                     AND (SELECT count(*) FROM dl_access_history(pg_temp.ds('product.monthly_revenue'), now() + interval '1 hour')) = 2,
                     'the revoked everyone grant counts before its revocation, not after');
SELECT pg_temp.check((SELECT count(*) FROM dl_grants_expiring(40)) = 1, 'grants expiring: Priya''s payroll grant (30 days)');
SELECT pg_temp.check((SELECT count(*) FROM dl_grants_unused(90)) = 0, 'no dataset grant is older than 90 days yet');
SELECT pg_temp.check((dl_engine_state() -> 'queue' ->> 'failed_24h')::int = 1 AND (dl_engine_state() -> 'queries_today' ->> 'ok')::int >= 1, 'the engine''s state: the failed job, today''s query');
SELECT pg_temp.check((dl_share_datasets_index('gl') -> 'datasets' -> 0 ->> 'key') = 'product.monthly_revenue' AND jsonb_array_length(dl_share_datasets_index('gl') -> 'datasets') = 1
                     AND jsonb_array_length(dl_share_datasets_index('inventory') -> 'datasets') = 0, 'the K7 index answers what is granted to the consumer, nothing else');
SELECT pg_temp.as_member(40); SELECT set_config('app.on_behalf_of', '29', true);
SELECT pg_temp.check(NOT dl_can_see_stub(pg_temp.ds('curated.payroll')), 'an agent acting for the external sees no stub either (access-and-policies decision 7)');
SELECT set_config('app.on_behalf_of', '26', true);
SELECT pg_temp.check(dl_can_see_stub(pg_temp.ds('curated.payroll')), '…and acting for an insider it does');
SELECT set_config('app.on_behalf_of', '', true); SELECT pg_temp.as_member(1);
SELECT pg_temp.check(has_function_privilege('datalake_lake', 'dl_query_run_log(jsonb)', 'EXECUTE'), 'the writer writes the audit of the exports and transforms it runs');
UPDATE dl_settings SET steward_member_id = 41;
SELECT pg_temp.check(dl_steward_agent() = 41, 'the Steward the settings name');

-- ================================================================ the read roles see views only; the engine's schema is not theirs
SET ROLE datalake_activity_ro; SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM mcp_datasets) >= 5, 'the activity role reads the catalog view');
SELECT pg_temp.refused($$SELECT * FROM query_runs$$, 'permission denied', 'the activity role reads no table');
RESET ROLE;
SELECT pg_temp.check(NOT has_table_privilege('datalake_rw', 'query_runs', 'INSERT') AND has_function_privilege('datalake_query', 'dl_query_run_log(jsonb)', 'EXECUTE'),
                     'only the query service (and its function) writes the audit');
SELECT pg_temp.check(NOT has_table_privilege('datalake_rw', 'lake_jobs', 'UPDATE') AND has_table_privilege('datalake_lake', 'lake_jobs', 'UPDATE'), 'only the writer works the queue');

-- ================================================================ the estate's tables, verbatim (shared-schema.md)
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'sources' AND ordinal_position <= 24)
                     = 'id,name,connector,role,supplier_id,base_url,settings,credential_id,schedule_minutes,rate_per_second,user_agent,robots_state,robots_checked_at,last_pull_id,last_ok_at,consecutive_failures,backoff_until,paused_at,paused_reason,active,created_by,created_at,updated_at',
                     'sources: Inventory''s canonical columns, in order');
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'search_index' AND ordinal_position <= 14)
                     = 'entity_kind,entity_uuid,entity_id,page_id,space_id,channel_id,author_member_id,title,body,tsv,has_link,has_file,occurred_at,updated_at',
                     'search_index: Spaces'' canonical columns, in order, the lake''s appended after');
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'groups')
                     = 'id,key,name,description,kind,department_id,member_level,created_by,archived_at,created_at,updated_at', 'groups: DocCloud''s canonical, verbatim');
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'blobs' AND ordinal_position <= 7)
                     = 'id,sha256,byte_size,storage_path,ref_count,created_at,deleted_at', 'blobs: DocCloud''s canonical columns, in order');

-- ================================================================ slice 6 (db/025): a source is deleted by the application's role; its pulls, its credential and its lineage edge go with it; the dataset and its loads stay
SELECT pg_temp.check(has_table_privilege('datalake_rw', 'sources', 'DELETE') AND NOT has_table_privilege('datalake_rw', 'source_pulls', 'DELETE') AND NOT has_table_privilege('datalake_lake', 'sources', 'DELETE'),
                     'only the application deletes a source — not a pull directly, not the writer');
INSERT INTO sources (name, connector, settings, schedule_minutes) VALUES ('Delete me', 'http_file', '{"url": "https://files.example.invalid/f.csv"}', 60);
INSERT INTO source_credentials (source_id, kind, label, last4, ciphertext) SELECT id, 'bearer', 'proof', 'abcd', convert_to('v1.sealed', 'UTF8') FROM sources WHERE name = 'Delete me';
UPDATE sources SET credential_id = (SELECT c.id FROM source_credentials c WHERE c.label = 'proof') WHERE name = 'Delete me';
INSERT INTO source_pulls (source_id, kind, status, listings_seen, listings_new) SELECT id, 'manual', 'ok', 1, 1 FROM sources WHERE name = 'Delete me';
INSERT INTO loads (dataset_id, source_id, pull_id, idempotency_key, status, rows_read, rows_loaded)
SELECT pg_temp.ds('raw.crm_deals'), s.id, (SELECT p.id FROM source_pulls p WHERE p.source_id = s.id), 'proof:delete-me', 'loaded', 1, 1 FROM sources s WHERE s.name = 'Delete me';
SELECT dl_lineage_edge(NULL, (SELECT id FROM sources WHERE name = 'Delete me'), pg_temp.ds('raw.crm_deals'), 'load');
SELECT id AS delsrc FROM sources WHERE name = 'Delete me' \gset
SELECT pg_temp.check((SELECT count(*) FROM lineage_edges WHERE from_source_id = :delsrc) = 1 AND (SELECT count(*) FROM source_pulls WHERE source_id = :delsrc) = 1, 'the source has a pull and a lineage edge');
SET ROLE datalake_rw;
DELETE FROM sources WHERE id = :delsrc;
RESET ROLE;
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM sources WHERE id = :delsrc) AND NOT EXISTS (SELECT 1 FROM source_pulls WHERE source_id = :delsrc) AND NOT EXISTS (SELECT 1 FROM source_credentials WHERE source_id = :delsrc)
                     AND NOT EXISTS (SELECT 1 FROM lineage_edges WHERE from_source_id = :delsrc), 'deleting a source takes its pulls, its sealed credential and its lineage edge with it');
SELECT pg_temp.check((SELECT source_id IS NULL AND pull_id IS NULL FROM loads WHERE idempotency_key = 'proof:delete-me') AND EXISTS (SELECT 1 FROM datasets WHERE key = 'raw.crm_deals'),
                     'the load stays (it loses its source and its pull) and so does the dataset it fed');
SELECT pg_temp.refused($$SET ROLE datalake_rw; DELETE FROM source_pulls$$, 'permission denied', 'the application cannot delete a pull directly');

SET ROLE datalake_rw;
SELECT pg_temp.check((SELECT count(*) FROM members) = 10 AND (SELECT count(*) FROM datasets) >= 9, 'the fixtures are there until the rollback');
SELECT pg_temp.check((SELECT inline_flush_minutes FROM dl_settings) = 60, 'small loads are flushed out of the catalog hourly (FINDINGS §4)');
RESET ROLE;

\o
\pset tuples_only on
SELECT CASE WHEN ok THEN ' ok   ' ELSE ' FAIL ' END || label FROM proof ORDER BY n;
SELECT ' ' || count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' FROM proof;
SELECT count(*) FILTER (WHERE NOT ok) > 0 AS anyfail FROM proof \gset
ROLLBACK;
\if :anyfail
    \echo 'PHASE 0 PROOF FAILED'
    \set ON_ERROR_STOP on
    SELECT 1 / 0;
\endif
