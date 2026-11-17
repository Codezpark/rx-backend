#!/usr/bin/env python3
"""End of day: patient list by status, money by method, bills vs payments, past days, role and isolation."""
from _client import *
import datetime as dt, subprocess

MYSQL = "/Applications/XAMPP/xamppfiles/bin/mysql"
def sql(q): subprocess.run([MYSQL, "-uroot", "php_rest_api", "-e", q], check=True)

today = dt.datetime.utcnow().date()
day = lambda n: (today + dt.timedelta(days=n)).isoformat()
q = lambda date=None: f"eod?today={day(0)}&tz_offset=0" + (f"&date={date}" if date else "")

tok, did = new_doctor("Dr Alpha")
tok2, _ = new_doctor("Dr Beta")
check("needs sign-in", call("GET", "eod")[0] == 401)

# ── an empty day ────────────────────────────────────────────────────────────
s, r = call("GET", q(), token=tok)
d = r["data"]
check("empty day: zeros and an empty list", s == 200 and d["patients"] == [] and d["totals"]["patients"] == 0 and d["charges"]["collected"] == 0 and d["is_today"] is True, r)
check("past days: 14 entries, newest first, ends today", len(d["days"]) == 14 and d["days"][0]["date"] == day(0) and d["days"][-1]["date"] == day(-13), d["days"][:2])
check("a future date is clamped to today", call("GET", q(day(3)), token=tok)[1]["data"]["date"] == day(0))

mk = lambda name, t=tok, **kw: call("POST", "patients", {"name": name, "sex": "female", "age_years": 30, **kw}, t)[1]["data"]["patient"]["id"]
asha, ben, chen, dev = mk("Asha Seen"), mk("Ben Waiting"), mk("Chen Cancelled"), mk("Dev BillOnly")

# Asha: walk-in, prescription finalised (completes the visit), consultation bill part paid in cash.
ap_a = call("POST", "appointments", {"patient_id": asha, "walk_in": True, "reason": "Fever"}, tok)[1]["data"]["appointment"]["id"]
s, r = call("POST", "prescriptions", {"patient_id": asha, "appointment_id": ap_a, "status": "final", "diagnosis": "Viral fever", "medicines": [{"medicine_name": "Paracetamol 500", "duration_days": 3, "dose_schedule": [{"slot": "morning", "qty": 1}]}]}, tok)
assert s == 201, r
rx = r["data"]["prescription"]["id"]
bill_a = call("POST", "bills", {"patient_id": asha, "prescription_id": rx, "items": [{"description": "Consultation", "amount": 500}], "paid": 300, "method": "cash"}, tok)[1]["data"]["bill"]["id"]
# Ben: waiting in the queue, nothing billed yet.
call("POST", "appointments", {"patient_id": ben, "walk_in": True}, tok)
# Chen: booked then cancelled.
ap_c = call("POST", "appointments", {"patient_id": chen, "walk_in": True}, tok)[1]["data"]["appointment"]["id"]
call("POST", f"appointments/{ap_c}/status", {"status": "cancelled"}, tok)
# Dev: no visit, only a bill (paid by UPI).
bill_d = call("POST", "bills", {"patient_id": dev, "items": [{"description": "Dressing", "amount": 200}], "paid": 200, "method": "upi"}, tok)[1]["data"]["bill"]["id"]

