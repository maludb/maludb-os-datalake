-- 004: the Data Lake's roles and the rights they give (kernel db/145; roles-and-rights.md; the design §3, D5).
--
-- Published to the kernel by app_roles on the records MCP (os.app-roles/1). The kernel grants a SET of roles; the claims and
-- the feed carry them back into members.roles. What a role lets its holder do is the application's to enforce:
-- dl_has_right(right) answers from the catalogue and the mirror. The base role's KEY IS `user` on purpose (Member): the
-- installer's --grant-standing-departments gives a department the role keyed member/user/write.
--
-- Rights are application-wide capabilities. WHICH ROWS OF WHICH DATASET a caller reads is NOT a right: it is the dataset's level for
-- the caller (discover / read / read_unmasked / write / own — grants on a dataset, a zone or a tag, with masks and row filters; design §3
-- rules 1–9), compiled by dl_query_policy() in the query service (db/006+). The owner and the steward of a dataset are ROWS on the
-- dataset, never roles. Four roles (D5): Member (user), Analyst, Data engineer, Lake admin (is_admin).
--
-- THE ESTATE'S DATA MODEL (design §6.8): the shape is Consultant Tracking db/004 = DocCloud db/004 = Sales CRM db/004 (crm_ → dl_); the
-- roles and rights are this application's; dl_member_roles(member) is Inventory db/004's convenience (the roles a member holds, resolved);
-- dl_member_has_right(member, right) is new here — the query service asks it of the PERSON an agent acts for (design §3 rule 8, K38).
BEGIN;

