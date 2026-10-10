#!/usr/bin/env bash
# The schema proof (docs/datalake-design.md §10, Phase 0): a fresh scratch database `dl_proof`, every db/[0-9]*.sql applied in order as
# postgres, then db/proof/phase0_proof.sql (one transaction, rolled back). Re-runnable from nothing; never touches an installed database.
# The three installer roles are made here when missing (cluster-wide, NOLOGIN — the installer gives them passwords); db/006 makes the two
# engine roles itself.
set -euo pipefail
cd "$(dirname "$0")/../.."
DB=${DL_PROOF_DB:-dl_proof}
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -X"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null
sudo -n -u postgres createdb "$DB"
$PSQL -d postgres >/dev/null <<'SQL'
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'datalake_rw') THEN CREATE ROLE datalake_rw NOLOGIN; END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'datalake_records_ro') THEN CREATE ROLE datalake_records_ro NOLOGIN; END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'datalake_activity_ro') THEN CREATE ROLE datalake_activity_ro NOLOGIN; END IF;
END $$;
SQL
n=0
for f in db/[0-9]*.sql; do
  $PSQL -d "$DB" -f "$f" >/dev/null || { echo "MIGRATION FAILED: $f"; exit 1; }
  n=$((n + 1))
done
echo " $n migrations applied to $DB"
$PSQL -d "$DB" -f db/proof/phase0_proof.sql
