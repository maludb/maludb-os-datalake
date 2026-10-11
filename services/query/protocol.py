"""The query service's request (query-service.md §2): one line of UTF-8 JSON in, its shape checked here before anything else is touched.

    parse(line) -> Request   (raises ProtocolError(rule, message) — `bad_request` or `too_long`; the server answers it and audits nothing:
                              a line that is not a request names no caller)

The kinds, by the slice that builds them (query-service §7): `ping` (Phase 2), `preview` (slice 2), `sql`, `saved_query`, `cancel` (slice 3),
`check_row_filter` (slice 4), `metric` (slice 7 — `metric.py` compiles it to the SELECT `sql` runs), `product_rows` (slice 9). A kind a slice adds is a name in KINDS, its fields checked in
`_check`, and a handler in server.BUILT. THE CALLER NEVER SENDS A POLICY: identity.policy() compiles it from these fields.
"""
from __future__ import annotations

import json
import re
from dataclasses import dataclass, field

MAX_REQUEST_BYTES = 1 << 20
KINDS = ("ping", "preview", "sql", "saved_query", "metric", "product_rows", "cancel", "check_row_filter")
SOURCES = ("screen", "preview", "mcp", "agent", "api", "k7", "export", "transform")
PARAM_RE = re.compile(r"^[A-Za-z_][A-Za-z0-9_]{0,62}$")
KEY_RE = re.compile(r"^(raw|curated|product|sandbox|quarantine)\.[a-z][a-z0-9_]{0,62}$")


class ProtocolError(Exception):
    def __init__(self, rule: str, message: str):
        super().__init__(message)
        self.rule, self.message = rule, message


@dataclass
class Request:
    kind: str
    caller_member_id: int | None
    on_behalf_of: int | None = None
    ceiling: str | None = None
    agent_run_id: int | None = None
    request_id: str | None = None
    source: str = "screen"
    consumer_app: str | None = None
    key_label: str | None = None
    dataset: str | None = None
    as_of: int | str | None = None
    sql: str | None = None
    params: dict = field(default_factory=dict)
    dry_run: bool = False
    saved_query_id: int | None = None
    raw: dict = field(default_factory=dict)


def _int(v, name: str) -> int | None:
    if v is None or v == "":
        return None
    if isinstance(v, bool) or not isinstance(v, (int, str)) or (isinstance(v, str) and not v.isdigit()):
        raise ProtocolError("bad_request", f"{name} is a number")
    return int(v)


def parse(line: bytes) -> Request:
    if len(line) > MAX_REQUEST_BYTES:
        raise ProtocolError("too_long", "The request is over 1 MB.")
    try:
        d = json.loads(line)
    except Exception:
        raise ProtocolError("bad_request", "The request is not one line of JSON.")
    if not isinstance(d, dict):
        raise ProtocolError("bad_request", "The request is not one line of JSON.")
    kind = d.get("kind")
    if kind not in KINDS:
        raise ProtocolError("bad_request", "Unknown request kind.")
    if kind != "ping" and d.get("v") != 1:
        raise ProtocolError("bad_request", "Unknown protocol version.")
    source = d.get("source") or "screen"
    if source not in SOURCES:
        raise ProtocolError("bad_request", "Unknown source.")
    ceiling = d.get("ceiling")
    if ceiling not in (None, "internal_unpersonal"):
        raise ProtocolError("bad_request", "Unknown ceiling.")
    rid = d.get("request_id")
    if rid is not None and (not isinstance(rid, str) or not re.fullmatch(r"[A-Za-z0-9._:-]{1,80}", rid)):
        raise ProtocolError("bad_request", "request_id is up to 80 letters, digits and ._:-")
    as_of = d.get("as_of")
    if as_of not in (None, "") and not (isinstance(as_of, int) and not isinstance(as_of, bool)) and not (
            isinstance(as_of, str) and (as_of.isdigit() or re.fullmatch(r"\d{4}-\d{2}-\d{2}([T ][0-9:.]+)?(Z|[+-]\d{2}:?\d{2})?", as_of))):
        raise ProtocolError("bad_request", "as_of is a snapshot number or an ISO 8601 time")
    req = Request(kind=kind, caller_member_id=_int(d.get("caller_member_id"), "caller_member_id"),
                  on_behalf_of=_int(d.get("on_behalf_of"), "on_behalf_of"), ceiling=ceiling,
                  agent_run_id=_int(d.get("agent_run_id"), "agent_run_id"), request_id=rid, source=source,
                  consumer_app=d.get("consumer_app") if isinstance(d.get("consumer_app"), str) else None,
                  key_label=(d.get("key_label") or None) if isinstance(d.get("key_label"), (str, type(None))) else None,
                  dataset=d.get("dataset") if isinstance(d.get("dataset"), str) else None,
                  as_of=(int(as_of) if isinstance(as_of, str) and as_of.isdigit() else (as_of or None)),
                  sql=d.get("sql") if isinstance(d.get("sql"), str) else None,
                  params=d.get("params") if isinstance(d.get("params"), dict) else {}, dry_run=d.get("dry_run") is True,
                  saved_query_id=_int(d.get("saved_query_id"), "saved_query_id"), raw=d)
    _check(req)
    return req


