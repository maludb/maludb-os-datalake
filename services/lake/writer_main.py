"""The lake's WRITER service — the only process that writes the lake (design §6.1; docs/build-specs/lake-engine.md §1).

    services/.venv/bin/python services/lake/writer_main.py            (the unit datalake-writer.service)
    services/.venv/bin/python services/lake/writer_main.py --once     (claim and work what is due, then exit — the proofs)

The loop: one PostgreSQL connection (psycopg, the datalake_lake role through DL_LAKE_DB_DSN — a passfile, never a password) holds
LISTEN lake_jobs and claims with dl_job_claim() (db/009 — never two running jobs on one dataset, a backup alone); each claimed job runs on
a thread of its own (up to dl_settings.writer_concurrency) with its OWN PostgreSQL connection and, from slice 2, its own DuckDB writer
connection (engine.writer_connection) closed when the job ends — never a DuckDB transaction held across jobs (FINDINGS §2); then
dl_job_finish(). Nothing claimable → wait up to 2 s for a NOTIFY (slice 2 appends the NOTIFY to dl_job_enqueue).

PHASE 2 — THE ENGINE RUNS EMPTY (sso-shell.md, divergence 1): DISPATCH is empty. A job whose kind no slice has built yet is finished
`failed` at once with "not built until slice N" and no retry. Each slice adds its kinds to DISPATCH through services/lake/jobs/
(lake-engine §2: `jobs/__init__.py` holds DISPATCH = {kind: handler}, each handler `job_<kind>(pg, job) -> dict`) — services/lake/README.md
names who adds what. An error or result never carries a row value, SQL text with literals, a path or a credential.

The connection's application_name is `datalake-writer`: the shell's health check (app/lake/client.php lake_ping) sees the writer
connected through pg_stat_activity — the writer has no socket of its own.
"""
from __future__ import annotations

import argparse
import os
import select
import signal
import socket
import sys
import threading
import traceback
from concurrent.futures import ThreadPoolExecutor

_HERE = os.path.dirname(os.path.abspath(__file__))
sys.path[:] = [p for p in sys.path if os.path.abspath(p or ".") != _HERE]   # run as a script: services/lake/profile.py must not shadow the stdlib
sys.path.insert(0, os.path.dirname(_HERE))

import psycopg  # noqa: E402
from psycopg.rows import dict_row  # noqa: E402
from psycopg.types.json import Json  # noqa: E402

from lake.engine import LakeConfig  # noqa: E402

APPLICATION_NAME = "datalake-writer"

# Who builds each kind (lake-engine §1's table; design §10). A kind here with no handler in DISPATCH fails at once, naming its slice.
KIND_SLICE = {
    "sniff": "slice 2", "load": "slice 2", "profile": "slice 2", "rollback": "slice 2",
    "export": "slice 3",
    "promote": "slice 5", "checks": "slice 5",
    "transform": "slice 7",
    "maintenance": "slice 8", "retention": "slice 8", "erasure": "slice 8", "backup_lock": "slice 8",
}

# {kind: handler(pg, job) -> dict} — EMPTY in Phase 2; services/lake/jobs/ (slice 2 onward) fills it.
DISPATCH: dict = {}
try:  # pragma: no cover - the package arrives with slice 2
    from lake.jobs import DISPATCH as _SLICES  # type: ignore  # noqa: E402
    DISPATCH.update(_SLICES)
except ImportError:
    pass

_stop = threading.Event()


def _dsn(cfg: LakeConfig) -> str:
    return cfg.lake_dsn + f" application_name={APPLICATION_NAME}"


def _connect(cfg: LakeConfig) -> psycopg.Connection:
    return psycopg.connect(_dsn(cfg), autocommit=True, row_factory=dict_row)


def _first_line(text: str) -> str:
    line = (text or "").strip().splitlines()[0] if (text or "").strip() else "failed"
    return line[:2000]


