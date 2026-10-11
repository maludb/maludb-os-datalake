"""The query service's socket server (design §6.5; docs/build-specs/query-service.md §1, §2, §7).

    services/.venv/bin/python services/query/server.py --worker N        (the unit datalake-query@N.service)

Instance N listens on the Unix socket `<DL_QUERY_SOCKET>-N.sock` (DL_QUERY_SOCKET is a PREFIX: /run/datalake/query in production),
mode 0660, group www-data when the service may set it — the socket's group is the first wall; on accept the server reads SO_PEERCRED and
refuses any uid but www-data's (and its own) — the second (`refusal_rule: peer`). No TCP listener exists. One request per connection:
one line of UTF-8 JSON in (≤ 1 MB, else `too_long`), one line of JSON out. ONE request at a time per instance: another arriving meanwhile
is answered `busy` at once (never queued — the client tries the next instance).

The kinds, by the slice that builds them (query-service §7): `ping` (Phase 2 — the health check and the shell's engine badge: {status ok,
worker, version}, touches no catalog and answers even while the instance is busy) · `preview` (slice 2) · `sql`, `saved_query`, `cancel`,
`dry_run` (slice 3 — `sql` with dry_run: true; a cancel is answered beside the running request, never `busy`) · `check_row_filter` (slice 4 — a grant's filter checked before it is saved; reads no row) · `metric` (slice 7) · `product_rows` (slice 9). Until its slice lands a kind is refused
`not_built`. THE SERVICE COMPILES THE POLICY ITSELF from the caller's identity (query-service §3, decision 9) — a caller never sends one.

THE PRODUCTION PATH (slice 2 — uploads-loads.md, THE FIRST EXEMPLAR): a line → `protocol.parse()` (the shape; `bad_request` / `too_long`)
→ `handle()` → the kind's handler in BUILT, which names the dataset keys it touches, asks `identity.policy()` for the caller's compiled policy,
answers a key under `refused` in the policy's words, runs `service.run()` (the allow-list, the caller's views with masks and row filters, the
lock, the watchdog), and writes the audit row with `identity.audit()` WHATEVER the outcome — the answer's `query_run_id`. A kind a later slice
adds copies `_preview`.

`serve()` / `ask()` below are the Phase 0 PROTOTYPE the engine proof (tests/engine/proof_query.py) drives in-process with a policy it
builds itself; they are never reachable from the command line or the unit — the real request path is `handle()` → BUILT (`_preview`, `_sql`).
"""
from __future__ import annotations

import argparse
import grp
import json
import os
import pwd
import signal
import socket
import socketserver
import struct
import sys
import threading

_HERE = os.path.dirname(os.path.abspath(__file__))
sys.path[:] = [p for p in sys.path if os.path.abspath(p or ".") != _HERE]
sys.path.insert(0, os.path.dirname(_HERE))

import duckdb  # noqa: E402

import time  # noqa: E402

from lake.engine import LakeConfig  # noqa: E402
from query import allowlist, protocol, service  # noqa: E402
from query.identity import Identity  # noqa: E402

MAX_REQUEST_BYTES = protocol.MAX_REQUEST_BYTES
PREVIEW_ROWS = 100

# Who builds each request kind (query-service §7). Not in BUILT → refused `not_built` with its slice.
KIND_SLICE = {"preview": "slice 2", "sql": "slice 3", "saved_query": "slice 3", "cancel": "slice 3", "check_row_filter": "slice 4",
              "metric": "slice 7", "product_rows": "slice 9"}
BUILT: dict = {}          # {kind: handler(server, request) -> dict}; slices 2 onward add theirs (filled below the handlers)


def _peer_uid(sock: socket.socket) -> int | None:
    try:
        creds = sock.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize("3i"))
        _pid, uid, _gid = struct.unpack("3i", creds)
        return uid
    except OSError:
        return None


def _allowed_uids() -> set[int]:
    uids = {os.getuid()}
    try:
        uids.add(pwd.getpwnam("www-data").pw_uid)
    except KeyError:
        pass
    return uids


