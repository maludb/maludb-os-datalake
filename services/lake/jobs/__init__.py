"""The writer's job kinds (lake-engine.md §1): DISPATCH = {kind: handler}. writer_main.py merges it at start; a kind not here yet fails at once
with "not built until slice N". Each handler is job_<kind>(con, pg, job) -> dict (jobs/frame.py says what each argument is). A slice adds its
module and its kinds here — services/lake/README.md names who adds what."""
from .ingest import job_load, job_profile, job_rollback, job_sniff
from .query_results import job_export, job_sandbox_save
from .quality import job_checks, job_promote
from .transform import job_transform

DISPATCH = {
    "sniff": job_sniff,          # slice 2
    "load": job_load,            # slice 2
    "profile": job_profile,      # slice 2
    "rollback": job_rollback,    # slice 2
    "sandbox_save": job_sandbox_save,   # slice 3 — a person's result kept as their sandbox dataset (the policy frozen at the request)
    "export": job_export,        # slice 3 — a person's result as a file (op make), and exports/ kept in step with its rows (op sweep)
    "promote": job_promote,      # slice 5 — raw → curated typed by the active contract, the quarantine, the row checks counted, on_load checks after
    "checks": job_checks,        # slice 5 — a dataset's checks (one, those of a `when`, or all), each result through dl_check_result_record()
    "transform": job_transform,  # slice 7 — a transform's SQL over its inputs as its owner could read them (frozen), replacing or merging into the output; the lineage
}