s, r = call("GET", q(), token=tok)
d = r["data"]
by = {p["name"]: p for p in d["patients"]}
check("seen patient: status, diagnosis, prescription, money", s == 200 and by["Asha Seen"]["status"] == "seen" and by["Asha Seen"]["diagnosis"] == "Viral fever" and by["Asha Seen"]["prescription_id"] == rx and by["Asha Seen"]["billed"] == 500 and by["Asha Seen"]["paid"] == 300 and by["Asha Seen"]["due"] == 200, by.get("Asha Seen"))
check("waiting patient listed as waiting", by["Ben Waiting"]["status"] == "waiting", by.get("Ben Waiting"))
check("cancelled patient listed as missed", by["Chen Cancelled"]["status"] == "missed", by.get("Chen Cancelled"))
check("bill-only patient is listed apart as billing", by["Dev BillOnly"]["status"] == "billing" and by["Dev BillOnly"]["paid"] == 200, by.get("Dev BillOnly"))
check("order: seen, waiting, missed, billing", [p["status"] for p in d["patients"]] == ["seen", "waiting", "missed", "billing"], [p["status"] for p in d["patients"]])
t = d["totals"]
check("totals count visits only (billing-only excluded)", t["seen"] == 1 and t["waiting"] == 1 and t["missed"] == 1 and t["patients"] == 2, t)
check("only the seen patient counts as a new patient today", t["new_patients"] == 1 and by["Asha Seen"]["is_new"] is True, t)
c = d["charges"]
check("collected = 300 cash + 200 upi", c["collected"] == 500 and c["by_method"]["cash"] == 300 and c["by_method"]["upi"] == 200 and c["payments"] == 2, c)
check("billed 700 across 2 bills, 200 still due", c["billed"] == 700 and c["bills"] == 2 and c["due"] == 200, c)

# ── a later payment on an older bill counts on the day it is received ────────
sql(f"update bills set bill_date = utc_timestamp() - interval 3 day where id = {bill_a}")
call("POST", f"bills/{bill_a}/payment", {"amount": 150, "method": "card"}, tok)
c = call("GET", q(), token=tok)[1]["data"]["charges"]
check("old bill paid today: counted today and flagged as from earlier bills", c["collected"] == 650 and c["by_method"]["card"] == 150 and c["from_earlier_bills"] == 150 + 300, c)

# ── cancelling a bill removes its money from the day ─────────────────────────
call("POST", f"bills/{bill_d}/cancel", {}, tok)
c = call("GET", q(), token=tok)[1]["data"]["charges"]
check("cancelled bill no longer counts", c["by_method"]["upi"] == 0 and c["collected"] == 450, c)

# ── looking at a past day ────────────────────────────────────────────────────
sql(f"update prescriptions set visit_date = utc_timestamp() - interval 2 day where id = {rx}")
sql(f"update bill_payments set paid_at = utc_timestamp() - interval 2 day where bill_id = {bill_a} and method = 'cash'")
d2 = call("GET", q(day(-2)), token=tok)[1]["data"]
check("two days ago: the seen patient and the cash taken", d2["date"] == day(-2) and d2["is_today"] is False and [p["name"] for p in d2["patients"] if p["status"] == "seen"] == ["Asha Seen"] and d2["charges"]["by_method"]["cash"] == 300, d2["charges"])
pd = {x["date"]: x for x in call("GET", q(), token=tok)[1]["data"]["days"]}
check("past-days row for that date", pd[day(-2)]["patients"] == 1 and pd[day(-2)]["prescriptions"] == 1 and pd[day(-2)]["collected"] == 300, pd[day(-2)])
check("today's past-days row has the later card payment", pd[day(0)]["collected"] == 150, pd[day(0)])

# ── time zone: the doctor's day follows tz_offset ────────────────────────────
s, r = call("GET", f"eod?today={day(0)}&tz_offset=330", token=tok)
check("tz_offset shifts the window by 5h30", s == 200 and r["data"]["from"].endswith("18:30:00") and r["data"]["from"][:10] == day(-1), r["data"]["from"])

# ── role and isolation ───────────────────────────────────────────────────────
phone = "9" + "".join(random.choice("0123456789") for _ in range(9))
call("POST", "staff", {"name": "Reception", "countrycode": "+91", "phone": phone, "password": "Secret123"}, tok)
rtok = call("POST", "auth/login-new", {"identifier": phone, "password": "Secret123"})[1]["data"]["access_token"]
check("the receptionist cannot open the day's totals", call("GET", q(), token=rtok)[0] == 403)
d3 = call("GET", q(), token=tok2)[1]["data"]
check("another doctor sees none of it", d3["patients"] == [] and d3["charges"]["collected"] == 0, d3)
finish()
