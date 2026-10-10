"""Loads into the raw zone, and their rollback (design §6.1, §6.3).

Raw is the lake's memory of what arrived: one table per source dataset, EVERY column VARCHAR as received, every row with its
provenance — ``_dl_load_id``, ``_dl_row`` (the row's place in the file: the sheet's row number for a workbook, Parquet's own
file row number + 1, the ordinal of the parsed data row for CSV and JSON), ``_dl_loaded_at``, ``_dl_landing_sha256``,
``_dl_rescued`` (a JSON object of fields that matched no known column) — append-only, replayable into curated at any time.

``load_raw`` STREAMS the file into DuckLake (CSV, JSON and Parquet are never materialised in memory — a 25-million-row file
loads under a 3 GB cap; FINDINGS §8) and is idempotent by (dataset, landing sha256): the same file again is a no-op that says
so. Rejected rows are returned with their file line, column, error and raw text (DuckDB's store_rejects) for
``load_rejects``. Schema drift is decided by the dataset's evolution mode: ``add`` (new columns added to raw, reported),
``rescue`` (new fields packed into ``_dl_rescued``, reported — the default), ``fail`` (nothing loaded, reported). A column
that stopped arriving is NULL and reported; nothing is ever dropped or retyped silently.
"""
from __future__ import annotations

import os
import tempfile
import time

import duckdb
import pyarrow as pa
from python_calamine import CalamineWorkbook

from .engine import columns_of, commit, current_snapshot, qi, split_key, table_exists, table_ref
from .sniff import combine_header, detect_format, encoding_of, header_depth, normalise_name, utf8_working_copy

PROVENANCE = ("_dl_load_id", "_dl_row", "_dl_loaded_at", "_dl_landing_sha256", "_dl_rescued")
PROVENANCE_DDL = ('"_dl_load_id" BIGINT, "_dl_row" BIGINT, "_dl_loaded_at" TIMESTAMP WITH TIME ZONE, '
                  '"_dl_landing_sha256" VARCHAR, "_dl_rescued" VARCHAR')


class LoadRefused(Exception):
    def __init__(self, reason: str, detail: dict | None = None):
        super().__init__(reason)
        self.reason = reason
        self.detail = detail or {}


class _Source:
    """A streamable SELECT over the landed file producing ``_dl_row`` + text columns, and how to collect its rejects."""

    def __init__(self, sql: str, params: list, columns: list[str], cleanup=None, csv_rejects: bool = False):
        self.sql, self.params, self.columns, self._cleanup, self.csv_rejects = sql, params, columns, cleanup, csv_rejects

    def rejects(self, con) -> list[dict]:
        if not self.csv_rejects:
            return []
        return [{"line": int(line), "column": col, "error": etype, "message": msg, "raw": raw}
                for line, col, etype, raw, msg in con.execute(
                    "SELECT line, column_name, error_type, csv_line, error_message FROM _dl_rej_errors ORDER BY line").fetchall()]

    def close(self, con):
        if self._cleanup:
            self._cleanup(con)


