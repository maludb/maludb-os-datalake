"""The writer's job kinds (lake-engine.md §1): DISPATCH = {kind: handler}. writer_main.py merges it at start; a kind not here yet fails at once
with "not built until slice N". Each handler is job_<kind>(con, pg, job) -> dict (jobs/frame.py says what each argument is). A slice adds its
module and its kinds here — services/lake/README.md names who adds what."""
from .ingest import job_load, job_profile, job_rollback, job_sniff

DISPATCH = {
    "sniff": job_sniff,          # slice 2
    "load": job_load,            # slice 2
    "profile": job_profile,      # slice 2
    "rollback": job_rollback,    # slice 2
}
