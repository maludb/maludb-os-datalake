"""Synthetic fixtures for the engine proof — this repository is PUBLIC: every name, email, phone and card number here is
invented (emails at example.invalid, phones in the 555-01xx fiction range, card numbers from the networks' published TEST
ranges). Each file carries the defects the proof looks for. Deterministic: rerunning writes identical bytes (except the
XLSX's zip timestamps, which openpyxl fixes to a constant below).

    services/.venv/bin/python tests/fixtures/make_fixtures.py [outdir]
"""
from __future__ import annotations

import datetime as dt
import json
import os
import sys

import openpyxl
import pyarrow as pa
import pyarrow.parquet as pq

OUT = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(os.path.abspath(__file__))

# Published test card numbers (Visa, Mastercard, Amex, Discover test ranges) — Luhn-valid, never real accounts.
TEST_CARDS = ["4111111111111111", "5555555555554444", "378282246310005", "6011111111111117", "4012888888881881"]

CUSTOMERS = [
    # id (leading zeros!), name, email, phone, card, city (a quoted delimiter), signed_up, balance
    ("00042", "Ada Example", "ada.example@example.invalid", "+1-555-0101", TEST_CARDS[0], "Portland, OR", "2026-01-15", "120.50"),
    ("00043", "Bo Sample", "bo.sample@example.invalid", "+1-555-0102", TEST_CARDS[1], "Austin, TX", "2026-02-01", "0"),
    ("00044", "Cy Testcase", "cy.testcase@example.invalid", "+1-555-0103", TEST_CARDS[2], "Denver", "2026-02-11", "75.25"),
    ("00045", "Di Fixture", "di.fixture@example.invalid", "+1-555-0104", TEST_CARDS[3], "Boston, MA", "2026-03-09", "15"),
    ("00046", "Ed Mock", "ed.mock@example.invalid", "+1-555-0105", TEST_CARDS[4], "Chicago, IL", "2026-03-30", "9.99"),
    ("00047", "Fay Dummy", "fay.dummy@example.invalid", "+1-555-0106", TEST_CARDS[0], "Seattle, WA", "not-a-date", "abc"),
    ("00042", "Ada Example", "ada.new@example.invalid", "+1-555-0101", TEST_CARDS[0], "Portland, OR", "2026-01-15", "130.00"),
]


def q(v: str) -> str:
    return '"' + v.replace('"', '""') + '"' if ("," in v or '"' in v) else v


def write_customers_csv() -> None:
    lines = ["customer_id,name,email,phone,card_number,city,signed_up,balance"]
    for r in CUSTOMERS[:3]:
        lines.append(",".join(q(v) for v in r))
    lines.append('00099,Bad Row,bad@example.invalid,+1-555-0199,4111111111111111,Nowhere,2026-01-01,1,EXTRA')  # too many columns
    for r in CUSTOMERS[3:]:
        lines.append(",".join(q(v) for v in r))
    with open(os.path.join(OUT, "customers.csv"), "w", encoding="utf-8", newline="\n") as f:
        f.write("\n".join(lines) + "\n")


def write_customers_v2_csv() -> None:
    """The same shape plus a column the first load never saw (schema drift)."""
    lines = ["customer_id,name,email,phone,card_number,city,signed_up,balance,loyalty_tier"]
    lines.append(",".join(q(v) for v in ("00050", "Gus Placeholder", "gus.placeholder@example.invalid", "+1-555-0107",
                                          TEST_CARDS[1], "Miami, FL", "2026-04-02", "44.00", "gold")))
    lines.append(",".join(q(v) for v in ("00051", "Hal Stub", "hal.stub@example.invalid", "+1-555-0108",
                                          TEST_CARDS[2], "Reno, NV", "2026-04-03", "5.00", "silver")))
    with open(os.path.join(OUT, "customers_v2.csv"), "w", encoding="utf-8", newline="\n") as f:
        f.write("\n".join(lines) + "\n")


def write_suppliers_cp1252_csv() -> None:
    """Windows-1252 — é and a curly quote (0x92) that Latin-1 decodes wrongly; DuckDB reads UTF-8/UTF-16/Latin-1 only."""
    text = ("supplier_id,name,note\n"
            "007,Café Résumé Supplies,It’s fine\n"
            "008,Naïve Widgets,€ price list\n")
    with open(os.path.join(OUT, "suppliers_cp1252.csv"), "wb") as f:
        f.write(text.encode("cp1252"))


def write_orders_xlsx() -> None:
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Orders"
    ws.append(["order_id", "customer_id", "ordered_on", "amount", "status"])
    rows = [("SO-1001", "00042", dt.date(2026, 5, 1), 120.5, "shipped"),
            ("SO-1002", "00043", dt.date(2026, 5, 2), 99.0, "open"),
            ("SO-1003", "00044", dt.date(2026, 5, 3), 15.25, "shipped"),
            ("SO-1004", "00042", dt.date(2026, 5, 4), 300.0, "cancelled")]
    for r in rows:
        ws.append(list(r))
    s2 = wb.create_sheet("Summary")
    s2.append(["region", "Q1", None, "Q2", None])          # a merged header over two columns each
    s2.merge_cells("B1:C1")
    s2.merge_cells("D1:E1")
    s2.append([None, "units", "revenue", "units", "revenue"])
    s2.append(["North", 10, 1000.0, 12, 1250.0])
    s2.append(["South", 7, 640.5, 9, 810.0])
    wb.properties.created = dt.datetime(2026, 1, 1)
    wb.properties.modified = dt.datetime(2026, 1, 1)
    wb.save(os.path.join(OUT, "orders.xlsx"))


def write_events_ndjson() -> None:
    events = [
        {"event_id": 1, "kind": "login", "member": "ada.example@example.invalid", "at": "2026-05-01T08:00:00Z",
         "meta": {"ip": "192.0.2.10", "agent": "test"}},
        {"event_id": 2, "kind": "export", "member": "bo.sample@example.invalid", "at": "2026-05-01T09:30:00Z",
         "meta": {"ip": "198.51.100.7", "rows": 120}},
        {"event_id": 3, "kind": "login", "member": "cy.testcase@example.invalid", "at": "2026-05-02T10:00:00Z", "meta": None},
    ]
    with open(os.path.join(OUT, "events.ndjson"), "w", encoding="utf-8", newline="\n") as f:
        for e in events:
            f.write(json.dumps(e, sort_keys=True) + "\n")


def write_products_parquet() -> None:
    t = pa.table({
        "sku": ["SKU-001", "SKU-002", "SKU-003"],
        "name": ["Widget", "Gadget", "Gizmo"],
        "list_price": pa.array([9.99, 24.5, 3.0], pa.float64()),
        "launched": pa.array([dt.date(2025, 1, 1), dt.date(2025, 6, 1), dt.date(2026, 2, 1)], pa.date32()),
    })
    pq.write_table(t, os.path.join(OUT, "products.parquet"), compression="zstd")


if __name__ == "__main__":
    os.makedirs(OUT, exist_ok=True)
    write_customers_csv()
    write_customers_v2_csv()
    write_suppliers_cp1252_csv()
    write_orders_xlsx()
    write_events_ndjson()
    write_products_parquet()
    print("fixtures written to", OUT)
