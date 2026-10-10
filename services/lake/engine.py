"""The lake's connections — the ONE place a DuckDB connection to the lake is made (design §6.1, §6.5).

Two roles, two kinds of connection:

* ``writer`` — the datalake-writer service (and only it): attaches DuckLake read-write through the ``datalake_lake`` role,
  loads ``httpfs`` because DuckLake's ENCRYPTED writes need its OpenSSL crypto module (DuckDB's built-in mbedtls is
  read-only — FINDINGS §2), caps memory/threads/spill.
* ``query`` — the query service: attaches READ_ONLY through the ``datalake_query`` role, never loads ``httpfs``; the caller's
  views are created and the configuration locked by ``services/query`` before a word of the caller's SQL runs.

Configuration comes from the environment (config/.env in production, var/engine/secrets.env in the proofs):
DL_LAKE_DB_DSN / DL_QUERY_DB_DSN (libpq conninfo with ``passfile=`` — never a password: ``duckdb_databases()`` prints the
whole conninfo), DL_STORAGE_DIR (lake/ is DuckLake's DATA_PATH, tmp/ the spill), DL_EXTENSION_DIR (vendored, read-only).
Extensions are LOADed by name with autoinstall and autoload OFF — nothing is downloaded at runtime.
"""
from __future__ import annotations

import os
import secrets
from contextlib import contextmanager
from dataclasses import dataclass
from typing import Iterator

import duckdb

ZONES = ("raw", "curated", "product", "sandbox", "quarantine")
METADATA_SCHEMA = "ducklake"
INLINING_ROW_LIMIT = 10  # DuckLake's data inlining: batches up to this many rows live in the catalog until flushed


@dataclass(frozen=True)
class LakeConfig:
    lake_dsn: str
    query_dsn: str
    storage_dir: str
    extension_dir: str
    writer_memory: str = "3GB"
    writer_threads: int = 4
    writer_temp_max: str = "20GB"

    @property
    def data_path(self) -> str:
        return os.path.join(self.storage_dir, "lake") + "/"

    @property
    def temp_dir(self) -> str:
        return os.path.join(self.storage_dir, "tmp")

    @classmethod
    def from_env(cls, env: dict | None = None) -> "LakeConfig":
        e = env if env is not None else os.environ
        for k in ("DL_LAKE_DB_DSN", "DL_QUERY_DB_DSN", "DL_STORAGE_DIR", "DL_EXTENSION_DIR"):
            if not e.get(k):
                raise RuntimeError(f"{k} is not set")
        for k in ("DL_LAKE_DB_DSN", "DL_QUERY_DB_DSN"):
            if "password=" in e[k]:
                raise RuntimeError(f"{k} carries a password — use passfile= (duckdb_databases() prints the conninfo)")
        return cls(lake_dsn=e["DL_LAKE_DB_DSN"], query_dsn=e["DL_QUERY_DB_DSN"], storage_dir=e["DL_STORAGE_DIR"],
                   extension_dir=e["DL_EXTENSION_DIR"], writer_memory=e.get("DL_WRITER_MEMORY", "3GB"),
                   writer_threads=int(e.get("DL_WRITER_THREADS", "4")), writer_temp_max=e.get("DL_WRITER_TEMP_MAX", "20GB"))


def _base_connection(cfg: LakeConfig, memory: str, threads: int, temp_max: str, extensions: tuple[str, ...]) -> duckdb.DuckDBPyConnection:
    os.makedirs(cfg.temp_dir, exist_ok=True)
    con = duckdb.connect(database=":memory:", config={
        "extension_directory": cfg.extension_dir,
        "autoinstall_known_extensions": False,
        "autoload_known_extensions": False,
        "allow_community_extensions": False,
        "memory_limit": memory,
        "threads": threads,
        "temp_directory": cfg.temp_dir,
        "max_temp_directory_size": temp_max,
    })
    for ext in extensions:
        con.execute(f"LOAD {ext}")
    return con


