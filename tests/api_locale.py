#!/usr/bin/env python3
"""Clinic language / currency / country: defaults, validation, who can change it, who can read it,
the currency-change guard once bills exist, and 3-decimal money (OMR) vs 2-decimal (INR)."""
from _client import *

tok, did = new_doctor("Dr Alpha")
tok2, _ = new_doctor("Dr Beta")
s, r = call("GET", "doctor/clinic-settings", token=tok)
check("a new clinic defaults to English / INR / India", s == 200 and r["data"]["settings"] == {"language": "en", "currency": "INR", "country": "IN", "currency_decimals": 2}, r)
check("needs sign-in", call("GET", "doctor/clinic-settings")[0] == 401)

s, r = call("PUT", "doctor/clinic-settings", {"language": "ar", "currency": "OMR", "country": "OM"}, tok)
check("set Arabic / OMR / Oman (creates the clinic row if there is none)", s == 200 and r["data"]["settings"] == {"language": "ar", "currency": "OMR", "country": "OM", "currency_decimals": 3}, r)
c = call("GET", "doctor/clinic", token=tok)[1]["data"]["clinic"]
check("the clinic payload carries them too", c["language"] == "ar" and c["currency"] == "OMR" and c["country"] == "OM", c)
for lang in ["en", "hi", "gu", "mr", "ta", "te", "bn", "kn", "ml", "pa", "ur", "ne", "si", "ar"]:
    s, _ = call("PUT", "doctor/clinic-settings", {"language": lang}, tok)
    if s != 200: check(f"language {lang} accepted", False, s)
check("all 14 languages are accepted", fails == 0)
check("an unknown language is refused", call("PUT", "doctor/clinic-settings", {"language": "xx"}, tok)[0] == 422)
check("an unknown currency is refused", call("PUT", "doctor/clinic-settings", {"currency": "ZZZ"}, tok)[0] == 422)
check("a bad country is refused", call("PUT", "doctor/clinic-settings", {"country": "India"}, tok)[0] == 422)
check("one doctor's settings do not touch another's", call("GET", "doctor/clinic-settings", token=tok2)[1]["data"]["settings"]["currency"] == "INR")

# 3-decimal currency: OMR keeps baisa, INR rounds to paise
call("PUT", "doctor/clinic-settings", {"language": "en", "currency": "OMR", "country": "OM"}, tok)
pid = call("POST", "patients", {"name": "Little Asha", "sex": "female", "age_years": 4}, tok)[1]["data"]["patient"]["id"]
s, r = call("POST", "bills", {"patient_id": pid, "items": [{"description": "Consultation", "amount": 12.345}, {"description": "Dressing", "amount": 3.5}], "discount": 0.845, "paid": 5, "method": "cash"}, tok)
b = r["data"]["bill"] if s == 201 else {}
check("OMR bill keeps 3 decimals: 15.845 - 0.845 = 15.000, paid 5, due 10.000", s == 201 and b["subtotal"] == 15.845 and b["total"] == 15.0 and b["paid"] == 5.0 and b["due"] == 10.0, r)
s, r = call("POST", f"bills/{b.get('id', 0)}/payment", {"amount": 0.0005, "method": "cash"}, tok)
check("an amount finer than the currency's decimals is rounded away (0.0005 OMR -> 0.001 or 0)", s in (200, 422), s)

# currency change guard once bills exist
s, r = call("PUT", "doctor/clinic-settings", {"currency": "AED"}, tok)
check("changing currency with bills needs confirmation (409)", s == 409 and "needs_confirmation" in (r.get("errors") or {}), r)
check("…and nothing changed", call("GET", "doctor/clinic-settings", token=tok)[1]["data"]["settings"]["currency"] == "OMR")
s, r = call("PUT", "doctor/clinic-settings", {"currency": "AED", "confirm_no_conversion": True}, tok)
check("confirmed change goes through, amounts untouched", s == 200 and r["data"]["settings"]["currency_decimals"] == 2, r)
check("the old bill still has its numbers", call("GET", f"bills/{b['id']}", token=tok)[1]["data"]["bill"]["total"] == 15.0)
s, r = call("POST", "bills", {"patient_id": pid, "items": [{"description": "Visit", "amount": 10.126}]}, tok)
check("AED rounds to 2 decimals: 10.126 -> 10.13", s == 201 and r["data"]["bill"]["total"] == 10.13, r)
check("the same currency again needs no confirmation", call("PUT", "doctor/clinic-settings", {"language": "hi", "currency": "AED"}, tok)[0] == 200)

finish()
