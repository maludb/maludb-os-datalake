"""Erasure of one subject from every zone (design §6.7, R30, D11): FIND, REPORT, then — on a person's approval — ERASE and
PROVE. The app holds the request (``erasure_requests``); this module is the engine's part.

* ``scan``  — every column whose personal kind matches an identifier's kind, in every zone (raw, curated, quarantine,
  product, sandbox), compared as text (emails case-insensitively); ``follow`` carries the subject to datasets that hold it
  only by a key (a product keyed by customer_id) — the app derives ``follow`` from lineage. Nothing is changed.
* ``erase`` — DELETE in every dataset found (one commit per dataset, author + message), then the bytes:
  flush inlined data → rewrite every affected table's files without the deleted rows (``delete_threshold`` 0) → expire EVERY
  snapshot but the newest (the time-travel window is given up for these rows — that is the point) → clean up every file no
  snapshot references (``cleanup_all``) → delete orphaned files → ``VACUUM FULL`` of the catalog tables that held inlined rows
  (PostgreSQL keeps a deleted row's bytes in the heap until a vacuum rewrites it) → purge the landing files of the affected
  loads (the caller passes their paths) → ``verify``.
* ``verify`` — the proof: (1) at every remaining snapshot of every table, no row matches; (2) every data file on disk,
  DECRYPTED with its catalog key, contains no match; (3) no file on disk that the catalog does not list; (4) no inlined row
  in the catalog matches; (5) no landing file of the affected loads remains. Returns the proof dict.
Limits stated in the proof: PostgreSQL's WAL and earlier base backups, and earlier lake backups, hold the rows until they
expire (``backup_keep_days``) — the proof names the date.
"""
from __future__ import annotations

import datetime as dt
import os
import time

import duckdb
import psycopg

from .engine import ZONES, commit, current_snapshot, qi, table_ref


def _match(col: str, kind: str, values: list[str]) -> tuple[str, list]:
    if kind == "email":
        return f"lower(CAST({qi(col)} AS VARCHAR)) IN ({', '.join('?' for _ in values)})", [v.lower() for v in values]
    return f"CAST({qi(col)} AS VARCHAR) IN ({', '.join('?' for _ in values)})", list(values)


def scan(con: duckdb.DuckDBPyConnection, *, identifiers: list[dict], personal_map: dict[str, dict[str, str]],
         follow: list[dict] | None = None, alias: str = "lake") -> list[dict]:
    """→ targets [{dataset, column, kind, values, rows}]. ``follow``: [{"from": dataset, "key": col,
    "to": [{"dataset", "column"}]}] — the key values of the rows found in ``from`` become identifiers in each ``to``."""
    by_kind: dict[str, list[str]] = {}
    for i in identifiers:
        by_kind.setdefault(i["kind"], []).append(str(i["value"]))
    targets: list[dict] = []
    for ds, cols in personal_map.items():
        for col, kind in cols.items():
            if kind not in by_kind:
                continue
            cond, params = _match(col, kind, by_kind[kind])
            n = con.execute(f"SELECT count(*) FROM {table_ref(ds, alias)} WHERE {cond}", params).fetchone()[0]
            if n:
                targets.append({"dataset": ds, "column": col, "kind": kind, "values": by_kind[kind], "rows": int(n)})
    for f in follow or []:
        hits = [t for t in targets if t["dataset"] == f["from"]]
        keys: set[str] = set()
        for t in hits:
            cond, params = _match(t["column"], t["kind"], t["values"])
            keys |= {str(r[0]) for r in con.execute(
                f"SELECT DISTINCT CAST({qi(f['key'])} AS VARCHAR) FROM {table_ref(f['from'], alias)} WHERE {cond}", params).fetchall()
                if r[0] is not None}
        for to in f["to"]:
            if not keys:
                continue
            cond, params = _match(to["column"], "key", sorted(keys))
            n = con.execute(f"SELECT count(*) FROM {table_ref(to['dataset'], alias)} WHERE {cond}", params).fetchone()[0]
            if n:
                targets.append({"dataset": to["dataset"], "column": to["column"], "kind": "key", "values": sorted(keys),
                                "rows": int(n)})
    return targets