class WorkerHandler(socketserver.StreamRequestHandler):
    def handle(self):
        srv: WorkerServer = self.server  # type: ignore[assignment]
        if _peer_uid(self.request) not in srv.allowed_uids:
            self._send({"status": "refused", "refusal_rule": "peer", "message": "Only the web application may ask the query service."})
            return
        line = self.rfile.readline(MAX_REQUEST_BYTES + 1)
        try:
            req = protocol.parse(line)
        except protocol.ProtocolError as e:
            self._send({"status": "refused", "refusal_rule": e.rule, "message": e.message})
            return
        if req.kind == "ping":                               # the health check: answered even while busy, no catalog touched
            self._send({"status": "ok", "worker": srv.worker, "version": duckdb.__version__, "kinds": sorted(["ping", "cancel", *BUILT])})
            return
        if req.kind == "cancel":                             # slice 3: answered beside the running request it interrupts (never `busy`)
            self._send(_cancel(srv, req))
            return
        if not srv.busy.acquire(blocking=False):
            self._send({"status": "refused", "refusal_rule": "busy", "message": "This instance is answering another request."})
            return
        try:
            self._send(handle(srv, req))
        finally:
            srv.busy.release()

    def _send(self, obj):
        try:
            self.wfile.write((json.dumps(obj, separators=(",", ":"), default=str) + "\n").encode())
        except (BrokenPipeError, ConnectionResetError):
            pass