def _source(con: duckdb.DuckDBPyConnection, path: str, options: dict, work_dir: str) -> _Source:
    fmt = options.get("format") or detect_format(path)
    if fmt == "csv":
        enc = options.get("encoding") or encoding_of(path)
        work = utf8_working_copy(path, enc, work_dir)
        base = ["delim = ?", "header = ?", "all_varchar = true"]
        params = [options.get("delim", ","), bool(options.get("header", True))]
        if options.get("quote"):
            base.append("quote = ?")
            params.append(options["quote"])
        cols = [r[0] for r in con.execute(f"DESCRIBE SELECT * FROM read_csv(?, {', '.join(base)}, ignore_errors = true)",
                                          [work] + params).fetchall()]
        con.execute("DROP TABLE IF EXISTS _dl_rej_errors; DROP TABLE IF EXISTS _dl_rej_scans")
        args = base + ["store_rejects = true", "rejects_table = '_dl_rej_errors'", "rejects_scan = '_dl_rej_scans'"]
        sql = f"SELECT row_number() OVER () AS _dl_row, * FROM read_csv(?, {', '.join(args)})"
        return _Source(sql, [work] + params, cols, (lambda c: os.unlink(work)) if work != path else None, csv_rejects=True)
    if fmt == "workbook":
        wb = CalamineWorkbook.from_path(path)
        sheet = options.get("sheet") or wb.sheet_names[0]
        rows = wb.get_sheet_by_name(sheet).to_python(skip_empty_area=False)
        depth = int(options.get("header_rows") or header_depth(rows))
        names = combine_header(rows, depth)
        cols: dict[str, list] = {"_dl_row": []}
        for h in names:
            cols[h] = []
        for i, r in enumerate(rows[depth:]):
            if all(v in (None, "") for v in r):
                continue
            cols["_dl_row"].append(depth + i + 1)  # the sheet's own row number, as a person sees it
            for j, h in enumerate(names):
                v = r[j] if j < len(r) else None
                cols[h].append(None if v in (None, "") else _cell_text(v))
        tbl = pa.table({k: pa.array(v, pa.int64() if k == "_dl_row" else pa.string()) for k, v in cols.items()})
        con.register("_dl_arrow", tbl)
        return _Source("SELECT * FROM _dl_arrow", [], names, lambda c: c.unregister("_dl_arrow"))
    if fmt in ("json", "parquet"):
        reader = "read_json_auto(?, sample_size = -1)" if fmt == "json" else "read_parquet(?, file_row_number = true)"
        desc = [(r[0], r[1]) for r in con.execute(f"DESCRIBE SELECT * FROM {reader}", [path]).fetchall()
                if r[0] != "file_row_number"]
        proj = []
        for name, typ in desc:
            nested = any(t in typ for t in ("STRUCT", "MAP", "[]", "LIST", "UNION"))
            expr = f"to_json({qi(name)})::VARCHAR" if nested else f"CAST({qi(name)} AS VARCHAR)"
            proj.append(f"{expr} AS {qi(name)}")
        rown = "file_row_number + 1" if fmt == "parquet" else "row_number() OVER ()"
        return _Source(f"SELECT {rown} AS _dl_row, {', '.join(proj)} FROM {reader}", [path], [n for n, _ in desc])
    raise LoadRefused("unsupported_format", {"format": fmt})


def _cell_text(v) -> str:
    if isinstance(v, float) and v.is_integer():
        return str(int(v))
    if hasattr(v, "isoformat"):
        return v.isoformat()
    return str(v)


