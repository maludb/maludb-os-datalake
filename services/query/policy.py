"""The caller's policy → the caller's views — layer 2 of the query service (design §3, §6.5).

The policy is compiled in PostgreSQL by ``dl_query_policy()`` (the ONE place the walls of §3 are compiled) and arrives as::

    {"caller": int, "on_behalf_of": int|null,
     "datasets": {"<zone>.<name>": {"level": "read"|"read_unmasked", "columns": ["c1", ...],
                                    "masks": {"col": "full"|"partial_email"|"partial"|"hash"|"null"|"year_only"},
                                    "row_filter": "<predicate over the dataset's columns — literals only>"|null}},
     "limits": {"timeout_s": n, "row_limit": n, "result_bytes": n, "memory": "1.5GB", "threads": 2},
     "salt_hex": "..."}

Each granted dataset becomes ONE temporary view over the attached lake: only the listed columns, each masked column replaced
by its mask expression, the row filter as the view's WHERE, the as-of snapshot as ``AT (VERSION => n)``. The caller's SQL
is rewritten to name only these views. A policy that cannot be honoured exactly (an unknown column, a filter with anything
but columns, literals and comparisons) is refused — fail closed.
"""
from __future__ import annotations

import re

import sqlglot
from sqlglot import exp

MASKS = ("full", "partial_email", "partial", "hash", "null", "year_only")
LEVELS = ("read", "read_unmasked")
_FILTER_NODES = (exp.Column, exp.Identifier, exp.Literal, exp.Boolean, exp.Null, exp.And, exp.Or, exp.Not, exp.Paren,
                 exp.EQ, exp.NEQ, exp.GT, exp.GTE, exp.LT, exp.LTE, exp.Is, exp.In, exp.Between, exp.Like, exp.ILike,
                 exp.Array, exp.Tuple, exp.Neg, exp.Cast, exp.DataType, exp.DataTypeParam,
                 exp.Any)     # slice 3: db/008's bound department filter is `department_id = ANY(ARRAY[7]::BIGINT[])` (query-service §5)


class PolicyError(Exception):
    pass


def validate(policy: dict) -> dict:
    if not isinstance(policy, dict) or not isinstance(policy.get("datasets"), dict):
        raise PolicyError("the policy has no datasets")
    if not isinstance(policy.get("caller"), int):
        raise PolicyError("the policy names no caller")
    lim = policy.get("limits") or {}
    for k in ("timeout_s", "row_limit", "result_bytes"):
        if not isinstance(lim.get(k), int) or lim[k] <= 0:
            raise PolicyError(f"limits.{k} is missing")
    salt = policy.get("salt_hex")
    if not isinstance(salt, str) or not re.fullmatch(r"[0-9a-f]{32,128}", salt):
        raise PolicyError("salt_hex is missing or not hex")
    for key, d in policy["datasets"].items():
        if not re.fullmatch(r"(raw|curated|product|sandbox|quarantine)\.[a-z][a-z0-9_]{0,62}", key):
            raise PolicyError(f"{key!r} is not a dataset key")
        if d.get("level") not in LEVELS:
            raise PolicyError(f"{key}: level must be read or read_unmasked")
        if not isinstance(d.get("columns"), list) or not d["columns"]:
            raise PolicyError(f"{key}: no columns")
        for col, m in (d.get("masks") or {}).items():
            if m not in MASKS:
                raise PolicyError(f"{key}.{col}: unknown mask {m!r}")
            if col not in d["columns"]:
                raise PolicyError(f"{key}.{col}: a mask on a column the caller does not see")
        if d["level"] == "read_unmasked" and d.get("masks"):
            raise PolicyError(f"{key}: read_unmasked carries masks")
    return policy


def view_name(key: str) -> str:
    zone, name = key.split(".", 1)
    return f"dlv_{zone}__{name}"


def _qi(n: str) -> str:
    return '"' + n.replace('"', '""') + '"'