class WorkerServer(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True

    def __init__(self, path: str, worker: int, cfg: LakeConfig):
        if os.path.exists(path):
            os.unlink(path)
        super().__init__(path, WorkerHandler)
        os.chmod(path, 0o660)
        try:                                                  # the socket's group is www-data where the service may set it (production)
            os.chown(path, -1, grp.getgrnam("www-data").gr_gid)
        except (KeyError, PermissionError):
            pass
        self.path = path
        self.worker = worker
        self.cfg = cfg
        self.busy = threading.Lock()
        self.running: dict[str, tuple[int | None, service.Control]] = {}     # request_id → (caller, its cancel) — one at a time, by design
        self.allowed_uids = _allowed_uids()
        self.identity = Identity(cfg, worker)


def handle(srv, req) -> dict:
    """One parsed request: its kind's handler, or `not_built` naming the slice that builds it. A fault in a handler is an `engine` error in
    words (never a traceback, a path or a value), audited when the handler got that far."""
    handler = BUILT.get(req.kind)
    if handler is None:
        where = KIND_SLICE.get(req.kind)
        return {"status": "refused", "refusal_rule": "not_built" if where else "bad_request",
                "message": f"not built until {where}" if where else "Unknown request kind."}
    try:
        return handler(srv, req)
    except Exception as e:  # pragma: no cover - a fault, logged on the instance's journal
        import traceback
        traceback.print_exc()
        return {"status": "error", "refusal_rule": "engine", "message": "the engine could not finish this read", "error_kind": type(e).__name__}


def _refused(rule: str, message: str, dataset: str | None = None) -> dict:
    return {"status": "refused", "refusal_rule": rule, "message": message, "dataset": dataset, "columns": [], "rows": [],
            "truncated": False, "masked_columns": [], "datasets": [], "ms": 0}


def _preview(srv, req) -> dict:
    """`preview` (query-service §7): `SELECT * FROM <dataset> LIMIT 100` as the caller may read it — the policy compiled for the one key,
    the views with masks and the row filter, an as-of snapshot when asked. A dataset with no data yet answers ok with nothing."""
    if req.raw.get("quarantine") is True:
        return _quarantine(srv, req)
    t0 = time.monotonic()
    key = req.dataset
    sql = f"SELECT * FROM {key} LIMIT {PREVIEW_ROWS}"
    ident: Identity = srv.identity
    policy = ident.policy(req, [key])
    if key in (policy.get("refused") or {}):
        r = policy["refused"][key]
        ans = _refused(r.get("rule") or "not_readable", r.get("message") or "you may not read this dataset", key)
    elif key not in (policy.get("datasets") or {}):
        ans = _refused("not_readable", "you may not read this dataset — request access", key)
    elif not (policy["datasets"][key].get("columns") or []):           # slice 4: registered, never loaded — no column yet: nothing to show, no refusal
        ans = {"status": "ok", "refusal_rule": None, "message": f"{key} has no data yet.", "columns": [], "rows": [], "truncated": False,
               "masked_columns": [], "datasets": [key], "ms": 0}
    else:
        as_of = ident.resolve_as_of(req.as_of)
        if as_of == -1:
            ans = _refused("absent_as_of", f"{key} did not exist then.", key)
        else:
            ans = service.run(srv.cfg, policy, sql, as_of=as_of)
            if ans.get("refusal_rule") in ("not_granted", "unknown_table"):
                if as_of is None:                              # granted, but nothing has landed in the lake yet
                    ans = {"status": "ok", "refusal_rule": None, "message": f"{key} has no data yet.", "columns": [], "rows": [],
                           "truncated": False, "masked_columns": [], "datasets": [key], "ms": ans.get("ms", 0)}
                else:
                    ans = _refused("absent_as_of", f"{key} did not exist then.", key)
            ans["as_of_snapshot"] = as_of if as_of is not None else ident.current_snapshot()
            if ans.get("status") != "ok":
                ans.setdefault("dataset", key)
    ans["ms"] = max(int(ans.get("ms") or 0), int((time.monotonic() - t0) * 1000))
    ans["query_run_id"] = ident.audit(req, ans, sql=sql, datasets=[key], policy=policy)
    ans.setdefault("next_offset", None)
    return ans


def _quarantine(srv, req) -> dict:
    """`preview` with `quarantine: true` (slice 5 — contracts-quality divergence 4): the rows a promotion of `curated.<name>` held back, as
    they arrived (text), newest first, 100 a page from `offset`, filtered to a `reason` when asked — read at WRITE level on the curated
    dataset, compiled by the database (dl_quarantine_policy), each source column masked as the curated column it feeds. With the page, the
    count per reason (a row may carry several). Audited like any read, under the curated key's quarantine."""
    t0 = time.monotonic()
    key = req.dataset
    name = key.split(".", 1)[1]
    qkey = f"quarantine.{name}"
    ident: Identity = srv.identity
    policy = ident.quarantine_policy(req, key)
    reason = req.raw.get("reason")
    offset = int(req.raw.get("offset") or 0)
    where = " WHERE list_contains(string_split(_dl_reason, ', '), $reason)" if reason else ""
    sql = f"SELECT * FROM {qkey}{where} ORDER BY _dl_quarantined_at DESC, _dl_load_id DESC, _dl_row LIMIT {PREVIEW_ROWS} OFFSET {offset}"
    counts: dict = {}
    if key in (policy.get("refused") or {}):
        r = policy["refused"][key]
        ans = _refused(r.get("rule") or "not_readable", r.get("message") or "you may not read this quarantine", key)
    else:
        ans = service.run(srv.cfg, policy, sql, params={"reason": reason} if reason else None)
        if ans.get("refusal_rule") in ("not_granted", "unknown_table"):     # nothing ever held back: no table yet
            ans = {"status": "ok", "refusal_rule": None, "message": f"nothing of {key} has been held back", "columns": [], "rows": [],
                   "truncated": False, "masked_columns": [], "datasets": [qkey], "ms": ans.get("ms", 0)}
        elif ans.get("status") == "ok":
            c = service.run(srv.cfg, policy, f"SELECT r, count(*) AS n FROM (SELECT unnest(string_split(_dl_reason, ', ')) AS r FROM {qkey}) "
                                             f"GROUP BY r ORDER BY n DESC, r")
            if c.get("status") == "ok":
                counts = {str(row[0]): int(row[1]) for row in c.get("rows") or []}
        if ans.get("status") != "ok":
            ans.setdefault("dataset", key)
    ans["reasons"] = counts
    ans["total"] = sum(counts.values()) if not reason else counts.get(reason, 0)
    ans["offset"] = offset
    ans["ms"] = max(int(ans.get("ms") or 0), int((time.monotonic() - t0) * 1000))
    ans["query_run_id"] = ident.audit(req, ans, sql=sql, datasets=[key, qkey], policy=policy)     # the curated key too: its readers' trail
    ans.setdefault("next_offset", offset + PREVIEW_ROWS if len(ans.get("rows") or []) == PREVIEW_ROWS else None)
    return ans


ADMIT_WORDS = {"rate": "too many queries this minute — try again in a moment",
               "concurrency": "too many queries at once — try again in a moment"}


def _sql(srv, req) -> dict:
    """`sql` and `saved_query` (slice 3 — query-service §7; THE SECOND EXEMPLAR): the caller's SQL as is, `params` bound as literals after
    the parse, `dry_run` = parse, allow-list, policy and EXPLAIN only (the plan, no rows — saving a query learns its datasets so). The order:
    the allow-list's first pass collects the keys (a refusal of the shape costs nothing and names no dataset) → the admission (an agent's rate,
    a person's concurrency — db/022's advisory slots, held for the run) → the policy compiled for those keys → the first key under `refused`
    answered in the policy's own words → the run under the watchdog, cancellable by the caller → the audit, whatever the outcome → the slot
    released. A `saved_query` is run exactly as `sql`; its id rides into the audit (the service never reads saved_queries)."""
    t0 = time.monotonic()
    ident: Identity = srv.identity
    sql = req.sql or ""
    control = service.Control()
    rid = req.request_id
    if rid:
        srv.running[rid] = (req.caller_member_id, control)
    policy = None
    keys: list[str] = []
    admitted: dict = {"admitted": True, "slot": None}
    try:
        try:
            keys = allowlist.keys_of(sql, table_functions=service._table_functions(srv.cfg), params=req.params)
        except allowlist.Refusal as r:
            ans = _refused(r.rule, r.message, r.dataset)
        else:
            admitted = ident.admit(req)
            if not admitted.get("admitted"):
                rule = admitted.get("rule") or "concurrency"
                ans = _refused(rule, ADMIT_WORDS.get(rule, ADMIT_WORDS["concurrency"]))
            else:
                policy = ident.policy(req, keys)
                refused = policy.get("refused") or {}
                readable = policy.get("datasets") or {}
                bad = next((k for k in keys if k in refused or k not in readable), None)
                empty = next((k for k in keys if not ((readable.get(k) or {}).get("columns") or [])), None)
                if bad is not None:
                    r = refused.get(bad) or {"rule": "not_readable", "message": f"you may not read {bad} — request access"}
                    ans = _refused(r.get("rule") or "not_readable", r.get("message") or "you may not read it — request access", bad)
                elif empty is not None:                                    # slice 4: a dataset registered and never loaded has no column yet
                    ans = {"status": "ok", "refusal_rule": None, "message": f"{empty} has no data yet.", "columns": [], "rows": [],
                           "truncated": False, "masked_columns": [], "datasets": keys, "ms": 0, "plan": None}
                else:
                    as_of = ident.resolve_as_of(req.as_of)
                    if as_of == -1:
                        ans = _refused("absent_as_of", "The lake held no data at that time.", keys[0] if keys else None)
                    else:
                        ans = service.run(srv.cfg, policy, sql, as_of=as_of, params=req.params, explain=req.dry_run, control=control)
                        if ans.get("refusal_rule") in ("not_granted", "unknown_table") and ans.get("datasets"):
                            empty = ans["datasets"][0]
                            if as_of is None:                  # granted, but nothing has landed in the lake yet: an answer, not a refusal
                                ans = {"status": "ok", "refusal_rule": None, "message": f"{empty} has no data yet.", "columns": [], "rows": [],
                                       "truncated": False, "masked_columns": [], "datasets": keys, "ms": ans.get("ms", 0), "plan": None}
                            else:
                                ans = _refused("absent_as_of", f"{empty} did not exist then.", empty)
                        if ans.get("refusal_rule") == "policy":
                            ident.policy_fault(keys[0] if keys else None, ans.get("message"))
                            ans["message"] = "the lake refused this read (its policy) — the Lake admin has been told"
                        ans["as_of_snapshot"] = as_of if as_of is not None else ident.current_snapshot()
        if ans.get("status") != "ok" and not ans.get("datasets") and keys:
            ans["datasets"] = [ans["dataset"]] if ans.get("dataset") else []
        if req.dry_run and ans.get("status") == "ok":
            ans["rows"], ans["columns"], ans["truncated"] = [], [], False
            ans["datasets"] = ans.get("datasets") or keys
        ans["ms"] = max(int(ans.get("ms") or 0), int((time.monotonic() - t0) * 1000))
        audited = ans.get("datasets") or ([ans["dataset"]] if ans.get("dataset") else [])
        ans["query_run_id"] = ident.audit(req, ans, sql=sql, datasets=audited, policy=policy)
    finally:
        if rid:
            srv.running.pop(rid, None)
        ident.release(req, admitted)
    ans.setdefault("next_offset", None)
    ans.setdefault("dataset", None)
    return ans


def _cancel(srv, req) -> dict:
    """`cancel` (slice 3): the caller's own running request on THIS instance is interrupted (the watchdog's interrupt(), so the run ends
    `cancelled` and is audited so); another caller's id answers `not_yours`; an id this instance is not running, `not_found` — the client
    asks every instance and keeps the best answer."""
    entry = srv.running.get(req.request_id or "")
    if entry is None:
        return {"status": "refused", "refusal_rule": "not_found", "message": "No request of yours with that id is running here.", "worker": srv.worker}
    caller, control = entry
    if caller != req.caller_member_id:
        return {"status": "refused", "refusal_rule": "not_yours", "message": "That request is not yours to cancel.", "worker": srv.worker}
    control.cancel()
    return {"status": "ok", "cancelled": True, "worker": srv.worker}


def _no_values(e: Exception) -> str:
    """An engine error in words without any quoted value: DuckDB names the value a cast failed on ('D-0001') — a value the granter may not read."""
    import re
    words = str(e).splitlines()[0].split(": ", 1)[-1]
    return re.sub(r"'[^']*'|\"[^\"]*\"", "…", words)[:200]


def _check_row_filter(srv, req) -> dict:
    """`check_row_filter` (slice 4 — query-service §7; access-policies.md divergence 1): a grant's row filter, its tokens bound by the database
    for the GRANTER (dl_bind_row_filter), validated node by node against the dataset's columns exactly as the views do at query time
    (policy.check_row_filter: columns, literals, comparisons, IN / ANY / BETWEEN / LIKE, AND / OR / NOT, CAST — no function, no subquery, no
    other table), then BOUND by DuckDB on the locked READ-ONLY attach (`EXPLAIN SELECT 1 FROM <table> WHERE <filter>` — a text column compared
    with a number refuses here, at the granter's save, never at a reader's first query), then RUN with LIMIT 1 under a 5-second interrupt (a cast
    that fails on the values refuses too); its answer is only valid or not — no row, no count leaves — so nothing is audited (it is no read);
    a dataset with no table in the lake yet is checked against the catalog's column names the caller sends. Answers
    {status: ok, valid, message?, row_filter?, checked_against: lake | catalog}."""
    import duckdb as _duckdb
    from lake.engine import columns_of, query_connection, split_key
    from query.policy import PolicyError, check_row_filter
    key = req.dataset or ""
    pred = str(req.raw.get("row_filter") or "")
    hint = {c for c in (req.raw.get("columns") or []) if isinstance(c, str)}
    con = None
    try:
        con, alias = query_connection(srv.cfg, memory="256MB", threads=1, temp_max="256MB")
        cols = columns_of(con, key, alias)
        against = "lake" if cols else "catalog"
        names = {c for c, _t in cols} or hint
        out = {"status": "ok", "dataset": key, "checked_against": against}
        if not names:
            return out | {"valid": False, "rule": "row_filter", "message": f"{key} has no columns yet — a row filter needs the dataset's columns"}
        try:
            bound = check_row_filter(pred, names)
        except PolicyError as e:
            return out | {"valid": False, "rule": "row_filter", "message": str(e)[:300]}
        if cols:
            zone, name = split_key(key)
            lake_dir = srv.cfg.data_path.rstrip("/") + "/"
            con.execute(f"SET allowed_directories = ['{lake_dir}']")
            con.execute("SET enable_external_access = false")
            con.execute("SET lock_configuration = true")
            try:
                con.execute(f'EXPLAIN SELECT 1 FROM {alias}."{zone}"."{name}" WHERE {bound}').fetchall()
            except _duckdb.Error as e:
                return out | {"valid": False, "rule": "row_filter", "message": f"the filter does not fit the dataset's column types ({_no_values(e)})"}
            # then RUN it, answering nothing but valid or not: a cast that fails on the data (a text key compared with a number) fails here,
            # never at a reader's first query; LIMIT 1 stops at the first kept row, and a scan longer than 5 s is interrupted and counts as checked
            timer = threading.Timer(5.0, con.interrupt)
            timer.start()
            try:
                con.execute(f'SELECT 1 FROM {alias}."{zone}"."{name}" WHERE {bound} LIMIT 1').fetchall()
            except _duckdb.InterruptException:
                pass
            except _duckdb.Error as e:
                return out | {"valid": False, "rule": "row_filter", "message": f"the filter fails on the dataset's values ({_no_values(e)})"}
            finally:
                timer.cancel()
        return out | {"valid": True, "row_filter": bound}
    finally:
        if con is not None:
            try:
                con.close()
            except Exception:
                pass


BUILT.update({"preview": _preview, "sql": _sql, "saved_query": _sql, "check_row_filter": _check_row_filter})


def socket_path(prefix: str, worker: int) -> str:
    return f"{prefix}-{worker}.sock"


def main() -> int:
    ap = argparse.ArgumentParser(description="One instance of the Data Lake's query service.")
    ap.add_argument("--worker", type=int, required=True, help="the instance number (the unit's %%i)")
    a = ap.parse_args()
    prefix = os.environ.get("DL_QUERY_SOCKET", "")
    if not prefix:
        print("query: DL_QUERY_SOCKET is not set", file=sys.stderr, flush=True)
        return 1
    try:
        cfg = LakeConfig.from_env()
    except RuntimeError as e:
        print(f"query: {e}", file=sys.stderr, flush=True)
        return 1
    path = socket_path(prefix, a.worker)
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    srv = WorkerServer(path, a.worker, cfg)
    signal.signal(signal.SIGTERM, lambda *_: threading.Thread(target=srv.shutdown, daemon=True).start())
    print(f"query: worker {a.worker} listening on {path}; kinds: ping{''.join(', ' + k for k in sorted(BUILT))}", flush=True)
    try:
        srv.serve_forever()
    finally:
        srv.server_close()
        try:
            os.unlink(path)
        except FileNotFoundError:
            pass
    return 0


# ---- the Phase 0 PROTOTYPE (tests/engine/proof_query.py only; never the unit's path) --------------------------------------------------
class _PrototypeHandler(socketserver.StreamRequestHandler):
    def handle(self):
        from query.service import run
        line = self.rfile.readline(MAX_REQUEST_BYTES + 1)
        if len(line) > MAX_REQUEST_BYTES:
            self._send({"status": "refused", "refusal_rule": "too_long", "message": "request over 1 MB"})
            return
        try:
            req = json.loads(line)
        except Exception:
            self._send({"status": "refused", "refusal_rule": "bad_request", "message": "not JSON"})
            return
        with self.server.slots:
            ans = run(self.server.cfg, req.get("policy") or {}, req.get("sql") or "", as_of=req.get("as_of"), params=req.get("params"))
        self._send(ans)

    def _send(self, obj):
        self.wfile.write((json.dumps(obj, separators=(",", ":"), default=str) + "\n").encode())


class _PrototypeServer(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True

    def __init__(self, path: str, cfg: LakeConfig, workers: int):
        if os.path.exists(path):
            os.unlink(path)
        super().__init__(path, _PrototypeHandler)
        os.chmod(path, 0o660)
        self.cfg = cfg
        self.slots = threading.BoundedSemaphore(workers)


def serve(path: str, workers: int = 2, cfg: LakeConfig | None = None) -> _PrototypeServer:
    """The engine proof's in-process server (a caller-built policy) — never the service's path."""
    return _PrototypeServer(path, cfg or LakeConfig.from_env(), workers)


def ask(path: str, request: dict, timeout: float = 120) -> dict:
    s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    s.settimeout(timeout)
    s.connect(path)
    s.sendall((json.dumps(request) + "\n").encode())
    buf = b""
    while not buf.endswith(b"\n"):
        chunk = s.recv(1 << 16)
        if not chunk:
            break
        buf += chunk
    s.close()
    return json.loads(buf)


if __name__ == "__main__":
    sys.exit(main())
