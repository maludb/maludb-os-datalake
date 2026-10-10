"""Sniffing a landed file into a PROPOSAL a person confirms (design §6.3: inference proposes, a person confirms, nothing is
silently dropped). Never writes the lake. The original stays byte-for-byte in landing; when a CSV is not UTF-8 a transcoded
working copy is made beside the spill (DuckDB reads UTF-8, UTF-16 and Latin-1 only — Windows-1252's € and curly quotes are
not Latin-1).

    sniff(path) → {"format", "encoding", "delimiter", "header", "sheets"|None, "columns": [{name, source_name, proposed_type,
                   sample}], "rows_estimate", "warnings", "read_options"}
"""
from __future__ import annotations

import hashlib
import os
import re
import tempfile

import duckdb
import pyarrow.parquet as pq
from python_calamine import CalamineWorkbook

CSV_EXT = {".csv", ".tsv", ".txt"}
WORKBOOK_EXT = {".xlsx", ".xlsm", ".xls", ".ods"}
JSON_EXT = {".json", ".ndjson", ".jsonl"}
PARQUET_EXT = {".parquet"}
SAMPLE_ROWS = 50


def detect_format(path: str) -> str:
    ext = os.path.splitext(path)[1].lower()
    with open(path, "rb") as f:
        head = f.read(8)
    if head[:4] == b"PAR1":
        return "parquet"
    if head[:4] == b"PK\x03\x04" and ext in WORKBOOK_EXT | {""}:
        return "workbook"
    if head[:8] == b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1":
        return "workbook"  # legacy .xls (OLE2)
    if ext in WORKBOOK_EXT:
        return "workbook"
    if ext in JSON_EXT:
        return "json"
    if ext in PARQUET_EXT:
        return "parquet"
    return "csv"


