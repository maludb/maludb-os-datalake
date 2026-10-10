#!/usr/bin/env bash
# Data Lake — what root (the owner) does to install it beside the kernel, in order. Written in Phase 0 (2026-10-10); later phases ADD
# steps and mark them. NOTHING HERE HAS BEEN RUN: the proofs use scratch databases, scratch directories, php -S and fixtures, and never
# touch an installed application, Apache, systemd or the MaluDB API.
#   bash deploy/ROOT_STEPS.sh                                  prints this plan (the default; nothing changes)
#   sudo bash deploy/ROOT_STEPS.sh apply [--allow-shared-volume] runs every step that has no decision in it (0–6b), each idempotent
# Step 7 (the installer's apply and the hires) is printed and never run. --allow-shared-volume lets step 3 put the data directory on
# PostgreSQL's filesystem — for a scratch proof only; an installation puts it on its own volume (the owner's (a), 2026-10-10).
set -euo pipefail
APP=/srv/apps/datalake
KERNEL=/var/www
MODE=plan; SHARED=0
for a in "$@"; do case "$a" in apply) MODE=apply;; --allow-shared-volume) SHARED=1;; *) echo "unknown argument: $a" >&2; exit 2;; esac; done
if [ "$MODE" = apply ] && [ "$(id -u)" != 0 ]; then echo "apply needs root: sudo bash deploy/ROOT_STEPS.sh apply" >&2; exit 2; fi
ENVF=$APP/config/.env
envval() { [ -f "$ENVF" ] && sed -n "s/^$1=//p" "$ENVF" | tail -1 | sed -E "s/^([\"'])(.*)\\1\$/\\2/" || true; }
STORE=$(envval DL_STORAGE_DIR); STORE=${STORE:-${DL_STORAGE_DIR:-/srv/lake}}
EXT=$(envval DL_EXTENSION_DIR); EXT=${EXT:-/var/lib/datalake/duckdb_extensions}
SALT=$(envval DL_MASK_SALT_FILE); SALT=${SALT:-/etc/datalake/mask_salt}
WORKERS=3
run() { echo "+ $*"; if [ "$MODE" = apply ]; then "$@"; fi; }
envset() {   # one key into config/.env in place (the inode kept: owner root or the installer's, group www-data, mode 640)
    local k=$1 v=$2 tmp
    [ "$(envval "$k")" = "$v" ] && { echo "   $k already $v"; return; }
    echo "+ $k=$v → config/.env"
    [ "$MODE" = apply ] || return 0
    tmp=$(mktemp); grep -v "^$k=" "$ENVF" > "$tmp" || true; printf '%s=%s\n' "$k" "$v" >> "$tmp"; cat "$tmp" > "$ENVF"; rm -f "$tmp"
}

# ------------------------------------------------------------------------------------------------------------------
# 0. APACHE'S MODULES (idempotent; Sales CRM's step 0b, 2026-10-09). The vhost rewrites (rewrite), proxies the two MCP servers (proxy,
#    proxy_http) and sets nosniff and the frame option (headers — this host had it disabled; the vhost guards its Header lines with
#    <IfModule> so a missing module never fails the config test, but the headers are wanted).
echo "== 0. Apache's modules: rewrite, proxy, proxy_http, headers"
run a2enmod -q rewrite proxy proxy_http headers

# ------------------------------------------------------------------------------------------------------------------
# 1. PIN THE PORTS (and the data directory) before the installer's first apply (Help Desk's lesson of 2026-10-02). A config/.env holding
#    ONLY these: the installer reads them, fills every other required key itself, and sets fresh role passwords because DB_PASSWORD is
#    absent. DL_STORAGE_DIR is pinned too: the writer's and the query service's units are rendered from it ({{DL_STORAGE_DIR}}).
echo "== 1. Pin the ports and the data directory in $ENVF"
if [ ! -f "$ENVF" ]; then
  run bash -c "install -d -m 755 '$APP/config' && printf 'APP_INTERNAL_PORT=8193\nMCP_RECORDS_PORT=8847\nMCP_ACTIVITY_PORT=8848\nDL_STORAGE_DIR=$STORE\n' > '$ENVF' && chgrp www-data '$ENVF' && chmod 640 '$ENVF'"
else
  echo "   (config/.env exists — the ports are kept as they are)"
  envset DL_STORAGE_DIR "$STORE"
fi
#    See that it worked:  php $KERNEL/bin/app_install.php plan $APP --domain subello.com | grep -E '^(todo|done) +(ports|vhost|services)'   -> no {{...}} unfilled

