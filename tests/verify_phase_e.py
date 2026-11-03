"""Phase E (Module 4) backend checks: fee tiers, consult-fee suggestion, intake vitals,
call-next, not_available status, consultation bill on finalize, and split/partial payment."""
import os, sys
sys.path.insert(0, os.path.dirname(__file__))
from _client import call, check, new_doctor, finish

tok, did = new_doctor("Dr PhaseE")
tok2, did2 = new_doctor("Dr Other")
print(f"CLEANUP_IDS: {did} {did2}")


def walkin(pid, force=False, case_type=None):
    body = {"patient_id": pid, "walk_in": True, "reason": "Fever"}
    if force: body["force"] = True
    if case_type: body["case_type"] = case_type
    s, r = call("POST", "appointments", body, tok)
    return s, r


def finalize_visit(pid, appt, diagnosis="Viral fever", body=None):
    s, r = call("POST", "prescriptions", {"patient_id": pid, "appointment_id": appt["id"], "status": "draft", "diagnosis": diagnosis}, tok)
    rx = r["data"]["prescription"]
    s, r = call("POST", f"prescriptions/{rx['id']}/finalize", body, tok)
    return s, r, rx


# ── 4.2 fee tiers round-trip on the clinic ───────────────────────────────────
s, r = call("PUT", "doctor/clinic", {
    "name": "PhaseE Clinic", "mobile": "9876500000", "consultation_fee": 250,
    "fee_new": 300, "fee_old": 200, "fee_emergency": 500, "old_case_window_days": 30, "free_next_day": False,
}, tok)
check("save fee tiers", s == 200, f"{s} {r}")
c = r["data"]["clinic"]
check("fee_new saved", c.get("fee_new") == 300, c)
check("fee_old saved", c.get("fee_old") == 200, c)
check("fee_emergency saved", c.get("fee_emergency") == 500, c)
check("window saved", c.get("old_case_window_days") == 30, c)
check("free_next_day saved", c.get("free_next_day") is False, c)

s, r = call("GET", "doctor/clinic", token=tok)
check("clinic GET reflects tiers", r["data"]["clinic"].get("fee_new") == 300, r["data"]["clinic"])

# bad tier rejected
s, r = call("PUT", "doctor/clinic", {"name": "PhaseE Clinic", "fee_new": -5}, tok)
check("negative tier rejected", s == 422, s)

# ── patient + consult-fee suggestion (new case) ──────────────────────────────
s, r = call("POST", "patients", {"name": "Fee Patient", "sex": "male", "age_years": 40, "mobile": "9123400010"}, tok)
check("create patient", s == 201, f"{s} {r}")
pid = r["data"]["patient"]["id"]

s, r = call("GET", f"doctor/consult-fee?patient_id={pid}", token=tok)
check("consult-fee ok", s == 200, s)
f = r["data"]["fee"]
check("first visit -> new case", f.get("case_type") == "new", f)
check("new-case fee = fee_new (300)", f.get("fee") == 300, f)

# ── 4.3 finalize raises the consultation bill (new case, no prior visit) ──────
s, r = walkin(pid, case_type="new")
check("walk-in created", s == 201, f"{s} {r}")
appt = r["data"]["appointment"]
check("case_type on appointment", appt.get("case_type") == "new", appt)

s, r, rx = finalize_visit(pid, appt)
check("finalize ok", s == 200, f"{s} {r}")

s, r = call("GET", "appointments", token=tok)
mine = next((a for a in r["data"]["items"] if a["id"] == appt["id"]), None)
check("appointment now completed", mine and mine["status"] == "completed", mine)
check("queue shows the consultation bill", mine and mine.get("bill") and mine["bill"]["due"] == 300, mine and mine.get("bill"))
bill_id = mine["bill"]["id"]

# ── 4.3 split payment (Cash 100 + Card 200 = 300) ────────────────────────────
s, r = call("POST", f"bills/{bill_id}/payment", {"payments": [{"amount": 100, "method": "cash"}, {"amount": 200, "method": "card"}]}, tok)
check("split payment ok", s == 200, f"{s} {r}")
b = r["data"]["bill"]
check("split paid in full", b["status"] == "paid" and b["paid"] == 300, b)
check("two payment rows recorded", len(b["payments"]) == 2, b["payments"])

# ── old-case derivation + partial payment ────────────────────────────────────
s, r = call("GET", f"doctor/consult-fee?patient_id={pid}", token=tok)
f = r["data"]["fee"]
check("return visit -> old case", f.get("case_type") == "old", f)
check("old-case fee = fee_old (200)", f.get("fee") == 200, f)

s, r = walkin(pid, force=True, case_type="old")
appt2 = r["data"]["appointment"]
s, r, rx2 = finalize_visit(pid, appt2)
check("second finalize ok", s == 200, f"{s} {r}")
s, r = call("GET", "appointments", token=tok)
mine2 = next((a for a in r["data"]["items"] if a["id"] == appt2["id"]), None)
check("old-case bill = 200", mine2 and mine2.get("bill") and mine2["bill"]["due"] == 200, mine2 and mine2.get("bill"))
bid2 = mine2["bill"]["id"]

# partial collect: 120 of 200
s, r = call("POST", f"bills/{bid2}/payment", {"amount": 120, "method": "upi"}, tok)
check("partial payment ok", s == 200, f"{s} {r}")
check("bill now partial, due 80", r["data"]["bill"]["status"] == "partial" and r["data"]["bill"]["due"] == 80, r["data"]["bill"])
# overpay rejected
s, r = call("POST", f"bills/{bid2}/payment", {"amount": 500, "method": "cash"}, tok)
check("overpay rejected", s == 422, s)