CREATE TABLE dl_rights (
    right_key   text PRIMARY KEY CHECK (right_key ~ '^[a-z][a-z0-9_.]{0,59}$'),
    description text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0
);
CREATE TABLE dl_roles (
    role_key    text PRIMARY KEY CHECK (role_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    is_admin    boolean NOT NULL DEFAULT false,
    sort_order  integer NOT NULL DEFAULT 0,
    CHECK (NOT is_admin OR capability = 'admin')
);
CREATE UNIQUE INDEX dl_roles_one_admin ON dl_roles (is_admin) WHERE is_admin;
CREATE TABLE dl_role_rights (
    role_key  text NOT NULL REFERENCES dl_roles(role_key) ON DELETE CASCADE,
    right_key text NOT NULL REFERENCES dl_rights(right_key) ON DELETE CASCADE,
    PRIMARY KEY (role_key, right_key)
);

INSERT INTO dl_rights (right_key, description, sort_order) VALUES
 -- Member (design §3)
 ('catalog.browse',      'Search and browse the catalog: every public and internal dataset''s entry, columns, profile (personal values masked), quality and lineage; a stub for a confidential or restricted one not granted', 1),
 ('data.read',           'Read the rows of datasets granted to them (masked below read_unmasked), through the query service: the preview, saved queries, metrics, exports', 2),
 ('queries.run',         'Run saved and verified queries with parameters', 3),
 ('metrics.use',         'Ask a metric''s value by grain and dimension', 4),
 ('ask.use',             'Ask the Data Lake Expert in the ask window and the command bar', 5),
 ('access.request',      'Request a level on a dataset, with a reason and an end date', 6),
 ('sandbox.upload',      'Upload a file into their own sandbox (a raw dataset theirs alone)', 7),
 ('follow.use',          'Follow a dataset: hear about schema changes, failed checks, deprecation and access decisions', 8),
 ('export.own',          'Export what they may read, within the download limits (pauses for an agent)', 9),
 ('keys.own',            'Mint, rotate and revoke their own keys for the API', 10),
 -- Analyst
 ('sql.editor',          'Write SQL in the editor (the allow-listed dialect, through the query service, as themselves)', 11),
 ('queries.save',        'Save a query, share it, give it parameters', 12),
 ('sandbox.save',        'Save a result as a dataset in their sandbox (it keeps the highest classification of its inputs)', 13),
 ('sandbox.transform',   'Keep transforms whose output is in their sandbox', 14),
 ('products.propose',    'Propose a sandbox dataset for promotion to curated or product', 15),
 -- Data engineer
 ('sources.manage',      'Keep sources and their sealed credentials; test, schedule, pause and resume them', 16),
 ('loads.manage',        'Run, replay and roll back loads (a rollback is a new snapshot, never a rewind)', 17),
 ('contracts.manage',    'Draft and publish contracts (a breaking change pauses for an agent)', 18),
 ('promote.run',         'Promote raw into curated under the contract; rebuild curated from raw', 19),
 ('transforms.manage',   'Keep transforms whose output is in curated or product', 20),
 ('checks.manage',       'Keep checks (blocking or not) and run them', 21),
 ('incidents.manage',    'Acknowledge, assign, resolve and dismiss incidents', 22),
 ('catalog.edit',        'Describe datasets and columns, tag, classify, set owners and stewards, keep the glossary, deprecate and retire', 23),
 ('metrics.manage',      'Define metrics over products and certify them', 24),
 ('queries.verify',      'Verify a saved query (the badge drops when its SQL changes; pauses for an agent)', 25),
 ('maintenance.run',     'Run maintenance on demand: flush, merge, expire, clean up', 26),
 -- Lake admin
 ('grants.manage',       'Grant and revoke any level on any dataset, zone or tag (pauses for an agent)', 27),
 ('policies.manage',     'Keep classifications, masking policies and row-filter templates (pauses for an agent)', 28),
 ('retention.manage',    'Keep retention policies per zone and dataset', 29),
 ('erasure.manage',      'Request, approve and run an erasure across every zone (pauses for an agent)', 30),
 ('settings.manage',     'Run the Data Lake: the application settings, windows and quotas', 31),
 ('query_limits.manage', 'Set the query service''s limits (time, rows, memory, workers, rates)', 32),
 ('audit.read',          'Read every query run (the SQL while it is kept, the datasets, the masks applied, the rows)', 33),
 ('keys.all',            'See, rotate and revoke anyone''s key', 34),
 ('agents.settings',     'See every agent''s dispatches and the Steward''s proposals and settings; link to the kernel for hires and grants', 35),
 ('lake.admin_read',     'Read a dataset''s rows by break-glass, with a reason — logged and told to its owner', 36);

INSERT INTO dl_roles (role_key, name, description, capability, is_admin, sort_order) VALUES
 ('user',     'Member',        'Finds data in the catalog, reads what is granted to them (masked), runs saved queries and metrics, asks the Expert, requests access, uploads a file into their sandbox, follows datasets.', 'write', false, 1),
 ('analyst',  'Analyst',       'A Member who works with data: the SQL editor, saved queries, results saved as sandbox datasets, sandbox transforms, proposals for promotion.', 'write', false, 2),
 ('engineer', 'Data engineer', 'Builds the lake: sources and credentials, loads, contracts and promotion, transforms into curated and product, checks and incidents, descriptions, tags, the glossary, metrics, verified queries, maintenance.', 'write', false, 3),
 ('admin',    'Lake admin',    'Every right: grants beyond a dataset''s owner, classifications and masks, row filters, retention, erasure, settings, the query limits, the audit of every query, keys, agents, break-glass reads. A super-admin holds this role.', 'admin', true, 4);

INSERT INTO dl_role_rights (role_key, right_key)
SELECT 'user', r FROM unnest(ARRAY['catalog.browse','data.read','queries.run','metrics.use','ask.use','access.request','sandbox.upload','follow.use','export.own','keys.own']) r
UNION ALL SELECT 'analyst', r FROM unnest(ARRAY['catalog.browse','data.read','queries.run','metrics.use','ask.use','access.request','sandbox.upload','follow.use','export.own','keys.own',
                                                'sql.editor','queries.save','sandbox.save','sandbox.transform','products.propose']) r
UNION ALL SELECT 'engineer', r FROM unnest(ARRAY['catalog.browse','data.read','queries.run','metrics.use','ask.use','access.request','sandbox.upload','follow.use','export.own','keys.own',
                                                 'sql.editor','queries.save','sandbox.save','sandbox.transform','products.propose',
                                                 'sources.manage','loads.manage','contracts.manage','promote.run','transforms.manage','checks.manage','incidents.manage',
                                                 'catalog.edit','metrics.manage','queries.verify','maintenance.run']) r
UNION ALL SELECT 'admin', right_key FROM dl_rights;

-- The roles a member holds, resolved: the kernel's list; a super-admin always holds admin; a member the kernel has not yet sent
-- roles for is read from the capability (admin = admin, anything else = user — there is no read-only role here: a Member reads only
-- what is granted to them anyway). Empty for nobody.
CREATE OR REPLACE FUNCTION dl_member_roles(p_member_id bigint) RETURNS text[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r text[];
BEGIN
    SELECT CASE
             WHEN m.member_kind = 'human' AND m.business_role = 'super_admin' THEN (SELECT array_agg(DISTINCT x) FROM unnest(m.roles || ARRAY['admin']) x)
             WHEN cardinality(m.roles) > 0 THEN m.roles
             ELSE ARRAY[CASE m.capability WHEN 'admin' THEN 'admin' ELSE 'user' END]
           END
      INTO r
      FROM members m
     WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN COALESCE(r, '{}');
END$$;

-- A right a given member's roles give (the member must be active and admitted). The query service asks it of the person an agent
-- acts for in a chat turn (K38), so the agent never reaches past what the person may (design §3 rule 8).
CREATE OR REPLACE FUNCTION dl_member_has_right(p_member_id bigint, p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM dl_role_rights rr
         WHERE rr.right_key = p_right AND rr.role_key = ANY (dl_member_roles(p_member_id)));
END$$;

-- The rule: a right the acting member's roles give.
CREATE OR REPLACE FUNCTION dl_has_right(p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN dl_member_has_right(app_current_member_id(), p_right);
END$$;

-- Runs the Data Lake: the super-admin, or a human holding settings.manage.
CREATE OR REPLACE FUNCTION dl_is_admin() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_super_admin() OR (app_member_kind() = 'human' AND dl_has_right('settings.manage'));
END$$;

-- Builds the lake: holds catalog.edit (a Data engineer or the admin).
CREATE OR REPLACE FUNCTION dl_is_engineer() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN dl_is_admin() OR dl_has_right('catalog.edit');
END$$;

-- Someone who belongs in the business here: admitted (any role; an agent granted a role too).
CREATE OR REPLACE FUNCTION dl_is_member_here() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_active_member();
END$$;

-- What app_roles answers, in one read.
CREATE OR REPLACE VIEW mcp_app_roles AS
SELECT r.role_key, r.name, r.description, r.capability, r.is_admin, r.sort_order,
       COALESCE((SELECT array_agg(rr.right_key ORDER BY h.sort_order) FROM dl_role_rights rr
                   JOIN dl_rights h ON h.right_key = rr.right_key WHERE rr.role_key = r.role_key), '{}') AS rights
FROM dl_roles r;

GRANT SELECT ON dl_rights, dl_roles, dl_role_rights, mcp_app_roles TO datalake_rw, datalake_records_ro;
GRANT EXECUTE ON FUNCTION dl_member_roles(bigint), dl_member_has_right(bigint, text), dl_has_right(text), dl_is_admin(), dl_is_engineer(), dl_is_member_here()
    TO datalake_rw, datalake_records_ro, datalake_activity_ro;

COMMIT;
