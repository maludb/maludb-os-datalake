"""A metric's request, compiled to the SQL the query service runs (query-service.md §7 `metric`; transforms-products-lineage.md "Divergences").

A metric is an aggregate defined once over a PRODUCT (design §6.5, D13): an expression (`sum(amount)`), a time column, the grains it may be cut by
(day, week, month, quarter, year), the dimensions it may be cut by, and a fixed predicate. The CALLER reads the metric under its own wall
(mcp_metrics) and sends its definition with the request — the service reads no application table. This module judges the definition and the cut
(a grain the metric does not allow, a `by` or `where` key that is not one of its dimensions, an expression that is not one aggregate over the
product's own columns) and writes the SELECT; the server then runs that SELECT EXACTLY as it runs a `sql` request: the allow-list (so the
expression and the predicate — an engineer's text — can name nothing the allow-list forbids), the caller's policy for the product, the masks and row
filters, the lock, the watchdog, the audit (carrying the metric's id). Nothing here widens what a caller may read.

    compile_metric(metric, grain, frm, to, by, where) -> Compiled(sql, params, grain, by, time_column)     or raises MetricRefused(field, message)

The SELECT (a time column):
    SELECT date_trunc('<grain>', "<time>") AS period, "<by1>", …, (<expression>) AS value FROM <dataset>
     WHERE "<time>" >= $mfrom AND "<time>" < $mto [AND (<filters>)] [AND "<dim>" = $w0 | "<dim>" IN ($w1, $w2) | "<dim>" IS NULL] GROUP BY ALL ORDER BY period, "<by1>", …
Without a time column there is no `period`, no grain and no window. Every value — the window's ends, a `where` value — is a bound parameter.
"""
from __future__ import annotations

import re
from dataclasses import dataclass

import sqlglot
from sqlglot import exp

GRAINS = ("day", "week", "month", "quarter", "year")
IDENT = re.compile(r"^[A-Za-z_][A-Za-z0-9_]{0,62}$")
KEY = re.compile(r"^(raw|curated|product|sandbox)\.[a-z][a-z0-9_]{0,62}$")
DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
MAX_BY = 4
MAX_WHERE_VALUES = 50


class MetricRefused(Exception):
    """A metric request the service refuses (`refusal_rule: invalid_metric`): `field` names what to fix (expression, filters, grain, by, where, from, to, time_column, dataset)."""

    def __init__(self, field: str, message: str):
        super().__init__(message)
        self.field, self.message = field, message


@dataclass
class Compiled:
    sql: str
    params: dict
    grain: str | None
    by: list[str]
    time_column: str | None


def _qi(name: str) -> str:
    return '"' + name.replace('"', '""') + '"'


def _text(v, field: str, limit: int) -> str:
    if not isinstance(v, str) or not v.strip():
        raise MetricRefused(field, f"The {field} is missing.")
    v = v.strip()
    if len(v) > limit:
        raise MetricRefused(field, f"The {field} is longer than {limit} characters.")
    for bad in (";", "--", "/*", "*/"):
        if bad in v:
            raise MetricRefused(field, f"The {field} may not contain “{bad}” — one expression, no comment.")
    return v


def _parse_expression(text: str, field: str) -> exp.Expression:
    try:
        tree = sqlglot.parse_one(text, read="duckdb")
    except Exception as e:                                           # sqlglot's ParseError / TokenError
        raise MetricRefused(field, f"The {field} could not be read: {str(e).splitlines()[0][:160]}")
    if tree is None or isinstance(tree, (exp.Query, exp.DDL, exp.DML, exp.Command, exp.Set)):
        raise MetricRefused(field, f"The {field} is one expression over the product's columns, not a statement.")
    for node in tree.walk():
        if isinstance(node, (exp.Select, exp.Subquery, exp.Union, exp.Table, exp.Placeholder, exp.Parameter)):
            raise MetricRefused(field, f"The {field} may name only the product's own columns — no subquery, no other table, no parameter.")
        if isinstance(node, exp.Window):
            raise MetricRefused(field, f"The {field} may not be a window function.")
    return tree


def check_expression(text) -> str:
    """The metric's expression: ONE aggregate expression over the product's columns (sum(amount), count(DISTINCT customer_id), sum(a) / nullif(sum(b), 0))."""
    t = _text(text, "expression", 1000)
    tree = _parse_expression(t, "expression")
    if not any(isinstance(n, exp.AggFunc) for n in tree.walk()):
        raise MetricRefused("expression", "The expression is an aggregate: sum(amount), count(*), count(DISTINCT customer_id), avg(price).")
    return t