# ── explicit fee override on finalize ────────────────────────────────────────
s, r = walkin(pid, force=True)
appt3 = r["data"]["appointment"]
s, r, rx3 = finalize_visit(pid, appt3, body={"consultation_fee": 555})
check("override finalize ok", s == 200, f"{s} {r}")
s, r = call("GET", "appointments", token=tok)
mine3 = next((a for a in r["data"]["items"] if a["id"] == appt3["id"]), None)
check("override bill = 555", mine3 and mine3.get("bill") and mine3["bill"]["due"] == 555, mine3 and mine3.get("bill"))

# ── 4.1 intake vitals ────────────────────────────────────────────────────────
s, r = walkin(pid, force=True)
intk = r["data"]["appointment"]
s, r = call("POST", f"appointments/{intk['id']}/intake",
            {"bp_systolic": 120, "bp_diastolic": 80, "pulse_bpm": 72, "rbs_fasting_mg_dl": 95, "rbs_pp_mg_dl": 140, "weight_kg": 70.5}, tok)
check("save intake vitals", s == 200, f"{s} {r}")
iv = r["data"]["intake"]
check("intake FBS/PPBS round-trip", iv["rbs_fasting_mg_dl"] == 95 and iv["rbs_pp_mg_dl"] == 140, iv)
check("intake BP round-trip", iv["bp_systolic"] == 120 and iv["bp_diastolic"] == 80, iv)
check("intake weight round-trip", iv["weight_kg"] == 70.5, iv)

s, r = call("GET", f"appointments/{intk['id']}/intake", token=tok)
check("GET intake returns the row", s == 200 and r["data"]["intake"]["pulse_bpm"] == 72, r["data"].get("intake"))

# out-of-range + diastolic>=systolic rejected
s, r = call("POST", f"appointments/{intk['id']}/intake", {"rbs_fasting_mg_dl": 5}, tok)
check("out-of-range FBS rejected", s == 422, s)
s, r = call("POST", f"appointments/{intk['id']}/intake", {"bp_systolic": 120, "bp_diastolic": 130}, tok)
check("diastolic>=systolic rejected", s == 422, s)

# ── 4.4 call-next ────────────────────────────────────────────────────────────
s, r = call("POST", f"appointments/{intk['id']}/call", token=tok)
check("call next ok", s == 200, f"{s} {r}")
check("called_at is set", r["data"]["appointment"].get("called_at") is not None, r["data"]["appointment"])

# ── 4.4 not_available status transitions ─────────────────────────────────────
s, r = walkin(pid, force=True)
na = r["data"]["appointment"]
s, r = call("POST", f"appointments/{na['id']}/status", {"status": "not_available"}, tok)
check("arrived -> not_available", s == 200 and r["data"]["appointment"]["status"] == "not_available", f"{s} {r}")
s, r = call("POST", f"appointments/{na['id']}/status", {"status": "arrived"}, tok)
check("not_available -> arrived", s == 200 and r["data"]["appointment"]["status"] == "arrived", f"{s} {r}")
# illegal jump
s, r = call("POST", f"appointments/{na['id']}/status", {"status": "no_show"}, tok)
check("arrived -> no_show blocked", s == 409, s)

# ── create-with-status-final path also raises the bill (the Flutter editor's finish) ──
s, r = walkin(pid, force=True)
appt4 = r["data"]["appointment"]
s, r = call("POST", "prescriptions", {"patient_id": pid, "appointment_id": appt4["id"], "status": "final", "diagnosis": "Cold", "consultation_fee": 275}, tok)
check("create+final ok", s == 201, f"{s} {r}")
s, r = call("GET", "appointments", token=tok)
mine4 = next((a for a in r["data"]["items"] if a["id"] == appt4["id"]), None)
check("create+final raises bill (275)", mine4 and mine4.get("bill") and mine4["bill"]["due"] == 275, mine4 and mine4.get("bill"))

# ── the receptionist's manual walk-in tag wins over history derivation ───────
# pid has prior final visits, so history would derive "old"; tag this one "new".
s, r = walkin(pid, force=True, case_type="new")
appt5 = r["data"]["appointment"]
check("walk-in tagged new (overrides derived old)", appt5.get("case_type") == "new", appt5)
s, r = call("GET", f"doctor/consult-fee?patient_id={pid}&appointment_id={appt5['id']}", token=tok)
check("consult-fee honours the walk-in tag (new, 300)", r["data"]["fee"].get("case_type") == "new" and r["data"]["fee"].get("fee") == 300, r["data"]["fee"])
s, r = call("GET", f"doctor/consult-fee?patient_id={pid}", token=tok)
check("without an appointment it still derives (old)", r["data"]["fee"].get("case_type") == "old", r["data"]["fee"])
s, r, rx5 = finalize_visit(pid, appt5)  # empty body: no explicit fee, no case_type
check("tagged finalize ok", s == 200, f"{s} {r}")
s, r = call("GET", "appointments", token=tok)
mine5 = next((a for a in r["data"]["items"] if a["id"] == appt5["id"]), None)
check("bill priced off the walk-in tag (fee_new 300)", mine5 and mine5.get("bill") and mine5["bill"]["due"] == 300, mine5 and mine5.get("bill"))
check("walk-in tag not overwritten by finalize", mine5 and mine5.get("case_type") == "new", mine5)

# ── multi-tenant isolation ───────────────────────────────────────────────────
s, r = call("GET", f"appointments/{intk['id']}/intake", token=tok2)
check("other doctor can't read intake (404)", s == 404, s)
s, r = call("GET", f"doctor/consult-fee?patient_id={pid}", token=tok2)
check("other doctor can't price my patient (422)", s == 422, s)
s, r = call("POST", f"appointments/{intk['id']}/call", token=tok2)
check("other doctor can't call my patient (404)", s == 404, s)

finish()
