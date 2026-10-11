"""The allow-list — layer 1 of the query service's four (design §6.5). Parses the caller's SQL with sqlglot (DuckDB dialect)
and either REFUSES it, naming the rule, or REWRITES every dataset key to the caller's view and returns the SQL to run.

Allowed: exactly one statement; a query (SELECT, WITH … SELECT, UNION / INTERSECT / EXCEPT of those); tables that are
``<zone>.<name>`` dataset keys present in the caller's policy, or names of the statement's own CTEs. Everything else is
refused — DDL, DML, SET, PRAGMA, ATTACH, COPY, INSTALL, LOAD, CALL, EXPORT, DESCRIBE, SUMMARIZE (all arrive as a non-query
root or a Command); a table that is a function (read_csv, glob, query_table, duckdb_databases(), ducklake_list_files() —
the last returns the files' ENCRYPTION KEYS, FINDINGS §4); a function anywhere whose name is a DuckDB table function or on the
deny list (getenv, current_setting …); a quoted file path as a table; a catalog-qualified name (``lake.raw.t`` reaches the
attached lake past the views — FINDINGS §5); ``AT (VERSION => …)`` (time travel is the ``as_of`` parameter, applied in the
view); unbound parameters; a tree over the node budget.
"""
from __future__ import annotations

import logging
from dataclasses import dataclass

import sqlglot
from sqlglot import exp

logging.getLogger("sqlglot").setLevel(logging.ERROR)  # "falling back to parsing as a Command" — we refuse Commands anyway

ZONES = ("raw", "curated", "product", "sandbox", "quarantine")
NODE_BUDGET = 5000

# Names refused anywhere, in addition to every table function the engine knows (``table_function_names``).
DENY_FUNCTIONS = {"getenv", "current_setting", "load_extension", "install_extension", "read_text", "read_blob",
                  "glob", "query", "query_table", "sniff_csv", "which_secret", "set_variable", "getvariable"}
DENY_PREFIXES = ("read_", "duckdb_", "pragma_", "ducklake_", "postgres_", "parquet_", "iceberg_", "delta_", "sqlite_",
                 "mysql_", "json_execute", "pg_", "load_", "httpfs", "s3_", "dl_")


class Refusal(Exception):
    """A refusal with the rule that made it — the query service answers ``status: refused`` with ``refusal_rule``."""

    def __init__(self, rule: str, message: str, dataset: str | None = None):
        super().__init__(message)
        self.rule = rule
        self.message = message
        self.dataset = dataset


@dataclass
class Checked:
    sql: str                      # the rewritten SQL — references only the caller's views
    datasets: list[str]           # the dataset keys it reads, in first-use order


def table_function_names(con) -> set[str]:
    """Every table function / table macro the connection knows — refused by name anywhere in the caller's SQL."""
    return {r[0].lower() for r in con.execute(
        "SELECT DISTINCT function_name FROM duckdb_functions() WHERE function_type IN ('table', 'table_macro')").fetchall()}


def _func_name(f: exp.Func) -> str:
    if isinstance(f, exp.Anonymous):
        return str(f.name).lower()
    try:
        return f.sql_name().lower()
    except Exception:
        return type(f).__name__.lower()


def _denied(name: str, table_functions: set[str]) -> bool:
    return name in DENY_FUNCTIONS or name in table_functions or name.startswith(DENY_PREFIXES)


