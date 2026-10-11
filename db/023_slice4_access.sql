-- 023: slice 4 — access, policies, groups and personal data (docs/build-specs/access-policies.md). Additive: the walls stay db/008's,
-- db/018's and db/022's; this file adds what the screens and the worker's `expiry` pass need to SAY who and why, never a new rule.
--
-- 1. notifications.kind admits `grant_expiring`: the worker's expiry pass tells a grantee and the dataset's owner, once, that a grant
--    ends within seven days (deduped per grant and person by the pass itself — dl_notify's own dedupe holds ten minutes, and a kind no
--    one mails has no outbox row to dedupe on).
-- 2. dl_members_with_right(right): the active people holding a right here — who decides a request on a dataset with no owner (the holders
--    of grants.manage, access-and-policies §4), who hears of a break-glass besides the owner (every other Lake admin). The worker reads it
--    with no member set, so it is a function, not a view (mcp_members answers nobody outside a session).
-- 3. dl_access_deciders(dataset): the owner, else the holders of grants.manage — the ONE answer to "whom does a request go to".
-- 4. dl_grant_via(grant): the words for what a grant reaches through — "you", "Sales (your department)", "the group Finance analysts",
--    "everyone inside" — answered only to someone the grant reaches, its dataset's owner or a holder of grants.manage (My access names
--    the grant that gives each level; mcp_grants shows a Member none of the department grants that reach them, by design).
-- 5. dl_access_request_expire(now): pending requests past wanted_until or older than 30 days → expired (the worker's pass); returns the
--    rows it expired so the pass logs and tells each requester.
BEGIN;

ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check;
ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check
    CHECK (kind IN ('check_failed', 'incident_assigned', 'freshness_missed', 'schema_changed', 'access_requested', 'access_decided',
                    'certification_lost', 'deprecated', 'export_ready', 'load_failed', 'proposal', 'steward_note', 'admin_read',
                    'erasure_done', 'mention', 'digest', 'policy_fault', 'grant_expiring'));

CREATE OR REPLACE FUNCTION dl_members_with_right(p_right text) RETURNS SETOF bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT m.id FROM members m
     WHERE m.status = 'active' AND m.member_kind = 'human' AND NOT m.is_external AND dl_member_has_right(m.id, p_right)
     ORDER BY m.id;
$$;

CREATE OR REPLACE FUNCTION dl_access_deciders(p_dataset_id bigint) RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE o bigint;
BEGIN
    SELECT d.owner_member_id INTO o FROM datasets d JOIN members m ON m.id = d.owner_member_id AND m.status = 'active' WHERE d.id = p_dataset_id;
    IF o IS NOT NULL THEN RETURN NEXT o; RETURN; END IF;
    RETURN QUERY SELECT dl_members_with_right('grants.manage');
END$$;

CREATE OR REPLACE FUNCTION dl_grant_via(p_grant_id bigint) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE g grants%ROWTYPE; me bigint := app_current_member_id(); reaches boolean;
BEGIN
    SELECT * INTO g FROM grants WHERE id = p_grant_id;
    IF g.id IS NULL OR me IS NULL THEN RETURN NULL; END IF;
    reaches := EXISTS (SELECT 1 FROM dl_principals_of(me) p WHERE p.kind = g.principal_kind AND (g.principal_kind = 'everyone' OR p.id = g.principal_id));
    IF NOT (reaches OR dl_has_right('grants.manage')
            OR EXISTS (SELECT 1 FROM datasets d WHERE d.id = g.dataset_id AND d.owner_member_id = me)) THEN
        RETURN NULL;
    END IF;
    RETURN CASE g.principal_kind
        WHEN 'member' THEN CASE WHEN g.principal_id = me THEN 'you' ELSE (SELECT display_name FROM members WHERE id = g.principal_id) END
        WHEN 'agent' THEN CASE WHEN g.principal_id = me THEN 'you' ELSE (SELECT display_name FROM members WHERE id = g.principal_id) END
        WHEN 'department' THEN (SELECT name FROM departments WHERE id = g.principal_id) || CASE WHEN reaches THEN ' (your department)' ELSE '' END
        WHEN 'group' THEN 'the group ' || (SELECT name FROM groups WHERE id = g.principal_id)
        WHEN 'application' THEN 'the application ' || g.principal_ref
        ELSE 'everyone inside' END;
END$$;

CREATE OR REPLACE FUNCTION dl_access_request_expire(p_now timestamptz DEFAULT now(), p_limit integer DEFAULT 200)
    RETURNS TABLE (request_id bigint, requester_id bigint, dataset_id bigint, level text, why text)
    LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
    WITH due AS (
        SELECT r.id, CASE WHEN r.wanted_until IS NOT NULL AND r.wanted_until < p_now::date THEN 'wanted_until' ELSE 'thirty_days' END AS why
          FROM access_requests r
         WHERE r.status = 'pending' AND ((r.wanted_until IS NOT NULL AND r.wanted_until < p_now::date) OR r.created_at < p_now - interval '30 days')
         ORDER BY r.id LIMIT GREATEST(1, LEAST(p_limit, 1000)) FOR UPDATE SKIP LOCKED),
    done AS (UPDATE access_requests r SET status = 'expired' FROM due WHERE r.id = due.id RETURNING r.id, r.requester_id, r.dataset_id, r.level, due.why)
    SELECT id, requester_id, dataset_id, level, why FROM done ORDER BY id;
$$;

REVOKE ALL ON FUNCTION dl_members_with_right(text), dl_access_deciders(bigint), dl_grant_via(bigint), dl_access_request_expire(timestamptz, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_members_with_right(text), dl_access_deciders(bigint), dl_grant_via(bigint), dl_access_request_expire(timestamptz, integer) TO datalake_rw;
GRANT EXECUTE ON FUNCTION dl_grant_via(bigint) TO datalake_records_ro;

COMMIT;
