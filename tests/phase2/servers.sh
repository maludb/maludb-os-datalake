#!/usr/bin/env bash
# tests/phase2/servers.sh start|stop — the proofs' servers, all from the scratch environment ($DL_DEV_ENV):
#   the application on :9001, a fake kernel on :9002, the fake MaluDB + MaluMail on :9003, THE ENGINE — the writer (one process) and the
#   query service ($DL_QUERY_WORKERS instances on the sockets <DL_QUERY_SOCKET>-N.sock under var/run) — exactly as their units run them.
#   The application is `php -S` with tests/dev_router.php by default; with DL_APP=apache it is a REAL Apache (as your user, mod_php) serving
#   deploy/apache-datalake.conf rendered the way the kernel's installer renders it (the public vhost moved to :9001, the internal one on
#   :9007) plus one proof-only Alias (/__proof/ → tests/phase2/routes/, never in the deploy file) — the proofs then exercise the vhost's own
#   rewrites. State: $DL_DEV_STATE (default /tmp/dl-dev2-state): the pid files, the fake kernel's state file, the logs.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${DL_DEV_ENV:-/tmp/dl-dev2.env}
STATE=${DL_DEV_STATE:-/tmp/dl-dev2-state}
mkdir -p "$STATE"
stop() {
  for f in "$STATE"/*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  pkill -f 'php .*-S 127.0.0.1:900[123]' 2>/dev/null || true       # the built-in server's forked workers outlive their parent
  pkill -f "$ROOT/services/lake/writer_main.py" 2>/dev/null || true
  pkill -f "$ROOT/services/query/server.py --worker" 2>/dev/null || true
  [ -f "$STATE/apache/main.conf" ] && apache2 -f "$STATE/apache/main.conf" -k stop 2>/dev/null || true
  sleep 0.4
}
case "${1:-}" in
  stop) stop ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    export FAKE_KERNEL_STATE="$STATE/kernel.json" FAKE_MALUDB_LOG="$STATE/maludb.log" FAKE_MALUMAIL_LOG="$STATE/malumail.log"
    : > "$FAKE_MALUDB_LOG"; : > "$FAKE_MALUMAIL_LOG"; rm -f "$FAKE_KERNEL_STATE".{chat,apps}
    php -r '$f=json_decode(file_get_contents($argv[1]),true); file_put_contents($argv[2], json_encode(["feed"=>$f["feed"]]));' "$ROOT/bin/dev_directory.json" "$FAKE_KERNEL_STATE"
    php -S 127.0.0.1:9002 "$ROOT/tests/fake_kernel.php" >"$STATE/kernel.log" 2>&1 & echo $! > "$STATE/kernel.pid"
    php -S 127.0.0.1:9003 "$ROOT/tests/fake_maludb.php" >"$STATE/fake.log" 2>&1 & echo $! > "$STATE/fake.pid"
    # the engine, as its units run it (services/.venv; the passfile DSNs the provision script wrote)
    "$ROOT/services/.venv/bin/python" "$ROOT/services/lake/writer_main.py" >"$STATE/writer.log" 2>&1 & echo $! > "$STATE/writer.pid"
    for n in $(seq 1 "${DL_QUERY_WORKERS:-2}"); do
      "$ROOT/services/.venv/bin/python" "$ROOT/services/query/server.py" --worker "$n" >"$STATE/query-$n.log" 2>&1 & echo $! > "$STATE/query-$n.pid"
    done
    if [ "${DL_APP:-php}" = "apache" ]; then
      mkdir -p "$STATE/apache"
      python3 - "$ROOT" "$STATE/apache" <<'PY'
import os, sys
root, out = sys.argv[1], sys.argv[2]
v = open(root + '/deploy/apache-datalake.conf').read()
for k, val in {'APP_FQDN': 'datalake.subello.com', 'APP_DIR': root, 'APP_INTERNAL_PORT': '9007', 'MCP_RECORDS_PORT': '9004', 'MCP_ACTIVITY_PORT': '9005'}.items():
    v = v.replace('{{%s}}' % k, val)
assert '{{' not in v, 'unfilled placeholder in the vhost template'
v = v.replace('<VirtualHost *:80>', '<VirtualHost *:9001>')
# the proof-only door (never in the deploy file): the gate's fixture route
proof = ('\tAlias /__proof/ %s/tests/phase2/routes/\n\t<Directory %s/tests/phase2/routes>\n\t\tRequire all granted\n\t</Directory>\n' % (root, root))
v = v.replace('\tRewriteEngine On\n\tRewriteRule ^/mcp/', '\tRewriteEngine On\n\tRewriteRule ^/__proof/ - [L]\n\tRewriteRule ^/mcp/', 1).replace('</VirtualHost>', proof + '</VirtualHost>', 1)
open(out + '/site.conf', 'w').write(v)
mods = [m for m in ('headers', 'rewrite', 'proxy', 'proxy_http', 'alias') if not os.path.exists('/etc/apache2/mods-enabled/%s.load' % m)]
open(out + '/main.conf', 'w').write('ServerRoot "/etc/apache2"\nDefaultRuntimeDir %s\nPidFile %s/apache.pid\nErrorLog %s/apache.err\nServerName localhost\nLogLevel warn\n'
    'Include /etc/apache2/mods-enabled/*.load\nInclude /etc/apache2/mods-enabled/*.conf\n%sDefine APACHE_LOG_DIR %s\nListen 9001\nInclude %s/site.conf\n'
    % (out, out, out, ''.join('LoadModule %s_module /usr/lib/apache2/modules/mod_%s.so\n' % (m, m) for m in mods), out, out))
PY
      # mod_php reads the application's environment from the process: the scratch .env is exported above, so the vhost needs no SetEnv.
      apache2 -f "$STATE/apache/main.conf" -k start
    else
      ( cd "$ROOT" && PHP_CLI_SERVER_WORKERS=4 php -d upload_max_filesize=64M -d post_max_size=72M -S 127.0.0.1:9001 -t html tests/dev_router.php >"$STATE/app.log" 2>&1 & echo $! > "$STATE/app.pid" )
    fi
    for i in $(seq 1 40); do curl -s -o /dev/null http://127.0.0.1:9001/api/v1/health && break; sleep 0.25; done
    for i in $(seq 1 40); do [ -S "${DL_QUERY_SOCKET}-1.sock" ] && break; sleep 0.25; done
    echo "servers up (app :9001 ${DL_APP:-php}, fake kernel :9002, fake MaluDB + MaluMail :9003, the writer, ${DL_QUERY_WORKERS:-2} query workers)" ;;
  *) echo "usage: $0 start|stop" >&2; exit 2 ;;
esac
