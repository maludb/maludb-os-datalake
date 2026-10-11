"""The query service's socket server (design §6.5; docs/build-specs/query-service.md §1, §2, §7).

    services/.venv/bin/python services/query/server.py --worker N        (the unit datalake-query@N.service)

Instance N listens on the Unix socket `<DL_QUERY_SOCKET>-N.sock` (DL_QUERY_SOCKET is a PREFIX: /run/datalake/query in production),
mode 0660, group www-data when the service may set it — the socket's group is the first wall; on accept the server reads SO_PEERCRED and
refuses any uid but www-data's (and its own) — the second (`refusal_rule: peer`). No TCP listener exists. One request per connection:
one line of UTF-8 JSON in (≤ 1 MB, else `too_long`), one line of JSON out. ONE request at a time per instance: another arriving meanwhile
is answered `busy` at once (never queued — the client tries the next instance).

The kinds, by the slice that builds them (query-service §7): `ping` (Phase 2 — the health check and the shell's engine badge: {status ok,
worker, version}, touches no catalog and answers even while the instance is busy) · `preview` (slice 2) · `sql`, `saved_query`, `cancel`,
`dry_run` (slice 3) · `check_row_filter` (slice 4) · `metric` (slice 7) · `product_rows` (slice 9). Until its slice lands a kind is refused
`not_built`. THE SERVICE COMPILES THE POLICY ITSELF from the caller's identity (query-service §3, decision 9) — a caller never sends one.

THE PRODUCTION PATH (slice 2 — uploads-loads.md, THE FIRST EXEMPLAR): a line → `protocol.parse()` (the shape; `bad_request` / `too_long`)
→ `handle()` → the kind's handler in BUILT, which names the dataset keys it touches, asks `identity.policy()` for the caller's compiled policy,
answers a key under `refused` in the policy's words, runs `service.run()` (the allow-list, the caller's views with masks and row filters, the
lock, the watchdog), and writes the audit row with `identity.audit()` WHATEVER the outcome — the answer's `query_run_id`. A kind a later slice
adds copies `_preview`.

`serve()` / `ask()` below are the Phase 0 PROTOTYPE the engine proof (tests/engine/proof_query.py) drives in-process with a policy it
builds itself; they are never reachable from the command line or the unit, and slice 3 replaces them with the real request path.
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
from query import protocol, service  # noqa: E402
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
            self._send({"status": "ok", "worker": srv.worker, "version": duckdb.__version__, "kinds": sorted(["ping", *BUILT])})
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


BUILT.update({"preview": _preview})


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
