"""The query service's own identity (query-service.md §3, §6): ONE PostgreSQL connection per instance as `datalake_query` (DL_QUERY_DB_DSN,
a passfile — never a password), through which the service

* COMPILES THE POLICY ITSELF from the caller's identity — `dl_query_policy(caller, on_behalf_of, keys, source, ceiling)` (db/018) — and adds
  the masking salt from DL_MASK_SALT_FILE (read once at start, never logged, never in an answer); a caller can forge nothing;
* resolves an as-of time to the lake's snapshot at or before it (`ducklake.ducklake_snapshot`, which the role may read);
* writes the AUDIT row of every request it answers — ok, refused, timed out or failed — with `dl_query_run_log(json)` (db/011): the id is
  the answer's `query_run_id`. Never in it: a row value, the salt, a DSN, a path.

The instance answers one request at a time, so one connection serves it; a broken connection is opened again on the next request.
"""
from __future__ import annotations

import hashlib
import json
import os
import re
import threading

import psycopg
from psycopg.rows import dict_row
from psycopg.types.json import Json

from lake.engine import LakeConfig


class Identity:
    def __init__(self, cfg: LakeConfig, worker: int | str = "1"):
        self.cfg = cfg
        self.worker = str(worker)
        self._pg: psycopg.Connection | None = None
        self._lock = threading.Lock()
        self.salt_hex = self._salt()

    @staticmethod
    def _salt() -> str:
        path = os.environ.get("DL_MASK_SALT_FILE", "")
        if not path:
            raise RuntimeError("DL_MASK_SALT_FILE is not set")
        with open(path, "r", encoding="ascii") as f:
            salt = f.read().strip().lower()
        if not re.fullmatch(r"[0-9a-f]{32,128}", salt):
            raise RuntimeError("the masking salt is not 32–128 hex characters")
        return salt

    def pg(self) -> psycopg.Connection:
        if self._pg is None or self._pg.closed or self._pg.broken:
            self._pg = psycopg.connect(self.cfg.query_dsn + f" application_name=datalake-query-{self.worker}", autocommit=True,
                                       row_factory=dict_row)
        return self._pg

    def _one(self, sql: str, args: tuple = ()) -> dict | None:
        with self._lock:
            try:
                return self.pg().execute(sql, args).fetchone()
            except psycopg.OperationalError:
                self._pg = None                                    # the server went away: one more try on a fresh connection
                return self.pg().execute(sql, args).fetchone()

    def policy(self, req, keys: list[str]) -> dict:
        """The caller's policy for these keys, compiled by the database (the one place the walls are compiled), plus the salt."""
        row = self._one("SELECT dl_query_policy(%s, %s, %s, %s, %s) AS p",
                        (req.caller_member_id, req.on_behalf_of, keys, req.source, req.ceiling))
        p = dict(row["p"])
        lim = dict(p.get("limits") or {})
        if lim.get("temp_mb") is not None:
            lim["temp"] = f"{int(lim['temp_mb'])}MB"
        p["limits"] = lim
        p["salt_hex"] = self.salt_hex
        return p

    def admit(self, req) -> dict:
        """The rate and the concurrency (query-service §4, db/022): {admitted, slot} or {admitted: false, rule}. The slot is a SESSION
        advisory lock on this instance's connection, held for the run — release() it after the audit, whatever the outcome."""
        row = self._one("SELECT dl_query_admit(%s, %s, %s) AS a", (req.caller_member_id, req.source, req.request_id))
        return dict(row["a"]) if row and row["a"] else {"admitted": True, "slot": None}

    def release(self, req, admitted: dict) -> None:
        if admitted.get("slot") is None:
            return
        try:
            self._one("SELECT dl_query_release(%s, %s) AS r", (req.caller_member_id, int(admitted["slot"])))
        except psycopg.Error:
            self._pg = None                                    # a broken connection released every lock it held

    def policy_fault(self, key: str | None, words: str | None) -> None:
        """A compiled policy the service could not honour: the Lake admins hear of it once a day per dataset (db/022)."""
        if not key:
            return
        try:
            self._one("SELECT dl_query_policy_fault(%s, %s) AS n", (key, (words or "")[:200]))
        except psycopg.Error:
            pass

    def current_snapshot(self) -> int | None:
        row = self._one("SELECT max(snapshot_id) AS s FROM ducklake.ducklake_snapshot")
        return int(row["s"]) if row and row["s"] is not None else None

    def resolve_as_of(self, as_of) -> int | None:
        """A snapshot number as given; an ISO time → the latest snapshot at or before it (None when the lake had none then)."""
        if as_of is None or as_of == "":
            return None
        if isinstance(as_of, int):
            return as_of
        row = self._one("SELECT max(snapshot_id) AS s FROM ducklake.ducklake_snapshot WHERE snapshot_time <= %s::timestamptz", (as_of,))
        return int(row["s"]) if row and row["s"] is not None else -1

    def audit(self, req, answer: dict, *, sql: str, datasets: list[str], policy: dict | None) -> int:
        """The query_runs row (db/011) — whatever the outcome. Returns its id."""
        masks: dict = {}
        filters: dict = {}
        used = set(datasets)
        for key, d in ((policy or {}).get("datasets") or {}).items():
            if key not in used:
                continue
            if d.get("masks"):
                masks[key] = d["masks"]
            if d.get("row_filter"):
                filters[key] = d["row_filter"]
        rows = answer.get("rows") or []
        payload = {
            "caller": req.caller_member_id, "on_behalf_of": req.on_behalf_of, "agent_run_id": req.agent_run_id, "request_id": req.request_id,
            "source": req.source, "consumer_app": req.consumer_app, "key_label": req.key_label,
            "saved_query_id": req.saved_query_id, "metric_id": (req.raw.get("metric") or {}).get("id") if isinstance(req.raw.get("metric"), dict) else None,
            "sql_sha256": hashlib.sha256(sql.encode()).hexdigest(), "sql": sql, "as_of": None if req.as_of is None else str(req.as_of),
            "datasets": sorted(used), "masked_columns": masks, "row_filters": filters, "rows_returned": len(rows),
            "truncated": bool(answer.get("truncated")), "bytes_returned": len(json.dumps(rows, separators=(",", ":"), default=str)) if rows else 0,
            "ms": int(answer.get("ms") or 0), "status": answer.get("status") if answer.get("status") in ("ok", "refused", "timeout", "error", "cancelled") else "error",
            "refusal_rule": answer.get("refusal_rule"), "error": (answer.get("message") or None) if answer.get("status") in ("error", "timeout") else None,
            "worker": self.worker,
        }
        row = self._one("SELECT dl_query_run_log(%s::jsonb) AS id", (Json(payload),))
        return int(row["id"])