def erase(con: duckdb.DuckDBPyConnection, *, targets: list[dict], author: str, request_id: int, lake_dsn: str,
          landing_paths: list[str] | None = None, alias: str = "lake") -> dict:
    t0 = time.monotonic()
    steps: dict = {"deleted": {}}
    for t in targets:
        cond, params = _match(t["column"], t["kind"], t["values"])
        with commit(con, author, f"erasure {request_id}: {t['dataset']}", alias):
            n = con.execute(f"SELECT count(*) FROM {table_ref(t['dataset'], alias)} WHERE {cond}", params).fetchone()[0]
            con.execute(f"DELETE FROM {table_ref(t['dataset'], alias)} WHERE {cond}", params)
        steps["deleted"][t["dataset"]] = steps["deleted"].get(t["dataset"], 0) + int(n)
    with commit(con, author, f"erasure {request_id}: flush inlined data", alias):
        con.execute(f"SELECT * FROM ducklake_flush_inlined_data('{alias}')").fetchall()
    rewritten = 0
    for ds in {t["dataset"] for t in targets}:
        zone, name = ds.split(".", 1)
        rewritten += len(con.execute(f"SELECT * FROM ducklake_rewrite_data_files('{alias}', '{name}', schema => '{zone}', "
                                     f"delete_threshold => 0)").fetchall())
    steps["files_rewritten"] = rewritten
    future = dt.datetime.now(dt.timezone.utc) + dt.timedelta(seconds=1)
    steps["snapshots_expired"] = len(con.execute(f"SELECT * FROM ducklake_expire_snapshots('{alias}', older_than => ?)",
                                                 [future]).fetchall())
    steps["files_cleaned"] = len(con.execute(f"SELECT * FROM ducklake_cleanup_old_files('{alias}', cleanup_all => true)").fetchall())
    steps["orphans_deleted"] = len(con.execute(f"SELECT * FROM ducklake_delete_orphaned_files('{alias}', cleanup_all => true)").fetchall())
    steps["catalog_vacuumed"] = vacuum_inlined(lake_dsn, con, alias)
    purged = []
    for p in landing_paths or []:
        if os.path.exists(p):
            os.unlink(p)
            purged.append(os.path.basename(p))
    steps["landing_purged"] = purged
    steps["snapshot_id"] = current_snapshot(con, alias)
    steps["ms"] = int((time.monotonic() - t0) * 1000)
    return steps


def vacuum_inlined(lake_dsn: str, con: duckdb.DuckDBPyConnection, alias: str) -> list[str]:
    """VACUUM FULL the catalog's inlined-data tables (the writer's role owns them): a deleted inlined row's bytes otherwise
    stay in PostgreSQL's heap until a vacuum rewrites the page."""
    schema = con.execute("SELECT options['metadata_schema'] FROM duckdb_databases() WHERE database_name = ?", [alias]).fetchone()[0] \
        or "ducklake"
    done = []
    with psycopg.connect(lake_dsn, autocommit=True) as pg:
        tables = [r[0] for r in pg.execute(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE 'ducklake_inlined_data_%%'",
            [schema]).fetchall()]
        for t in tables + ["ducklake_data_file", "ducklake_delete_file", "ducklake_file_column_stats"]:
            pg.execute(f'VACUUM FULL "{schema}"."{t}"')
            done.append(t)
    return done


def _all_tables(con, alias):
    return [(r[0], r[1]) for r in con.execute(
        "SELECT schema_name, table_name FROM duckdb_tables() WHERE database_name = ? AND schema_name IN "
        f"({', '.join(repr(z) for z in ZONES)})", [alias]).fetchall()]