def check(sql: str, granted: dict[str, str], *, table_functions: set[str] = frozenset(), params: dict | None = None,
          node_budget: int = NODE_BUDGET) -> Checked:
    """``granted`` maps each dataset key the caller may read to the view name that stands for it."""
    if not isinstance(sql, str) or not sql.strip():
        raise Refusal("empty", "There is no SQL to run.")
    if len(sql) > 100_000:
        raise Refusal("too_long", "The SQL is longer than 100,000 characters.")
    try:
        statements = [s for s in sqlglot.parse(sql, read="duckdb") if s is not None]
    except sqlglot.errors.ParseError as e:
        raise Refusal("parse", f"The SQL could not be read: {str(e).splitlines()[0][:200]}")
    except Exception as e:  # sqlglot's tokenizer errors and the like
        raise Refusal("parse", f"The SQL could not be read: {str(e)[:200]}")
    if len(statements) != 1:
        raise Refusal("one_statement", "Exactly one statement may run at a time.")
    tree = statements[0]
    if not isinstance(tree, (exp.Select, exp.Union, exp.Intersect, exp.Except)):
        kind = type(tree).__name__.upper()
        raise Refusal("select_only", f"Only a SELECT may run here ({kind} is not allowed).")
    n = 0
    for node in tree.walk():
        n += 1
        if n > node_budget:
            raise Refusal("too_complex", f"The query is larger than {node_budget} parts.")
        if isinstance(node, (exp.DDL, exp.DML, exp.Command, exp.Set, exp.Pragma, exp.Copy, exp.Into, exp.Use,
                             exp.Transaction, exp.Commit, exp.Rollback, exp.Merge, exp.Describe, exp.Summarize)) \
                or type(node).__name__ in ("Attach", "Detach", "Install", "Export", "LoadData"):
            raise Refusal("select_only", f"{type(node).__name__.upper()} is not allowed inside a query.")
        if isinstance(node, exp.Func):
            name = _func_name(node)
            if _denied(name, set(table_functions)):
                raise Refusal("function", f"The function {name}() is not allowed.")
        if isinstance(node, (exp.Placeholder, exp.Parameter)):
            if not params or str(node.name) not in params:
                raise Refusal("unbound_parameter", f"The parameter {node.sql()} has no value.")
    cte_names = {str(c.alias).lower() for c in tree.find_all(exp.CTE)}
    used: list[str] = []
    for table in list(tree.find_all(exp.Table)):
        this = table.this
        if not isinstance(this, exp.Identifier):
            name = _func_name(this) if isinstance(this, exp.Func) else type(this).__name__.lower()
            raise Refusal("table_function", f"Reading from {name}() is not allowed — name a dataset.")
        if table.args.get("version") is not None or table.args.get("when") is not None:
            raise Refusal("time_travel", "AT (VERSION …) is not allowed in the SQL — ask with the as-of option.")
        parts = [p.name for p in table.parts]
        if len(parts) >= 3:
            raise Refusal("catalog_reference", f"{'.'.join(parts)} is not a dataset key — name <zone>.<dataset>.")
        if len(parts) == 1:
            if parts[0].lower() in cte_names and not this.quoted:
                continue
            raise Refusal("unknown_table", f"{parts[0]} is not a dataset key — name <zone>.<dataset>.")
        key = f"{parts[0].lower()}.{parts[1].lower()}"
        if parts[0].lower() not in ZONES:
            raise Refusal("unknown_table", f"{key} is not a dataset of the lake.")
        if key not in granted:
            raise Refusal("not_granted", f"You may not read {key} — request access.", dataset=key)
        if key not in used:
            used.append(key)
        alias = table.alias or parts[1]
        new = exp.Table(this=exp.to_identifier(granted[key], quoted=True),
                        alias=exp.TableAlias(this=exp.to_identifier(alias, quoted=True)))
        table.replace(new)
    # a column qualified by the dataset key (curated.deals.amount) now names the alias
    for col in tree.find_all(exp.Column):
        if col.args.get("db") is not None:
            if col.args.get("catalog") is not None:
                raise Refusal("catalog_reference", f"{col.sql()} is not a column reference this lake understands.")
            col.set("db", None)
    if params:
        for ph in list(tree.find_all(exp.Placeholder)) + list(tree.find_all(exp.Parameter)):
            v = params[str(ph.name)]
            ph.replace(exp.convert(v))
    out = tree.sql(dialect="duckdb")
    return Checked(sql=out, datasets=used)


class _AnyKey(dict):
    """The first pass before a policy exists: every ``<zone>.<name>`` stands for itself, so the shape is judged and the keys collected."""

    def __contains__(self, key) -> bool:
        return True

    def __getitem__(self, key):
        return key


def keys_of(sql: str, *, table_functions: set[str] = frozenset(), params: dict | None = None) -> list[str]:
    """The dataset keys a SQL reads, in first-use order — every refusal of the shape (one statement, SELECT only, functions, catalog
    references, time travel, unbound parameters, the node budget) raised here, before the service asks for a policy (query-service §5.1)."""
    return check(sql, _AnyKey(), table_functions=table_functions, params=params).datasets