def writer_connection(cfg: LakeConfig, alias: str = "lake", dsn: str | None = None,
                      data_path: str | None = None, encrypted: bool = True,
                      metadata_schema: str = METADATA_SCHEMA) -> duckdb.DuckDBPyConnection:
    """The writer's connection: the lake attached read-write as ``alias``; zones created if missing."""
    con = _base_connection(cfg, cfg.writer_memory, cfg.writer_threads, cfg.writer_temp_max,
                           ("ducklake", "postgres_scanner", "httpfs"))
    opts = [f"DATA_PATH '{_q(data_path or cfg.data_path)}'", f"METADATA_SCHEMA '{_q(metadata_schema)}'"]
    if encrypted:
        opts.append("ENCRYPTED")
    con.execute(f"ATTACH '{_q('ducklake:postgres:' + (dsn or cfg.lake_dsn))}' AS {alias} ({', '.join(opts)})")
    opts_now = dict((r[0], r[2]) for r in con.execute(f"FROM ducklake_options('{alias}')").fetchall())
    if opts_now.get("data_inlining_row_limit") != str(INLINING_ROW_LIMIT):
        con.execute(f"CALL ducklake_set_option('{alias}', 'data_inlining_row_limit', {INLINING_ROW_LIMIT})")
    have = {r[0] for r in con.execute("SELECT schema_name FROM duckdb_schemas() WHERE database_name = ?", [alias]).fetchall()}
    for z in ZONES:
        if z not in have:
            con.execute(f"CREATE SCHEMA {alias}.{z}")
    return con


def query_connection(cfg: LakeConfig, memory: str = "1.5GB", threads: int = 2, temp_max: str = "2GB",
                     dsn: str | None = None, data_path: str | None = None,
                     metadata_schema: str = METADATA_SCHEMA, alias: str | None = None) -> tuple[duckdb.DuckDBPyConnection, str]:
    """A fresh query connection: the lake attached READ_ONLY under an unguessable alias (returned). NOT yet locked —
    services/query creates the caller's views and then locks it. Never loads httpfs."""
    con = _base_connection(cfg, memory, threads, temp_max, ("ducklake", "postgres_scanner"))
    alias = alias or "lk_" + secrets.token_hex(6)
    con.execute(f"ATTACH '{_q('ducklake:postgres:' + (dsn or cfg.query_dsn))}' AS {alias} "
                f"(DATA_PATH '{_q(data_path or cfg.data_path)}', METADATA_SCHEMA '{_q(metadata_schema)}', READ_ONLY)")
    return con, alias


@contextmanager
def commit(con: duckdb.DuckDBPyConnection, author: str, message: str, alias: str = "lake") -> Iterator[None]:
    """One DuckLake commit with an author (``dl:<member or agent>``) and a message (``load 4182``) — every write the writer
    makes goes through here, so ducklake_snapshot_changes is an engine-level history beside activity_log."""
    con.execute("BEGIN TRANSACTION")
    try:
        yield
        con.execute(f"CALL ducklake_set_commit_message('{alias}', ?, ?)", [author, message])
        con.execute("COMMIT")
    except BaseException:
        try:
            con.execute("ROLLBACK")
        except Exception:
            pass
        raise


def current_snapshot(con: duckdb.DuckDBPyConnection, alias: str = "lake") -> int:
    return int(con.execute(f"SELECT id FROM ducklake_current_snapshot('{alias}')").fetchone()[0])


def split_key(key: str) -> tuple[str, str]:
    """``curated.deals`` → (``curated``, ``deals``); refuses anything that is not ``<zone>.<identifier>``."""
    parts = key.split(".")
    if len(parts) != 2 or parts[0] not in ZONES or not is_identifier(parts[1]):
        raise ValueError(f"not a dataset key: {key!r}")
    return parts[0], parts[1]


def is_identifier(name: str) -> bool:
    return bool(name) and len(name) <= 63 and name[0].isalpha() and all(ch.isalnum() or ch == "_" for ch in name) \
        and name == name.lower()


def table_ref(key: str, alias: str = "lake") -> str:
    zone, name = split_key(key)
    return f'{alias}."{zone}"."{name}"'


def table_exists(con: duckdb.DuckDBPyConnection, key: str, alias: str = "lake") -> bool:
    zone, name = split_key(key)
    return con.execute("SELECT count(*) FROM duckdb_tables() WHERE database_name = ? AND schema_name = ? AND table_name = ?",
                       [alias, zone, name]).fetchone()[0] > 0


def columns_of(con: duckdb.DuckDBPyConnection, key: str, alias: str = "lake") -> list[tuple[str, str]]:
    zone, name = split_key(key)
    return [(r[0], r[1]) for r in con.execute(
        "SELECT column_name, data_type FROM duckdb_columns() WHERE database_name = ? AND schema_name = ? AND table_name = ? "
        "ORDER BY column_index", [alias, zone, name]).fetchall()]


def qi(name: str) -> str:
    """Quote an identifier."""
    return '"' + name.replace('"', '""') + '"'


def _q(s: str) -> str:
    return s.replace("'", "''")