def check_filters(text) -> str | None:
    """The metric's fixed predicate: a condition over the product's columns, no aggregate."""
    if text is None or (isinstance(text, str) and not text.strip()):
        return None
    t = _text(text, "filters", 1000)
    tree = _parse_expression(t, "filters")
    if any(isinstance(n, exp.AggFunc) for n in tree.walk()):
        raise MetricRefused("filters", "The filter is a condition on each row — an aggregate belongs in the expression.")
    return t


def _scalar(v, field: str):
    if v is None or isinstance(v, (bool, int, float)):
        return v
    if isinstance(v, str):
        if len(v) > 1000:
            raise MetricRefused(field, "A value is longer than 1,000 characters.")
        return v
    raise MetricRefused(field, "A value is a text, a number, a yes/no or empty.")


def compile_metric(metric, grain=None, frm=None, to=None, by=None, where=None) -> Compiled:
    if not isinstance(metric, dict):
        raise MetricRefused("metric", "The request carries no metric.")
    ds = metric.get("dataset")
    if not isinstance(ds, str) or not KEY.fullmatch(ds):
        raise MetricRefused("dataset", "A metric names the dataset it is over: <zone>.<name>.")
    expression = check_expression(metric.get("expression"))
    filters = check_filters(metric.get("filters"))
    time_col = metric.get("time_column") or None
    if time_col is not None and (not isinstance(time_col, str) or not IDENT.fullmatch(time_col)):
        raise MetricRefused("time_column", "The time column is a column's name.")
    grains = [g for g in (metric.get("grains") or []) if isinstance(g, str)]
    if any(g not in GRAINS for g in grains):
        raise MetricRefused("grain", "A metric's grains are day, week, month, quarter or year.")
    dims = metric.get("dimensions") or []
    if not isinstance(dims, list) or not all(isinstance(d, str) and IDENT.fullmatch(d) for d in dims):
        raise MetricRefused("dimensions", "A metric's dimensions are column names.")
    by = by or []
    if not isinstance(by, list) or not all(isinstance(b, str) for b in by):
        raise MetricRefused("by", "`by` is a list of dimensions.")
    if len(by) > MAX_BY or len(set(by)) != len(by):
        raise MetricRefused("by", f"Cut by up to {MAX_BY} different dimensions.")
    for b in by:
        if b not in dims:
            raise MetricRefused("by", f"{b} is not one of this metric's dimensions" + (f" ({', '.join(dims)})." if dims else " — it has none."))
    where = where or {}
    if not isinstance(where, dict):
        raise MetricRefused("where", "`where` is {dimension: value or [values]}.")
    params: dict = {}
    predicates: list[str] = []
    if time_col is not None:
        if grain in (None, ""):
            grain = grains[0] if grains else "month"
        if grain not in GRAINS or (grains and grain not in grains):
            raise MetricRefused("grain", f"{grain} is not one of this metric's grains" + (f" ({', '.join(grains)})." if grains else "."))
        for name, val, op in (("from", frm, ">="), ("to", to, "<")):
            if val in (None, ""):
                continue
            if not isinstance(val, str) or not DATE.fullmatch(val):
                raise MetricRefused(name, f"`{name}` is a date: YYYY-MM-DD.")
            params["m" + name] = val
            predicates.append(f"{_qi(time_col)} {op} $m{name}")
    else:
        grain = None
    if filters:
        predicates.append(f"({filters})")
    n = 0
    for dim, val in where.items():
        if not isinstance(dim, str) or dim not in dims:
            raise MetricRefused("where", f"{dim} is not one of this metric's dimensions" + (f" ({', '.join(dims)})." if dims else " — it has none."))
        if isinstance(val, list):
            if not val or len(val) > MAX_WHERE_VALUES:
                raise MetricRefused("where", f"Filter {dim} by 1 to {MAX_WHERE_VALUES} values.")
            names = []
            for v in val:
                params[f"w{n}"] = _scalar(v, "where")
                names.append(f"$w{n}")
                n += 1
            predicates.append(f"{_qi(dim)} IN ({', '.join(names)})")
        elif val is None:
            predicates.append(f"{_qi(dim)} IS NULL")
        else:
            params[f"w{n}"] = _scalar(val, "where")
            predicates.append(f"{_qi(dim)} = $w{n}")
            n += 1
    select = []
    order = []
    if time_col is not None:
        select.append(f"date_trunc('{grain}', {_qi(time_col)}) AS period")
        order.append("period")
    for b in by:
        select.append(_qi(b))
        order.append(_qi(b))
    select.append(f"({expression}) AS value")
    sql = f"SELECT {', '.join(select)} FROM {ds}"
    if predicates:
        sql += " WHERE " + " AND ".join(predicates)
    sql += " GROUP BY ALL"
    if order:
        sql += " ORDER BY " + ", ".join(order)
    return Compiled(sql=sql, params=params, grain=grain, by=list(by), time_column=time_col)