def sha256_file(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def normalise_name(name: str, seen: set[str]) -> str:
    """A source column name → a lake identifier (lower snake, ≤ 63, unique within the dataset)."""
    n = re.sub(r"[^0-9a-zA-Z]+", "_", (name or "").strip()).strip("_").lower() or "column"
    if not n[0].isalpha():
        n = "c_" + n
    n = n[:63]
    base, i = n, 2
    while n in seen or n.startswith("_dl_"):
        n = f"{base[:58]}_{i}"
        i += 1
    seen.add(n)
    return n


def encoding_of(path: str, probe_bytes: int = 4 << 20) -> str:
    with open(path, "rb") as f:
        data = f.read(probe_bytes)
    if data.startswith(b"\xff\xfe") or data.startswith(b"\xfe\xff"):
        return "utf-16"
    try:
        data.decode("utf-8")
        return "utf-8"
    except UnicodeDecodeError as e:
        if e.start > len(data) - 4:   # a multi-byte character cut by the probe's end
            return "utf-8"
        return "cp1252"


def utf8_working_copy(path: str, encoding: str, work_dir: str) -> str:
    """A transcoded copy for DuckDB; the landed original is never touched."""
    if encoding in ("utf-8", "utf-16"):
        return path
    os.makedirs(work_dir, exist_ok=True)
    fd, out = tempfile.mkstemp(prefix="sniff-", suffix=".csv", dir=work_dir)
    with os.fdopen(fd, "w", encoding="utf-8", newline="") as w, open(path, "r", encoding=encoding, errors="replace", newline="") as r:
        for chunk in iter(lambda: r.read(1 << 20), ""):
            w.write(chunk)
    return out


def guess_delimiter(path: str) -> str:
    with open(path, "r", encoding="utf-8", errors="replace") as f:
        first = f.readline()
    counts = {d: first.count(d) for d in (",", ";", "\t", "|")}
    best = max(counts, key=counts.get)
    return best if counts[best] > 0 else ","


def sniff(path: str, work_dir: str | None = None, con: duckdb.DuckDBPyConnection | None = None) -> dict:
    con = con or duckdb.connect(config={"memory_limit": "1GB", "threads": 2, "autoinstall_known_extensions": False,
                                        "autoload_known_extensions": False})
    work_dir = work_dir or tempfile.gettempdir()
    fmt = detect_format(path)
    out: dict = {"format": fmt, "encoding": None, "delimiter": None, "header": True, "sheets": None, "columns": [],
                 "rows_estimate": None, "warnings": [], "read_options": {}}
    seen: set[str] = set()
    if fmt == "csv":
        enc = encoding_of(path)
        out["encoding"] = enc
        if enc == "cp1252":
            out["warnings"].append("not UTF-8: read as Windows-1252 and transcoded for loading")
        work = utf8_working_copy(path, enc, work_dir)
        delim = guess_delimiter(work)
        # The header line's delimiter is the hint: DuckDB's sniffer alone was fooled by one malformed row (FINDINGS §3).
        r = con.execute("SELECT Delimiter, Quote, Escape, HasHeader, Columns FROM sniff_csv(?, delim = ?, ignore_errors = true, "
                        "sample_size = -1)", [work, delim]).fetchone()
        out["delimiter"], out["header"] = r[0], bool(r[3])
        blank = lambda v: None if v in (None, "", "(empty)") else v
        out["read_options"] = {"delim": r[0], "quote": blank(r[1]), "escape": blank(r[2]), "header": bool(r[3]), "encoding": enc}
        rows = con.execute(f"SELECT * FROM read_csv(?, delim = ?, header = ?, all_varchar = true, ignore_errors = true) "
                           f"LIMIT {SAMPLE_ROWS}", [work, r[0], bool(r[3])])
        names = [d[0] for d in rows.description]
        sample = rows.fetchall()
        types = {c["name"]: c["type"] for c in r[4]}
        for i, n in enumerate(names):
            out["columns"].append({"source_name": n, "name": normalise_name(n, seen), "proposed_type": types.get(n, "VARCHAR"),
                                   "sample": [s[i] for s in sample[:5]]})
        out["rows_estimate"] = con.execute("SELECT count(*) FROM read_csv(?, delim = ?, header = ?, all_varchar = true, "
                                           "ignore_errors = true)", [work, r[0], bool(r[3])]).fetchone()[0]
        if work != path:
            os.unlink(work)
    elif fmt == "workbook":
        wb = CalamineWorkbook.from_path(path)
        out["sheets"] = []
        for name in wb.sheet_names:
            rows = wb.get_sheet_by_name(name).to_python(skip_empty_area=True)
            header_rows = header_depth(rows)
            header = combine_header(rows, header_rows)
            sheet_seen: set[str] = set()
            cols = [{"source_name": h, "name": normalise_name(h, sheet_seen), "proposed_type": propose_type(
                [r[i] for r in rows[header_rows:header_rows + 200] if i < len(r)]),
                "sample": [str(r[i]) if i < len(r) else None for r in rows[header_rows:header_rows + 5]]}
                for i, h in enumerate(header)]
            sheet = {"name": name, "header_rows": header_rows, "rows_estimate": max(0, len(rows) - header_rows), "columns": cols}
            if header_rows > 1:
                sheet["warning"] = "a merged or multi-row header: names proposed by joining the header rows — confirm them"
            out["sheets"].append(sheet)
        first = out["sheets"][0] if out["sheets"] else {"columns": [], "rows_estimate": 0}
        out["columns"], out["rows_estimate"] = first["columns"], first["rows_estimate"]
    elif fmt == "json":
        rel = con.execute("SELECT * FROM read_json_auto(?, sample_size = -1) LIMIT ?", [path, SAMPLE_ROWS])
        names = [d[0] for d in rel.description]
        sample = rel.fetchall()
        typs = con.execute("SELECT column_name, column_type FROM (DESCRIBE SELECT * FROM read_json_auto(?, sample_size = -1))",
                           [path]).fetchall()
        tmap = dict(typs)
        for i, n in enumerate(names):
            out["columns"].append({"source_name": n, "name": normalise_name(n, seen), "proposed_type": tmap.get(n, "VARCHAR"),
                                   "sample": [None if s[i] is None else str(s[i]) for s in sample[:5]]})
        out["rows_estimate"] = con.execute("SELECT count(*) FROM read_json_auto(?)", [path]).fetchone()[0]
    else:
        pf = pq.ParquetFile(path)
        for f in pf.schema_arrow:
            out["columns"].append({"source_name": f.name, "name": normalise_name(f.name, seen), "proposed_type": str(f.type),
                                   "sample": []})
        out["rows_estimate"] = pf.metadata.num_rows
    for c in out["columns"] + [c for sh in (out["sheets"] or []) for c in sh["columns"]]:
        keep_text_if_identifier(c)
    return out


def keep_text_if_identifier(col: dict) -> None:
    """A numeric-looking identifier stays text: leading zeros (customer 00042) or 12+ digits (an account or card number) are
    lost or mangled by a numeric type — the proposal says why."""
    if col["proposed_type"].upper() not in ("BIGINT", "INTEGER", "HUGEINT", "DOUBLE", "INT64", "DOUBLE PRECISION"):
        return
    vals = [str(v) for v in col.get("sample") or [] if v not in (None, "")]
    if any(re.fullmatch(r"0\d+", v) or re.fullmatch(r"\d{12,}", v) for v in vals):
        col["proposed_type"] = "VARCHAR"
        col["note"] = "looks like an identifier (leading zeros or 12+ digits): kept as text"


def header_depth(rows: list[list]) -> int:
    """1, or 2 when the first row looks like a merged group header over a full second header row (calamine returns a merged
    cell's value in its first cell and empties in the rest)."""
    if len(rows) < 3:
        return 1
    r0, r1 = rows[0], rows[1]
    gaps = sum(1 for v in r0 if v in (None, ""))
    r1_text = all(isinstance(v, str) and v.strip() for v in r1[1:]) if len(r1) > 1 else False
    return 2 if gaps > 0 and r1_text else 1


def combine_header(rows: list[list], depth: int) -> list[str]:
    if not rows:
        return []
    width = max(len(r) for r in rows[:depth + 5])
    if depth == 1:
        return [str(v) if v not in (None, "") else f"column_{i + 1}" for i, v in enumerate(rows[0] + [None] * (width - len(rows[0])))]
    top = rows[0] + [None] * (width - len(rows[0]))
    filled, last = [], None
    for v in top:
        last = v if v not in (None, "") else last
        filled.append(last)
    sub = rows[1] + [None] * (width - len(rows[1]))
    return [" ".join(str(p) for p in (t, s) if p not in (None, "")) or f"column_{i + 1}" for i, (t, s) in enumerate(zip(filled, sub))]


def propose_type(values: list) -> str:
    vals = [v for v in values if v not in (None, "")]
    if not vals:
        return "VARCHAR"
    import datetime as _dt
    if all(isinstance(v, bool) for v in vals):
        return "BOOLEAN"
    if all(isinstance(v, (int, float)) and not isinstance(v, bool) for v in vals):
        return "BIGINT" if all(float(v).is_integer() for v in vals) else "DOUBLE"
    if all(isinstance(v, (_dt.date, _dt.datetime)) for v in vals):
        return "TIMESTAMP" if any(isinstance(v, _dt.datetime) and (v.hour or v.minute or v.second) for v in vals) else "DATE"
    return "VARCHAR"
