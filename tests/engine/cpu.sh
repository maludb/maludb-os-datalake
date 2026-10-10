#!/usr/bin/env bash
# Smoke-test every pinned wheel of services/requirements.txt on THIS host's CPU (design §0.3: the VM is x86-64-v2, no AVX —
# a wheel built for v3 dies with an illegal instruction, not an exception). Records the CPU flags found. Run after any change
# of the VM's CPU type (the owner's (b)) and before pinning any new wheel.
set -uo pipefail
HERE="$(cd "$(dirname "$0")/../.." && pwd)"
PY="$HERE/services/.venv/bin/python"
EXT="${DL_EXTENSION_DIR:-$HERE/services/.duckdb_extensions}"
n=0; bad=0
ok()  { n=$((n+1)); echo "ok $n - $1"; }
nok() { n=$((n+1)); bad=$((bad+1)); echo "not ok $n - $1"; }

model=$(grep -m1 'model name' /proc/cpuinfo | cut -d: -f2- | sed 's/^ *//')
flags=$(grep -m1 '^flags' /proc/cpuinfo)
has() { grep -qw "$1" <<<"$flags" && echo yes || echo no; }
level=$(/lib64/ld-linux-x86-64.so.2 --help 2>/dev/null | grep -E 'x86-64-v[0-9] \(supported' | tail -1 | awk '{print $1}')
echo "# cpu: $model"
echo "# x86-64 level: ${level:-unknown}; sse4_2=$(has sse4_2) popcnt=$(has popcnt) avx=$(has avx) avx2=$(has avx2) fma=$(has fma) bmi2=$(has bmi2) avx512f=$(has avx512f)"

run() {  # name, python snippet — each in its own process so an illegal instruction kills only that check
  local name="$1" code="$2" out
  out=$("$PY" -c "$code" 2>&1); rc=$?
  if [ $rc -eq 0 ]; then ok "$name ($out)"; else nok "$name (exit $rc: $(tail -1 <<<"$out"))"; fi
}
run "duckdb 1.5.6 vectorised aggregate" "
import duckdb; c=duckdb.connect(config={'memory_limit':'1GB','threads':'2'})
r=c.execute('SELECT count(*), sum(i % 997), avg(i) FROM range(5000000) t(i)').fetchone(); assert r[0]==5000000; print(duckdb.__version__)"
run "duckdb parquet write+read (zstd)" "
import duckdb,tempfile,os; d=tempfile.mkdtemp(); p=os.path.join(d,'t.parquet'); c=duckdb.connect()
c.execute(f\"COPY (SELECT i, i::VARCHAR s FROM range(1000000) t(i)) TO '{p}' (FORMAT parquet, COMPRESSION zstd)\")
print(c.execute(f\"SELECT count(*) FROM '{p}' WHERE i % 7 = 0\").fetchone()[0])"
run "duckdb extensions load (ducklake, postgres_scanner, excel) offline" "
import duckdb; c=duckdb.connect(config={'extension_directory':'$EXT','autoinstall_known_extensions':False,'autoload_known_extensions':False})
[c.execute(f'LOAD {e}') for e in ('ducklake','postgres_scanner','excel')]; print('loaded')"
run "pyarrow 26 compute + parquet" "
import pyarrow as pa, pyarrow.compute as pc, pyarrow.parquet as pq, io
t=pa.table({'a':pa.array(range(2000000))}); b=io.BytesIO(); pq.write_table(t,b,compression='zstd'); b.seek(0)
print(pc.sum(pq.read_table(b)['a']).as_py())"
run "sqlglot parse + lineage" "
import sqlglot; from sqlglot.lineage import lineage
sch={'a':{'x':'INT','k':'INT'},'b':{'y':'INT','k':'INT'}}
n=lineage('s','SELECT a.x, sum(b.y) s FROM a JOIN b ON a.k=b.k GROUP BY 1', sch, dialect='duckdb'); assert n is not None; print(sqlglot.__version__)"
run "psycopg 3 binary" "
import psycopg; print(psycopg.__version__, psycopg.pq.__impl__)"
run "python-calamine read" "
import python_calamine, openpyxl, tempfile, os; d=tempfile.mkdtemp(); p=os.path.join(d,'t.xlsx')
wb=openpyxl.Workbook(); ws=wb.active; ws.append(['a','b']); ws.append([1,'x']); wb.save(p)
r=python_calamine.CalamineWorkbook.from_path(p).get_sheet_by_index(0).to_python(); assert r[1][1]=='x'; print(len(r))"
run "openpyxl 3.1.5" "import openpyxl; print(openpyxl.__version__)"
run "xlrd 2.0.2" "import xlrd; print(xlrd.__version__)"
echo "# $((n-bad)) of $n checks green"
[ $bad -eq 0 ]
