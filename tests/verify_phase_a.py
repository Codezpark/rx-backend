"""Phase A (Module 5) backend checks: FBS/PPBS vitals, clinic landline+mobile, isolation."""
import os, sys
sys.path.insert(0, os.path.dirname(__file__))
from _client import call, check, new_doctor, finish

tok, did = new_doctor("Dr PhaseA")
print(f"CLEANUP_IDS: {did}", end="")

s, r = call("POST", "patients", {"name": "FBS Tester", "sex": "male", "age_years": 40, "mobile": "9123400001"}, tok)
check("create patient", s == 201, f"{s} {r}")
pid = r["data"]["patient"]["id"]

# Prescription with the new fasting/post-prandial fields
body = {
    "patient_id": pid, "status": "final",
    "bp_systolic": 120, "bp_diastolic": 80,
    "rbs_fasting_mg_dl": 110, "rbs_pp_mg_dl": 185,
    "diagnosis": "Type 2 DM review",
}
s, r = call("POST", "prescriptions", body, tok)
check("create rx with FBS/PPBS", s == 201, f"{s} {r}")
rxid = r["data"]["prescription"]["id"]
v = r["data"]["prescription"]["vitals"]
check("FBS round-trips on create", v.get("rbs_fasting_mg_dl") == 110, v)
check("PPBS round-trips on create", v.get("rbs_pp_mg_dl") == 185, v)

s, r = call("GET", f"prescriptions/{rxid}", None, tok)
check("GET rx ok", s == 200, s)
v = r["data"]["prescription"]["vitals"]
check("FBS persisted", v.get("rbs_fasting_mg_dl") == 110, v)
check("PPBS persisted", v.get("rbs_pp_mg_dl") == 185, v)

# Out-of-range blood sugar is rejected
s, r = call("POST", "prescriptions", {"patient_id": pid, "status": "draft", "rbs_fasting_mg_dl": 5000, "diagnosis": "x"}, tok)
check("rejects out-of-range FBS", s == 422, f"{s} {r}")

# Clinic landline + mobile round-trip
s, r = call("PUT", "doctor/clinic", {"name": "Sunrise Clinic", "landline": "0261-2345678", "mobile": "9876501234", "city": "Rajkot"}, tok)
check("save clinic landline+mobile", s == 200, f"{s} {r}")
c = r["data"]["clinic"]
check("landline returned", c.get("landline") == "0261-2345678", c)
check("mobile returned", c.get("mobile") == "9876501234", c)

s, r = call("PUT", "doctor/clinic", {"name": "Sunrise Clinic", "mobile": "12345"}, tok)
check("rejects non-10-digit clinic mobile", s == 422, f"{s} {r}")

s, r = call("GET", "doctor/clinic", None, tok)
check("GET clinic ok", s == 200, s)
check("landline persisted", r["data"]["clinic"].get("landline") == "0261-2345678", r["data"]["clinic"])
check("mobile persisted", r["data"]["clinic"].get("mobile") == "9876501234", r["data"]["clinic"])

# Multi-tenant isolation: a different doctor cannot read this prescription
tokB, didB = new_doctor("Dr PhaseB")
print(f" {didB}")
s, r = call("GET", f"prescriptions/{rxid}", None, tokB)
check("isolation: other doctor cannot read the rx", s == 404, f"{s} {r}")

finish()