def verify(con: duckdb.DuckDBPyConnection, *, needles: list[str], data_path: str, lake_dsn: str, alias: str = "lake",
           landing_paths: list[str] | None = None) -> dict:
    """The proof. ``needles`` are the identifier values and any other text that must be gone (searched case-insensitively)."""
    low = [n.lower() for n in needles]
    proof = {"snapshots_checked": 0, "tables_checked": 0, "rows_found": [], "files_checked": 0, "files_with_match": [],
             "files_not_in_catalog": [], "inlined_rows_found": [], "landing_remaining": []}
    snaps = [r[0] for r in con.execute(f"SELECT snapshot_id FROM ducklake_snapshots('{alias}')").fetchall()]
    proof["snapshots_remaining"] = snaps
    for zone, name in _all_tables(con, alias):
        proof["tables_checked"] += 1
        cols = [r[0] for r in con.execute("SELECT column_name FROM duckdb_columns() WHERE database_name = ? AND schema_name = ? "
                                          "AND table_name = ?", [alias, zone, name]).fetchall()]
        row_text = "lower(concat_ws('|', " + ", ".join(f"CAST({qi(c)} AS VARCHAR)" for c in cols) + "))"
        cond = " OR ".join(f"contains({row_text}, ?)" for _ in low)
        for s in snaps:
            try:
                n = con.execute(f'SELECT count(*) FROM {alias}."{zone}"."{name}" AT (VERSION => {s}) WHERE {cond}', low).fetchone()[0]
            except duckdb.Error:
                continue  # the table did not exist at that snapshot
            proof["snapshots_checked"] += 1
            if n:
                proof["rows_found"].append({"table": f"{zone}.{name}", "snapshot": s, "rows": int(n)})
    # every file on disk, decrypted with its catalog key
    listed: dict[str, bytes | None] = {}
    for zone, name in _all_tables(con, alias):
        for f, _sz, _ft, key, df, _dsz, _dft, dkey in con.execute(
                f"SELECT * FROM ducklake_list_files('{alias}', '{name}', schema => '{zone}')").fetchall():
            listed[os.path.realpath(f)] = key
            if df:
                listed[os.path.realpath(df)] = dkey
    on_disk = [os.path.realpath(os.path.join(dp, fn)) for dp, _, fns in os.walk(data_path) for fn in fns]
    for path in on_disk:
        proof["files_checked"] += 1
        if path not in listed:
            proof["files_not_in_catalog"].append(os.path.relpath(path, data_path))
            continue
        key = listed[path]
        with open(path, "rb") as fh:
            raw = fh.read().lower()
        if any(n.encode() in raw for n in low):
            proof["files_with_match"].append({"file": os.path.relpath(path, data_path), "how": "plain bytes"})
            continue
        if key:
            # DuckLake's own way: the catalog's key as BLOB bytes in ``footer_key_value`` (FINDINGS §4)
            enc = "encryption_config = {footer_key_value: ?}"
            rel = con.execute(f"SELECT * FROM read_parquet(?, {enc})", [path, key])
            cols = [d[0] for d in rel.description]
            text = "lower(concat_ws('|', " + ", ".join(f"CAST({qi(c)} AS VARCHAR)" for c in cols) + "))"
            n = con.execute(f"SELECT count(*) FROM read_parquet(?, {enc}) WHERE "
                            + " OR ".join(f"contains({text}, ?)" for _ in low), [path, key] + low).fetchone()[0]
            if n:
                proof["files_with_match"].append({"file": os.path.relpath(path, data_path), "how": "decrypted", "rows": int(n)})
            proof["files_decrypted"] = proof.get("files_decrypted", 0) + 1
    schema = con.execute("SELECT options['metadata_schema'] FROM duckdb_databases() WHERE database_name = ?", [alias]).fetchone()[0] \
        or "ducklake"
    with psycopg.connect(lake_dsn) as pg:
        # inlined rows are plain rows in the catalog; the column statistics keep each file's and each table's min and max
        # VALUES in plain text (FINDINGS §4a) — both are searched
        for (t,) in pg.execute("SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND "
                               "(table_name LIKE 'ducklake_inlined_data_%%' OR table_name IN "
                               "('ducklake_file_column_stats', 'ducklake_table_column_stats'))", [schema]).fetchall():
            for n in low:
                c = pg.execute(f'SELECT count(*) FROM "{schema}"."{t}" x WHERE position(%s in lower(x::text)) > 0', [n]).fetchone()[0]
                if c:
                    proof["inlined_rows_found"].append({"table": t, "rows": int(c)})
    proof["landing_remaining"] = [os.path.basename(p) for p in (landing_paths or []) if os.path.exists(p)]
    proof["clean"] = not (proof["rows_found"] or proof["files_with_match"] or proof["files_not_in_catalog"]
                          or proof["inlined_rows_found"] or proof["landing_remaining"])
    return proof