# ------------------------------------------------------------------------------------------------------------------
# 2. THE ENGINE'S SYSTEM USERS (idempotent). datalake-writer writes the lake; datalake-query reads it. Each has its own group (the query
#    service's reads the masking salt) and both are in the group `datalake` (lake/ is group-readable by it). Neither can log in; neither
#    is in www-data, and www-data is in neither group — the web application cannot read lake/ (design §3 rule 5).
echo "== 2. System group datalake; users datalake-writer and datalake-query (nologin)"
if ! getent group datalake >/dev/null; then run groupadd --system datalake; else echo "   group datalake exists"; fi
for u in datalake-writer datalake-query; do
  if ! id -u "$u" >/dev/null 2>&1; then
    run useradd --system --user-group --groups datalake --home-dir /nonexistent --no-create-home --shell /usr/sbin/nologin "$u"
  else
    echo "   user $u exists"; run usermod -a -G datalake "$u"
  fi
done

# ------------------------------------------------------------------------------------------------------------------
# 3. THE DATA DIRECTORY $STORE (idempotent). On its OWN VOLUME (the owner's (a)): a new Proxmox disk → its own volume group (lake-vg) and
#    filesystem, mounted at /srv/lake with noatime — this host's ubuntu-vg has no free extent. REFUSED when it is on PostgreSQL's
#    filesystem: the lake and DuckDB's spill must never be able to fill the disk PostgreSQL lives on (the pgaudit disk-fill of 2026-10-05).
#      landing/  www-data:datalake 2770 — uploads (PHP) and pulls (the writer); setgid so every file is group datalake
#      lake/     datalake-writer:datalake 0750 — DuckLake's DATA_PATH: the writer writes, the query service reads by group, www-data nothing
#      exports/  datalake-writer:www-data 2750 — the writer builds an export, PHP streams it to the person who asked
#      tmp/      datalake-writer:datalake 2770 — DuckDB's spill (the writer's and the query service's), capped by dl_settings
#      backup/   root:root 0700 — deploy/backup.sh's local copy (DL_BACKUP_DIR elsewhere for offsite)
echo "== 3. The data directory $STORE"
PGDATA=$(runuser -u postgres -- psql -Atc 'show data_directory' 2>/dev/null || sudo -n -u postgres psql -Atc 'show data_directory' 2>/dev/null || true)
probe=$STORE; while [ ! -e "$probe" ] && [ "$probe" != / ]; do probe=$(dirname "$probe"); done
FS_STORE=$(df --output=target "$probe" 2>/dev/null | tail -1)
FS_PG=$( [ -n "$PGDATA" ] && df --output=target "$PGDATA" 2>/dev/null | tail -1 || true)
echo "   $STORE is on $FS_STORE; PostgreSQL's data ($PGDATA) is on ${FS_PG:-?}"
if [ -z "$FS_PG" ]; then
  echo "   could not read PostgreSQL's data directory — run as root (apply) to check" >&2
elif [ "$FS_STORE" = "$FS_PG" ] && [ "$SHARED" != 1 ]; then
  echo "REFUSED: $STORE is on the same filesystem as PostgreSQL ($FS_PG). Mount a dedicated volume at $STORE first" >&2
  echo "         (a new disk → pvcreate → vgcreate lake-vg → lvcreate → mkfs.ext4 → /etc/fstab with noatime → mount), or pass" >&2
  echo "         --allow-shared-volume for a scratch proof only." >&2
  [ "$MODE" = apply ] && exit 3
fi
run install -d -o root -g root -m 755 "$STORE"
run install -d -o www-data -g datalake -m 2770 "$STORE/landing"
run install -d -o datalake-writer -g datalake -m 750 "$STORE/lake"
run install -d -o datalake-writer -g www-data -m 2750 "$STORE/exports"
run install -d -o datalake-writer -g datalake -m 2770 "$STORE/tmp"
run install -d -o root -g root -m 700 "$STORE/backup"

# ------------------------------------------------------------------------------------------------------------------
# 4. THE ENGINE'S PYTHON (idempotent): the application's own venv from services/requirements.txt (pinned: duckdb==1.5.6, pyarrow,
#    sqlglot, psycopg, python-calamine, openpyxl, xlrd — design §6.1; NO Polars: this host's CPU is x86-64-v2). Root-owned, read-only to
#    the services. A wheel newer than the pins is smoke-tested on this CPU first (tests/engine/cpu.sh).
echo "== 4. The venv services/.venv from services/requirements.txt"
if [ ! -f "$APP/services/requirements.txt" ]; then
  echo "   services/requirements.txt is not there yet (the engine's) — skipped"
else
  [ -x "$APP/services/.venv/bin/python" ] || run python3 -m venv "$APP/services/.venv"
  run "$APP/services/.venv/bin/pip" install -q --disable-pip-version-check -r "$APP/services/requirements.txt"
fi

