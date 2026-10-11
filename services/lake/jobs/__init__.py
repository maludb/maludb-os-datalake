"""The writer's job kinds (lake-engine.md §1): DISPATCH = {kind: handler}. writer_main.py merges it at start; a kind not here yet fails at once
with "not built until slice N". Each handler is job_<kind>(con, pg, job) -> dict (jobs/frame.py says what each argument is). A slice adds its
module and its kinds here — services/lake/README.md names who adds what."""
from .ingest import job_load, job_profile, job_rollback, job_sniff
from .query_results import job_export, job_sandbox_save

DISPATCH = {
    "sniff": job_sniff,          # slice 2
    "load": job_load,            # slice 2
    "profile": job_profile,      # slice 2
    "rollback": job_rollback,    # slice 2
    "sandbox_save": job_sandbox_save,   # slice 3 — a person's result kept as their sandbox dataset (the policy frozen at the request)
    "export": job_export,        # slice 3 — a person's result as a file (op make), and exports/ kept in step with its rows (op sweep)
}