def mask_expr(col: str, typ: str, mask: str, salt_hex: str) -> str:
    c = _qi(col)
    t = typ.upper()
    txt = f"CAST({c} AS VARCHAR)"
    if mask == "full":
        return f"CASE WHEN {c} IS NULL THEN NULL ELSE '***' END"
    if mask == "partial_email":
        return f"CASE WHEN {c} IS NULL THEN NULL ELSE regexp_replace({txt}, '^(.)[^@]*', '\\1***') END"
    if mask == "partial":
        return (f"CASE WHEN {c} IS NULL THEN NULL WHEN length({txt}) <= 2 THEN '**' "
                f"ELSE left({txt}, 1) || repeat('*', least(length({txt}) - 2, 6)) || right({txt}, 1) END")
    if mask == "hash":
        # keyed (installation salt) and stable, so joins on a hashed key still work; the salt never leaves this process
        return f"CASE WHEN {c} IS NULL THEN NULL ELSE 'h:' || left(sha256('{salt_hex}' || {txt}), 16) END"
    if mask == "null":
        return f"CAST(NULL AS {typ})"
    if mask == "year_only":
        if t == "DATE":
            return f"make_date(year({c}), 1, 1)"
        if t.startswith("TIMESTAMP"):
            return f"CAST(make_date(year({c}), 1, 1) AS {typ})"
        return f"CASE WHEN TRY_CAST({txt} AS DATE) IS NULL THEN NULL ELSE CAST(year(TRY_CAST({txt} AS DATE)) AS VARCHAR) END"
    raise PolicyError(f"unknown mask {mask}")


def check_row_filter(pred: str, columns: set[str]) -> str:
    """A row filter is a predicate over the dataset's own columns and literals — nothing else may hide in it."""
    try:
        tree = sqlglot.parse_one(pred, read="duckdb")
    except Exception as e:
        raise PolicyError(f"row filter unreadable: {e}")
    for node in tree.walk():
        if not isinstance(node, _FILTER_NODES):
            raise PolicyError(f"row filter may not contain {type(node).__name__}")
        if isinstance(node, exp.Column):
            if node.table or node.name not in columns:
                raise PolicyError(f"row filter names {node.sql()} — not a column of the dataset")
    return tree.sql(dialect="duckdb")


def create_views(con, alias: str, policy: dict, as_of: int | None = None) -> dict:
    """Create one TEMP VIEW per granted dataset; returns {key: view_name} and the list of masked ``key.column``."""
    from lake.engine import split_key
    granted: dict[str, str] = {}
    masked: list[str] = []
    absent: list[str] = []
    version = ""
    if as_of is not None:
        if not isinstance(as_of, int) or as_of < 0:
            raise PolicyError("as_of must be a snapshot number")
        version = f" AT (VERSION => {as_of})"
    for key, d in policy["datasets"].items():
        zone, name = split_key(key)
        cols = con.execute("SELECT column_name, data_type FROM duckdb_columns() WHERE database_name = ? AND schema_name = ? "
                           "AND table_name = ? ORDER BY column_index", [alias, zone, name]).fetchall()
        if not cols:
            continue  # granted but not (yet) in the lake — the allow-list will say "not granted" if it is named
        types = dict(cols)
        proj = []
        for col in d["columns"]:
            if col not in types:
                raise PolicyError(f"{key}.{col} is not a column of the dataset")
            m = (d.get("masks") or {}).get(col)
            if m:
                proj.append(f"{mask_expr(col, types[col], m, policy['salt_hex'])} AS {_qi(col)}")
                masked.append(f"{key}.{col}")
            else:
                proj.append(_qi(col))
        where = ""
        if d.get("row_filter"):
            where = " WHERE " + check_row_filter(d["row_filter"], set(types))
        vn = view_name(key)
        try:
            con.execute(f"CREATE OR REPLACE TEMP VIEW {_qi(vn)} AS SELECT {', '.join(proj)} "
                        f"FROM {alias}.{_qi(zone)}.{_qi(name)}{version}{where}")
        except Exception as e:  # the dataset did not exist at the as-of snapshot
            if version and "does not exist" in str(e):
                absent.append(key)
                masked[:] = [m for m in masked if not m.startswith(key + ".")]
                continue
            raise
        granted[key] = vn
    return {"granted": granted, "masked": masked, "absent_as_of": absent}