# ------------------------------------------------------------------------------------------------------------------
# 5. DUCKDB'S EXTENSIONS, VENDORED (idempotent): fetched ONCE for the pinned version (ducklake, postgres_scanner, excel) into a root-owned
#    read-only directory; at run time autoinstall and autoload are off, so nothing is downloaded and nothing unvetted loads.
echo "== 5. DuckDB's extensions into $EXT"
if [ ! -x "$APP/services/bin/fetch_extensions.sh" ] && [ ! -f "$APP/services/bin/fetch_extensions.sh" ]; then
  echo "   services/bin/fetch_extensions.sh is not there yet (the engine's) — skipped"
else
  run install -d -o root -g root -m 755 "$EXT"
  run bash "$APP/services/bin/fetch_extensions.sh" "$EXT"
  run chmod -R a-w,a+rX "$EXT"
  envset DL_EXTENSION_DIR "$EXT"
fi

# ------------------------------------------------------------------------------------------------------------------
# 6. THE MASKING SALT (idempotent: made once, never replaced — a new salt is a new dl_settings.mask_salt_id and old hashes stop joining).
#    32 random bytes, root:datalake-query 0440: the query service alone reads it (design §3 rule 4).
echo "== 6. The masking salt $SALT"
run install -d -o root -g root -m 755 "$(dirname "$SALT")"
if [ ! -s "$SALT" ]; then
  run bash -c "umask 077; head -c 32 /dev/urandom > '$SALT'"
else
  echo "   $SALT exists — kept"
fi
run chown root:datalake-query "$SALT"
run chmod 0440 "$SALT"
envset DL_MASK_SALT_FILE "$SALT"

# ------------------------------------------------------------------------------------------------------------------
# 6b. THE QUERY SERVICE'S POOL (idempotent; before step 7's apply). The kernel's installer enables every unit maludb-os.json lists with
#    `systemctl enable --now`, which a TEMPLATE refuses (no instance) — so datalake-query@.service is rendered and installed HERE, and
#    datalake-query.target (listed in services[]) starts @1…@$WORKERS. Rendered from config/.env: APP_DIR and DL_STORAGE_DIR.
#    Stricter still (a note, not a step): point DL_QUERY_DB_DSN at PostgreSQL's Unix socket (host=/var/run/postgresql) with a pg_hba
#    peer map datalake-query → datalake_query, and drop IPAddressAllow=localhost from the unit — then a worker reaches nothing by IP.
echo "== 6b. The query service's template unit → /etc/systemd/system/datalake-query@.service"
if [ "$MODE" = apply ]; then
  sed -e "s#{{APP_DIR}}#$APP#g" -e "s#{{DL_STORAGE_DIR}}#$STORE#g" "$APP/deploy/datalake-query@.service" > /etc/systemd/system/datalake-query@.service.new
  if ! cmp -s /etc/systemd/system/datalake-query@.service.new /etc/systemd/system/datalake-query@.service 2>/dev/null; then
    mv /etc/systemd/system/datalake-query@.service.new /etc/systemd/system/datalake-query@.service && systemctl daemon-reload && echo "   installed"
  else
    rm -f /etc/systemd/system/datalake-query@.service.new; echo "   unchanged"
  fi
else
  echo "+ render deploy/datalake-query@.service (APP_DIR=$APP, DL_STORAGE_DIR=$STORE) → /etc/systemd/system/; systemctl daemon-reload"
fi

# ------------------------------------------------------------------------------------------------------------------
# 7. THE INSTALLER'S APPLY AND THE HIRES (printed, never run here) — after 0–6b. database.provision (deploy/os-provision.sh) applies
#    db/*.sql with its ledger, gives the engine's two roles their logins (DL_LAKE_DB_DSN, DL_QUERY_DB_DSN into config/.env), makes the
#    schema ducklake, attaches DuckLake (services/bin/ducklake_init.py as datalake-writer) and creates the views of db/engine/. Run it twice:
#    the roles are read from app_roles only once the records MCP answers (Phase 4).
echo "== 7. The installer (printed, not run)"
echo "   php $KERNEL/bin/app_install.php plan $APP --by <super-admin email> --domain subello.com          (read-only)"
echo "   sudo php $KERNEL/bin/app_install.php apply $APP --by <super-admin email> --domain subello.com --hire-agents"
echo "   — --grant-standing-departments gives every standing department the Member role (key user): a Member reads only what a grant names,"
echo "     so it is safe, but grant Analysts and Data engineers by name. --hire-agents hires the Data Lake Expert and the Data Steward"
echo "     (weekdays 06:40, agents[].duty). Then: DNS and TLS for datalake.<domain>; the connections a super-admin approves for the lake's"
echo "     reads[] (the GL's account_balances, ar_aging_summary, ap_due_summary; Inventory's sales_closed; Sales CRM's deals_won)."
echo "   The owner's too: the VM's CPU type to host / x86-64-v3 (no AVX today; a restart) — then rerun tests/engine/cpu.sh;"
echo "   remove /var/crash/_usr_bin_python3.12.1000.crash (left by the research's Polars test)."
