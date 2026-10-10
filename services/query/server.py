"""The query service's socket — a PROTOTYPE for Phase 0 (the systemd unit ``datalake-query@`` runs it in Phase 2).

One request per connection on a Unix socket owned by the application's group: one line of JSON
``{"policy": {...}, "sql": "...", "as_of": n|null, "params": {...}|null}`` in, one line of JSON (the answer of
``service.run``) out. A semaphore bounds the concurrent requests per process (the design's pool is several processes, each
under systemd's MemoryMax/CPUQuota/IPAddressDeny; a process that overruns is killed and replaced by systemd). The CALLER
— PHP's ``app/lake/client.php`` or the records MCP — has already authenticated the person and asked PostgreSQL's
``dl_query_policy()`` for the policy; the socket's permissions are what keep anything else out.

    services/.venv/bin/python -m query.server --socket /run/datalake/query.sock --workers 2
"""
from __future__ import annotations

import argparse
import json
import os
import socketserver
import threading

from lake.engine import LakeConfig
from query.service import run

MAX_REQUEST_BYTES = 1 << 20


class Handler(socketserver.StreamRequestHandler):
    def handle(self):
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
            ans = run(self.server.cfg, req.get("policy") or {}, req.get("sql") or "", as_of=req.get("as_of"),
                      params=req.get("params"))
        self._send(ans)

    def _send(self, obj):
        self.wfile.write((json.dumps(obj, separators=(",", ":"), default=str) + "\n").encode())


class Server(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True

    def __init__(self, path: str, cfg: LakeConfig, workers: int):
        if os.path.exists(path):
            os.unlink(path)
        super().__init__(path, Handler)
        os.chmod(path, 0o660)
        self.cfg = cfg
        self.slots = threading.BoundedSemaphore(workers)


def serve(path: str, workers: int = 2, cfg: LakeConfig | None = None) -> Server:
    return Server(path, cfg or LakeConfig.from_env(), workers)


def ask(path: str, request: dict, timeout: float = 120) -> dict:
    import socket
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
    ap = argparse.ArgumentParser()
    ap.add_argument("--socket", required=True)
    ap.add_argument("--workers", type=int, default=2)
    a = ap.parse_args()
    srv = serve(a.socket, a.workers)
    try:
        srv.serve_forever()
    finally:
        srv.server_close()