def work(cfg: LakeConfig, job: dict) -> None:
    """One claimed job on its own connection: dispatch, then finish. A kind not built yet fails once, without a retry."""
    with _connect(cfg) as pg:
        kind = job["kind"]
        handler = DISPATCH.get(kind)
        if handler is None:
            slice_ = KIND_SLICE.get(kind, "a later slice")
            pg.execute("UPDATE lake_jobs SET max_attempts = GREATEST(attempts, 1) WHERE id = %s", (job["id"],))   # no retry
            pg.execute("SELECT dl_job_finish(%s, false, NULL, %s)", (job["id"], f"not built until {slice_}"))
            return
        try:
            result = handler(pg, job) or {}
            pg.execute("SELECT dl_job_finish(%s, true, %s::jsonb, NULL)", (job["id"], Json(result)))
        except Exception as e:  # the job's own failure: retried with back-off by dl_job_finish until max_attempts
            traceback.print_exc()
            pg.execute("SELECT dl_job_finish(%s, false, NULL, %s)", (job["id"], _first_line(f"{type(e).__name__}: {e}")))


def claim(pg: psycopg.Connection, worker: str) -> dict | None:
    row = pg.execute("SELECT * FROM dl_job_claim(%s)", (worker,)).fetchone()
    return row if row and row.get("id") is not None else None


def concurrency(pg: psycopg.Connection) -> int:
    row = pg.execute("SELECT dl_setting_int('writer_concurrency') AS n").fetchone()     # the writer's role reads settings only through db/005's functions
    return max(1, int(row["n"])) if row and row["n"] is not None else 1


def run(cfg: LakeConfig, once: bool = False) -> int:
    worker_base = f"{socket.gethostname()}:{os.getpid()}"
    with _connect(cfg) as pg:
        pg.execute("LISTEN lake_jobs")
        slots = concurrency(pg)
        print(f"writer: connected as {APPLICATION_NAME}; {slots} job(s) at once; {len(DISPATCH)} kind(s) built"
              + (f" ({', '.join(sorted(DISPATCH))})" if DISPATCH else " — the engine runs empty (Phase 2)"), flush=True)
        running: set = set()
        lock = threading.Lock()
        with ThreadPoolExecutor(max_workers=slots, thread_name_prefix="job") as pool:
            n = 0
            while not _stop.is_set():
                with lock:
                    free = slots - len(running)
                job = claim(pg, f"{worker_base}:{n}") if free > 0 else None
                if job is not None:
                    n += 1
                    fut = pool.submit(work, cfg, job)
                    with lock:
                        running.add(fut)

                    def _done(f, _running=running, _lock=lock):
                        with _lock:
                            _running.discard(f)
                    fut.add_done_callback(_done)
                    continue
                if once:
                    with lock:
                        busy = bool(running)
                    if not busy:
                        break
                # nothing claimable (or every slot busy): wait for a NOTIFY or 2 s, then look again
                if select.select([pg.fileno()], [], [], 2.0)[0]:
                    for _ in pg.notifies(timeout=0):
                        pass
        print("writer: stopped", flush=True)
    return 0


def run_one(job_id: int, cfg: LakeConfig | None = None) -> dict:
    """Work ONE queued job by id (the proofs, lake-engine §9): claimed only if it is the next claimable; answers its row afterwards."""
    cfg = cfg or LakeConfig.from_env()
    with _connect(cfg) as pg:
        job = claim(pg, f"{socket.gethostname()}:{os.getpid()}:one")
        if job is None or job["id"] != job_id:
            if job is not None:   # not the one asked for: put it back as it was
                pg.execute("UPDATE lake_jobs SET status = 'queued', locked_by = NULL, started_at = NULL, attempts = attempts - 1 WHERE id = %s", (job["id"],))
            raise RuntimeError(f"job {job_id} is not the next claimable job")
        work(cfg, job)
        return pg.execute("SELECT id, kind, status, error, result FROM lake_jobs WHERE id = %s", (job_id,)).fetchone()


def main() -> int:
    ap = argparse.ArgumentParser(description="The Data Lake's writer (the lake_jobs queue).")
    ap.add_argument("--once", action="store_true", help="work what is due, then exit")
    a = ap.parse_args()
    try:
        cfg = LakeConfig.from_env()
    except RuntimeError as e:
        print(f"writer: {e}", file=sys.stderr, flush=True)
        return 1
    signal.signal(signal.SIGTERM, lambda *_: _stop.set())
    signal.signal(signal.SIGINT, lambda *_: _stop.set())
    return run(cfg, once=a.once)


if __name__ == "__main__":
    sys.exit(main())
