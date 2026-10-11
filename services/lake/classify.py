"""Deterministic personal-data classifiers (design §6.3, D10): column names, value patterns WITH validators (Luhn for card
numbers, mod-97 for IBANs, the SSA's rules for US SSNs), and checks on a sample of values. Nothing leaves the process and no
model is involved. Each finding is a PROPOSAL — {kind, confidence, matched_fraction, by} — a person confirms.

Kinds (the design's): email, phone, name, address, national_id, payment_card, bank_account, birth_date, ip_address,
member_id, free_text_personal, other.
"""
from __future__ import annotations

import ipaddress
import re

NAME_HINTS = [
    (r"(^|_)(e_?mail|email_address)($|_)", "email"),
    (r"(^|_)(phone|mobile|cell|tel|telephone|fax)($|_)", "phone"),
    (r"(^|_)(first_?name|last_?name|full_?name|surname|given_?name|family_?name|contact_?name|customer_?name|person_?name)($|_)|^name$", "name"),
    (r"(^|_)(address|street|addr|postcode|post_code|zip|zipcode)($|_)", "address"),
    (r"(^|_)(ssn|social_security|national_id|nin|sin|tax_id|tin|passport)($|_)", "national_id"),
    (r"(^|_)(card_?number|cc_?num|credit_?card|pan)($|_)", "payment_card"),
    (r"(^|_)(iban|account_?number|acct_?no|routing_?number|sort_?code|bank_account)($|_)", "bank_account"),
    (r"(^|_)(dob|birth_?date|date_of_birth|birthday)($|_)", "birth_date"),
    (r"(^|_)(ip|ip_?address|client_?ip|remote_?addr)($|_)", "ip_address"),
    (r"(^|_)(member_?id)($|_)", "member_id"),
]
EMAIL = re.compile(r"^[A-Za-z0-9._%+'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$")
EMAIL_IN = re.compile(r"[A-Za-z0-9._%+'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}")
PHONE = re.compile(r"^\+?[\d][\d\s().-]{6,18}\d$")
SSN = re.compile(r"^(\d{3})-(\d{2})-(\d{4})$")
IBAN = re.compile(r"^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$")


def luhn_ok(digits: str) -> bool:
    total, alt = 0, False
    for ch in reversed(digits):
        d = ord(ch) - 48
        if alt:
            d *= 2
            if d > 9:
                d -= 9
        total += d
        alt = not alt
    return total % 10 == 0


def is_card(v: str) -> bool:
    d = re.sub(r"[\s-]", "", v)
    return d.isdigit() and 13 <= len(d) <= 19 and luhn_ok(d)


def is_iban(v: str) -> bool:
    s = re.sub(r"\s", "", v).upper()
    if not IBAN.match(s):
        return False
    r = s[4:] + s[:4]
    num = "".join(str(int(ch, 36)) for ch in r)
    return int(num) % 97 == 1


def is_ssn(v: str) -> bool:
    m = SSN.match(v)
    if not m:
        return False
    a, g, s = m.groups()
    return a not in ("000", "666") and not a.startswith("9") and g != "00" and s != "0000"


def is_ip(v: str) -> bool:
    try:
        ipaddress.ip_address(v)
        return True
    except ValueError:
        return False


ISO_DATE = re.compile(r"^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$")


def is_phone(v: str) -> bool:
    if not PHONE.match(v) or ISO_DATE.match(v):          # 2026-01-15 is a date, not a phone (slice 2 found the classifier said 0.9)
        return False
    digits = re.sub(r"\D", "", v)
    has_sep = bool(re.search(r"[\s().+-]", v))
    return 7 <= len(digits) <= 15 and has_sep and not is_card(v)


VALUE_TESTS = [("email", lambda v: bool(EMAIL.match(v))), ("payment_card", is_card), ("bank_account", is_iban),
               ("national_id", is_ssn), ("ip_address", is_ip), ("phone", is_phone)]


def classify_column(name: str, values: list) -> dict | None:
    """Return a proposal or None. ``values`` is a sample (up to ~1,000 non-null values as text)."""
    sample = [str(v).strip() for v in values if v not in (None, "")]
    hint = None
    for pat, kind in NAME_HINTS:
        if re.search(pat, name.lower()):
            hint = kind
            break
    best_kind, best_frac = None, 0.0
    if sample:
        for kind, test in VALUE_TESTS:
            n = sum(1 for v in sample if test(v))
            frac = n / len(sample)
            if frac > best_frac:
                best_kind, best_frac = kind, frac
        if best_frac < 0.8:
            contains = sum(1 for v in sample if EMAIL_IN.search(v) and not EMAIL.match(v)) / len(sample)
            if contains >= 0.2:
                best_kind, best_frac = "free_text_personal", contains
            elif best_frac < 0.8:
                best_kind = None
    if best_kind and hint and (best_kind == hint):
        return {"kind": hint, "confidence": round(min(1.0, 0.6 + best_frac * 0.4), 2), "matched_fraction": round(best_frac, 3),
                "by": "name+pattern"}
    if best_kind:
        return {"kind": best_kind, "confidence": round(0.5 + best_frac * 0.4, 2), "matched_fraction": round(best_frac, 3),
                "by": "pattern"}
    if hint:
        return {"kind": hint, "confidence": 0.6 if not sample else 0.5, "matched_fraction": None, "by": "name"}
    return None
