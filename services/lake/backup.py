"""Backups and the restore drill (design §6.7, R68; DuckLake's guide: the catalog and the files have different backup
strategies — the catalog FIRST, then the files, and never while maintenance may delete a file the dump still names).

* ``backup`` — under the writer's ``backup_lock`` (the caller holds it: no maintenance, no erasure runs meanwhile):
  ``pg_dump -Fc -n <metadata schema>`` of the catalog through the writer's role, THEN a copy of the data directory (and
  landing), THEN ``manifest.json`` — the snapshot the dump holds, every file with its size and sha256. A file written after
  the dump is an orphan in the copy (harmless; the next maintenance deletes it); a file the dump names is always in the copy.
* ``restore_drill`` — restores the dump into a scratch catalog database and the files into a scratch directory, points the
  restored catalog's ``data_path`` at the new directory (a relocation), attaches it READ_ONLY and runs fixed queries; the
  caller compares the answers with the live lake's at the dump's snapshot. A snapshot is not a backup; this is the backup's proof.
"""
from __future__ import annotations

import hashlib
import json
import os
import shutil
import subprocess
import time

import psycopg

from .engine import LakeConfig, METADATA_SCHEMA, current_snapshot, query_connection


def _conninfo(dsn: str) -> dict:
    return dict(p.split("=", 1) for p in dsn.split())


def _pg_env(dsn: str) -> tuple[list[str], dict]:
    ci = _conninfo(dsn)
    env = dict(os.environ)
    if "passfile" in ci:
        env["PGPASSFILE"] = ci["passfile"]
    args = ["-h", ci.get("host", "127.0.0.1"), "-p", ci.get("port", "5432"), "-U", ci["user"], "-d", ci["dbname"]]
    return args, env


def _sha(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def backup(cfg: LakeConfig, dest_dir: str, *, con=None, include_landing: bool = True,
           metadata_schema: str = METADATA_SCHEMA) -> dict:
    t0 = time.monotonic()
    os.makedirs(dest_dir, exist_ok=True)
    snap = current_snapshot(con) if con is not None else None
    args, env = _pg_env(cfg.lake_dsn)
    dump = os.path.join(dest_dir, "catalog.dump")
    subprocess.run(["pg_dump", "-Fc", "-n", metadata_schema, "--no-owner", "--no-privileges", "-f", dump] + args,
                   env=env, check=True, capture_output=True, stdin=subprocess.DEVNULL, timeout=600)
    files = []
    for sub in ("lake",) + (("landing",) if include_landing else ()):
        src = os.path.join(cfg.storage_dir, sub)
        if not os.path.isdir(src):
            continue
        dst = os.path.join(dest_dir, sub)
        shutil.copytree(src, dst, dirs_exist_ok=True)
        for dp, _, fns in os.walk(dst):
            for fn in fns:
                p = os.path.join(dp, fn)
                files.append({"path": os.path.relpath(p, dest_dir), "bytes": os.path.getsize(p), "sha256": _sha(p)})
    manifest = {"snapshot_id": snap, "catalog": "catalog.dump", "catalog_sha256": _sha(dump), "metadata_schema": metadata_schema,
                "files": files, "taken_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}
    with open(os.path.join(dest_dir, "manifest.json"), "w") as f:
        json.dump(manifest, f, indent=1)
    return {"status": "done", "snapshot_id": snap, "files": len(files), "bytes": sum(x["bytes"] for x in files),
            "ms": int((time.monotonic() - t0) * 1000)}


def restore_drill(cfg: LakeConfig, backup_dir: str, *, restore_dsn: str, restore_data_dir: str, queries: list[str],
                  metadata_schema: str = METADATA_SCHEMA) -> dict:
    t0 = time.monotonic()
    with open(os.path.join(backup_dir, "manifest.json")) as f:
        manifest = json.load(f)
    bad = [x["path"] for x in manifest["files"] if _sha(os.path.join(backup_dir, x["path"])) != x["sha256"]]
    if bad or _sha(os.path.join(backup_dir, manifest["catalog"])) != manifest["catalog_sha256"]:
        return {"status": "corrupt", "files": bad}
    args, env = _pg_env(restore_dsn)
    # The restore target's schema exists already (made by the provisioning step with its grants) — restore everything else.
    toc = subprocess.run(["pg_restore", "-l", os.path.join(backup_dir, manifest["catalog"])], check=True, capture_output=True,
                         text=True, stdin=subprocess.DEVNULL, timeout=120).stdout
    keep = "\n".join(line for line in toc.splitlines() if " SCHEMA - " not in line)
    listfile = os.path.join(backup_dir, "restore.list")
    with open(listfile, "w") as f:
        f.write(keep + "\n")
    with psycopg.connect(restore_dsn, autocommit=True) as pg:
        for (t,) in pg.execute("SELECT table_name FROM information_schema.tables WHERE table_schema = %s",
                               [metadata_schema]).fetchall():
            pg.execute(f'DROP TABLE "{metadata_schema}"."{t}" CASCADE')
    subprocess.run(["pg_restore", "--no-owner", "--no-privileges", "-L", listfile, "-d", _conninfo(restore_dsn)["dbname"]]
                   + args[:-2] + [os.path.join(backup_dir, manifest["catalog"])], env=env, check=True, capture_output=True,
                   stdin=subprocess.DEVNULL, timeout=600)
    shutil.rmtree(restore_data_dir, ignore_errors=True)
    shutil.copytree(os.path.join(backup_dir, "lake"), os.path.join(restore_data_dir, "lake"))
    new_path = os.path.join(restore_data_dir, "lake") + "/"
    with psycopg.connect(restore_dsn, autocommit=True) as pg:
        pg.execute(f"UPDATE \"{metadata_schema}\".ducklake_metadata SET value = %s WHERE key = 'data_path'", [new_path])
    rcfg = LakeConfig(lake_dsn=restore_dsn, query_dsn=restore_dsn, storage_dir=restore_data_dir, extension_dir=cfg.extension_dir,
                      writer_memory=cfg.writer_memory, writer_threads=cfg.writer_threads)
    con, _ = query_connection(rcfg, memory=cfg.writer_memory, threads=cfg.writer_threads, dsn=restore_dsn,
                              data_path=new_path, metadata_schema=metadata_schema, alias="lake")
    answers = [con.execute(q).fetchall() for q in queries]
    snap = current_snapshot(con)
    con.close()
    return {"status": "restored", "snapshot_id": snap, "answers": answers, "ms": int((time.monotonic() - t0) * 1000)}