def load_raw(con: duckdb.DuckDBPyConnection, *, dataset_key: str, landing_path: str, landing_sha256: str, load_id: int,
             author: str, mapping: dict[str, str] | None = None, evolution_mode: str = "rescue", read_options: dict | None = None,
             work_dir: str | None = None, alias: str = "lake") -> dict:
    """Land one file into ``raw.<name>``. ``mapping`` = source column name → raw column name (the person-confirmed
    proposal); a source column not in the mapping is NEW (drift) unless the raw table already has a column of its
    normalised name. Returns {status, rows_read, rows_loaded, rows_rejected, rows_rescued, rejects, drift, snapshot_id}."""
    zone, _ = split_key(dataset_key)
    if zone not in ("raw", "sandbox"):
        raise ValueError("loads land in raw (or a person's sandbox)")
    if evolution_mode not in ("add", "rescue", "fail"):
        raise ValueError("evolution_mode is add, rescue or fail")
    work_dir = work_dir or tempfile.gettempdir()
    t0 = time.monotonic()
    ref = table_ref(dataset_key, alias)
    exists = table_exists(con, dataset_key, alias)
    if exists and con.execute(f"SELECT 1 FROM {ref} WHERE _dl_landing_sha256 = ? LIMIT 1", [landing_sha256]).fetchone():
        n = con.execute(f"SELECT count(*) FROM {ref} WHERE _dl_landing_sha256 = ?", [landing_sha256]).fetchone()[0]
        return {"status": "noop", "reason": "already_loaded", "rows_already": int(n), "rows_loaded": 0,
                "snapshot_id": current_snapshot(con, alias), "ms": int((time.monotonic() - t0) * 1000)}
    src = _source(con, landing_path, read_options or {}, work_dir)
    try:
        mapping = {k: v for k, v in (mapping or {}).items() if k in src.columns}
        known = [c for c, _ in columns_of(con, dataset_key, alias) if c not in PROVENANCE] if exists else []
        used: set[str] = set(known)
        target_of: dict[str, str] = {}
        for s in src.columns:
            if s in mapping:
                target_of[s] = mapping[s]
            else:
                n = normalise_name(s, set())
                if n in known:
                    target_of[s] = n
                elif not exists:
                    target_of[s] = normalise_name(s, used)
        new_cols = [s for s in src.columns if s not in target_of]
        drift = {"added": [], "rescued": [], "missing": [c for c in known if c not in set(target_of.values())] if exists else []}
        if new_cols:
            if evolution_mode == "fail":
                raise LoadRefused("schema_drift", {"new_columns": new_cols, "missing": drift["missing"]})
            if evolution_mode == "add":
                for s in new_cols:
                    target_of[s] = normalise_name(s, used)
                    drift["added"].append(target_of[s])
            else:
                drift["rescued"] = list(new_cols)
        targets, sources = list(target_of.values()), list(target_of.keys())
        rescued_expr = "NULL"
        if drift["rescued"]:
            pairs = ", ".join(f"'{s.replace(chr(39), chr(39) * 2)}', {qi(s)}" for s in drift["rescued"])
            rescued_expr = (f"CASE WHEN {' AND '.join(f'{qi(s)} IS NULL' for s in drift['rescued'])} THEN NULL "
                            f"ELSE json_object({pairs})::VARCHAR END")
        with commit(con, author, f"load {load_id} into {dataset_key}", alias):
            if not exists:
                cols_ddl = ", ".join(f"{qi(t)} VARCHAR" for t in targets)
                con.execute(f"CREATE TABLE {ref} ({cols_ddl + ', ' if cols_ddl else ''}{PROVENANCE_DDL})")
            for t in drift["added"]:
                con.execute(f"ALTER TABLE {ref} ADD COLUMN {qi(t)} VARCHAR")
            insert_cols = ", ".join(qi(t) for t in targets + list(PROVENANCE))
            select_cols = ", ".join([qi(s) for s in sources] + [str(int(load_id)), "_dl_row", "now()", "?", rescued_expr])
            loaded = con.execute(f"INSERT INTO {ref} ({insert_cols}) SELECT {select_cols} FROM ({src.sql}) s",
                                 [landing_sha256] + src.params).fetchone()[0]
        rejects = src.rejects(con)
    finally:
        src.close(con)
    rescued = 0
    if drift["rescued"]:
        rescued = con.execute(f"SELECT count(*) FROM {ref} WHERE _dl_load_id = ? AND _dl_rescued IS NOT NULL", [load_id]).fetchone()[0]
    return {"status": "loaded", "rows_read": int(loaded) + len(rejects), "rows_loaded": int(loaded),
            "rows_rejected": len(rejects), "rows_rescued": int(rescued), "rejects": rejects, "drift": drift,
            "columns": targets, "snapshot_id": current_snapshot(con, alias), "ms": int((time.monotonic() - t0) * 1000)}


def rollback_load(con: duckdb.DuckDBPyConnection, *, load_id: int, dataset_keys: list[str], author: str,
                  alias: str = "lake") -> dict:
    """Remove one load's rows from raw and from every dataset that still carries its provenance (curated, quarantine) —
    as a NEW snapshot. History is never rewound: time travel to before the rollback still shows the rows until the
    snapshots are expired."""
    removed: dict[str, int] = {}
    with commit(con, author, f"rollback load {load_id}", alias):
        for key in dataset_keys:
            if not table_exists(con, key, alias):
                continue
            ref = table_ref(key, alias)
            n = con.execute(f"SELECT count(*) FROM {ref} WHERE _dl_load_id = ?", [load_id]).fetchone()[0]
            if n:
                con.execute(f"DELETE FROM {ref} WHERE _dl_load_id = ?", [load_id])
            removed[key] = int(n)
    return {"status": "rolled_back", "removed": removed, "snapshot_id": current_snapshot(con, alias)}