def _check(req: Request) -> None:
    """A kind's own fields — each slice adds its branch with its kind. A kind not built yet is answered `not_built` by the server."""
    if req.kind == "preview":
        if req.caller_member_id is None:
            raise ProtocolError("bad_request", "Who asks: a preview names its caller_member_id.")
        if not req.dataset or not KEY_RE.fullmatch(req.dataset):
            raise ProtocolError("bad_request", "A preview names one dataset key: <zone>.<name>.")
        q = req.raw.get("quarantine", False)
        if not isinstance(q, bool):
            raise ProtocolError("bad_request", "quarantine is true or false.")
        if q:                                                     # slice 5: a curated dataset's quarantine (contracts-quality divergence 4)
            if not req.dataset.startswith("curated."):
                raise ProtocolError("bad_request", "A quarantine is a curated dataset's: name curated.<name>.")
            reason = req.raw.get("reason")
            if reason is not None and (not isinstance(reason, str) or not 1 <= len(reason) <= 200):
                raise ProtocolError("bad_request", "reason is up to 200 characters.")
            offset = req.raw.get("offset", 0)
            if isinstance(offset, bool) or not isinstance(offset, int) or not 0 <= offset <= 1_000_000:
                raise ProtocolError("bad_request", "offset is a number of rows from 0.")
    elif req.kind in ("sql", "saved_query"):
        if req.caller_member_id is None:
            raise ProtocolError("bad_request", "Who asks: a query names its caller_member_id.")
        if not isinstance(req.raw.get("sql"), str):
            raise ProtocolError("bad_request", "A query carries its SQL (the allow-list judges it).")
        if req.kind == "saved_query" and req.saved_query_id is None:
            raise ProtocolError("bad_request", "A saved query's run names its saved_query_id (the caller read it under its own wall).")
        if not isinstance(req.raw.get("params", {}), dict):
            raise ProtocolError("bad_request", "params is an object of name → value.")
        for name, value in req.params.items():
            if not isinstance(name, str) or not PARAM_RE.fullmatch(name):
                raise ProtocolError("bad_request", "A parameter's name is letters, digits and underscores.")
            if value is not None and not isinstance(value, (str, int, float, bool)):
                raise ProtocolError("bad_request", f"The parameter {name} is a text, a number, a yes/no or empty.")
            if isinstance(value, str) and len(value) > 1000:
                raise ProtocolError("bad_request", f"The parameter {name} is longer than 1,000 characters.")
    elif req.kind == "metric":
        # slice 7: the caller read the metric under its own wall and sends its definition; the SHAPE is checked here, the meaning (a grain the metric does
        # not allow, a dimension it does not have, an expression that is not one aggregate) is the service's `invalid_metric` (query-service §7)
        if req.caller_member_id is None:
            raise ProtocolError("bad_request", "Who asks: a metric's value names its caller_member_id.")
        if not isinstance(req.raw.get("metric"), dict):
            raise ProtocolError("bad_request", "A metric request carries the metric's definition (the caller read it under its own wall).")
        mid = req.raw["metric"].get("id")
        if mid is not None and (isinstance(mid, bool) or not isinstance(mid, int)):
            raise ProtocolError("bad_request", "The metric's id is a number.")
        if req.raw.get("grain") is not None and not isinstance(req.raw.get("grain"), str):
            raise ProtocolError("bad_request", "grain is a word: day, week, month, quarter or year.")
        for k in ("from", "to"):
            if req.raw.get(k) is not None and not isinstance(req.raw.get(k), str):
                raise ProtocolError("bad_request", f"{k} is a date.")
        if not isinstance(req.raw.get("by", []), list) or not isinstance(req.raw.get("where", {}), dict):
            raise ProtocolError("bad_request", "by is a list of dimensions and where an object of dimension → value.")
    elif req.kind == "check_row_filter":
        if req.caller_member_id is None:
            raise ProtocolError("bad_request", "Who asks: a row filter's check names its caller_member_id (the granter).")
        if not req.dataset or not KEY_RE.fullmatch(req.dataset):
            raise ProtocolError("bad_request", "A row filter's check names one dataset key: <zone>.<name>.")
        rf = req.raw.get("row_filter")
        if not isinstance(rf, str) or not 1 <= len(rf) <= 4000:
            raise ProtocolError("bad_request", "A row filter's check carries the filter bound for the granter (1 to 4,000 characters).")
        cols = req.raw.get("columns", [])
        if not isinstance(cols, list) or len(cols) > 2000 or not all(isinstance(c, str) and PARAM_RE.fullmatch(c) for c in cols):
            raise ProtocolError("bad_request", "columns is the catalog's list of the dataset's column names.")
    elif req.kind == "cancel":
        if req.caller_member_id is None or not req.request_id:
            raise ProtocolError("bad_request", "A cancel names its caller_member_id and the request_id of the caller's own running request.")

