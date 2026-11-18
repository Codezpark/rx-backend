#!/usr/bin/env python3
"""Pediatric dose reference: the endpoint, matching, caps, bands, formulation choice, list/search, validation."""
from _client import *

tok, _ = new_doctor("Dr Alpha")
get = lambda q: call("GET", "catalog/pediatric-dose?" + q, token=tok)

check("needs sign-in", call("GET", "catalog/pediatric-dose?generic=Cefixime&weight=10")[0] == 401)

# the worked example: 13.5 kg x 40 mg/kg/day, 3 doses, 250 mg/5 ml => 180 mg => 3.6 ml
s, r = get("generic=Amoxicillin&weight=13.5&strength=250&per_ml=5&form=SYR")
d = r["data"]
check("found, calculated", s == 200 and d["found"] and d["calculated"], r)
check("daily 540 mg, single 180 mg, 3 a day", d["daily"] == 540 and d["single"] == 180 and d["doses_per_day"] == 3, d)
f = d["formulations"][d["chosen"]]
check("3.6 ml of the 250 mg/5 ml syrup", f["strength"] == 250 and f["ml_per_dose"] == 3.6, f)
check("steps spell the maths out", len(d["steps"]) >= 3 and "13.5 kg" in d["steps"][0] and "3.6 ml" in d["steps"][-1], d["steps"])
check("drug details: reference, brands, safety text", d["drug"]["verified"] is True and "Novamox" in d["drug"]["brands"] and d["drug"]["contraindications"], d["drug"])
check("every formulation is listed with its own volume", len(d["formulations"]) >= 5 and all("suitable" in x for x in d["formulations"]), len(d["formulations"]))

# brand / spelling / combinations
check("old spelling matches", get("generic=Amoxycillin&weight=10")[1]["data"].get("found") is True)
check("Acetaminophen matches paracetamol", get("generic=Acetaminophen&weight=10")[1]["data"]["drug"]["slug"] == "paracetamol")
check("a paracetamol combination is not matched", get("generic=Paracetamol%2BPhenylephrine%2BChlorpheniramine&weight=10")[1]["data"].get("found") is False)
check("brand name works when there is no generic", get("generic=&name=Calpol%20250&weight=10")[1]["data"]["drug"]["slug"] == "paracetamol")
check("unknown drug -> found false", get("generic=Nonexistol&weight=12")[1]["data"].get("found") is False)
check("by slug", get("drug=cefixime&weight=10")[1]["data"]["drug"]["name"] == "Cefixime")

# co-amoxiclav: the 228.5 label is 200 mg amoxicillin
d = get("generic=Amoxicillin%2BClavulanate&weight=10&strength=228.5&per_ml=5")[1]["data"]
f = d["formulations"][d["chosen"]]
check("co-amoxiclav is dosed by the amoxicillin component (5.6 ml, not 4.9)", d["single"] == 225 and f["strength"] == 200 and f["strength_total"] == 228.5 and f["ml_per_dose"] == 5.6, f)

# caps, bands, age
d = get("generic=Paracetamol&weight=80")[1]["data"]
check("paracetamol single dose capped at 1000 mg", d["single"] == 1000 and d["capped_single"] is True, d)
d = get("generic=Cetirizine&weight=12")[1]["data"]
check("cetirizine asks for the age", d["calculated"] is False and d["needs_age"] is True, d)
d = get("generic=Cetirizine&weight=12&age_months=30")[1]["data"]
check("cetirizine at 30 months: 2.5 mg twice a day", d["single"] == 2.5 and d["doses_per_day"] == 2, d)
d = get("generic=Oseltamivir&weight=20")[1]["data"]
check("oseltamivir by weight band: 45 mg", d["single"] == 45, d)
d = get("generic=Ibuprofen&weight=4&age_months=1")[1]["data"]
check("age and weight warnings come back", len([a for a in d["alerts"] if "minimum" in a["text"]]) == 2, d["alerts"])
d = get("generic=Cefixime&weight=10&form=TAB")[1]["data"]
check("a tablet medicine gets a tablet formulation", d["formulations"][d["chosen"]]["is_liquid"] is False, d["formulations"][d["chosen"]])

# list / search
s, r = call("GET", "catalog/pediatric-drugs", token=tok)
check("list: the full reference", s == 200 and r["data"]["total"] >= 50, r["data"]["total"])
names = [i["name"] for i in call("GET", "catalog/pediatric-drugs?q=scabies", token=tok)[1]["data"]["items"]]
check("search by indication", "Ivermectin" in names, names)
names = [i["name"] for i in call("GET", "catalog/pediatric-drugs?q=calpol", token=tok)[1]["data"]["items"]]
check("search by brand", names == ["Paracetamol"], names)

# exact age from the date of birth
import datetime as dt
today = dt.datetime.utcnow().date()
def months_ago(m):
    y, mo = divmod(today.month - 1 - m, 12)
    return dt.date(today.year + y, mo + 1, min(today.day, 28))
d = get(f"generic=Cetirizine&weight=12&dob={months_ago(23).isoformat()}")[1]["data"]
check("23 months old by date of birth: once a day, age is exact", d["age_months"] == 23 and d["age_exact"] is True and d["doses_per_day"] == 1, (d["age_months"], d["doses_per_day"]))
d = get(f"generic=Cetirizine&weight=12&dob={months_ago(24).isoformat()}")[1]["data"]
check("24 months old by date of birth: twice a day", d["age_months"] == 24 and d["doses_per_day"] == 2, (d["age_months"], d["doses_per_day"]))
check("the age in years is returned (decimal)", d["age_years"] == 2.0, d["age_years"])
d = get("generic=Cetirizine&weight=12&age_months=30")[1]["data"]
check("an age given only in months is not exact, and the clinician is told", d["age_exact"] is False and any("date of birth" in a["text"] for a in d["alerts"]), d["alerts"])
check("a future date of birth -> 422", get("generic=Cetirizine&weight=12&dob=2999-01-01")[0] == 422)
check("a junk date of birth -> 422", get("generic=Cetirizine&weight=12&dob=banana")[0] == 422)

# audit trail: a calculator-assisted dose is flagged on the saved prescription line
pid = call("POST", "patients", {"name": "Little Asha", "sex": "female", "age_years": 3, "date_of_birth": months_ago(30).isoformat()}, tok)[1]["data"]["patient"]["id"]
line = lambda name, **kw: {"medicine_name": name, "form_prefix": "SYR", "qty_unit": "ml", "dose_schedule": [{"slot": "morning", "qty": 3.6}, {"slot": "afternoon", "qty": 3.6}, {"slot": "night", "qty": 3.6}], "duration_days": 5, **kw}
note = "Pediatric calculator: 13.5 kg, 2 y 6 m, 40 mg/kg/day in 3 doses = 180 mg = 3.6 ml of 250 mg/5 ml."
s, r = call("POST", "prescriptions", {"patient_id": pid, "status": "final", "diagnosis": "Otitis media", "medicines": [
    line("Novamox DS", is_auto_calculated=True, calc_note=note),
    line("Manual syrup"),
    line("Sneaky note", calc_note="should be dropped, the flag is off"),
]}, tok)
check("prescription saved", s == 201, r)
meds = r["data"]["prescription"]["medicines"]
check("the calculator line is flagged, with its note", meds[0]["is_auto_calculated"] is True and meds[0]["calc_note"] == note, meds[0])
check("a hand-written line is not flagged", meds[1]["is_auto_calculated"] is False and meds[1]["calc_note"] is None, meds[1])
check("a note without the flag is not stored", meds[2]["is_auto_calculated"] is False and meds[2]["calc_note"] is None, meds[2])
rid = r["data"]["prescription"]["id"]
m2 = call("GET", f"prescriptions/{rid}", token=tok)[1]["data"]["prescription"]
check("the flag survives a reload", m2["medicines"][0]["is_auto_calculated"] is True and m2["medicines"][0]["calc_note"] == note, m2["medicines"][0])
check("the patient's date of birth comes with the prescription", m2["patient"]["date_of_birth"] == months_ago(30).isoformat(), m2["patient"])

# validation
check("no weight -> 422", get("generic=Cefixime&weight=")[0] == 422)
check("weight 0 -> 422", get("generic=Cefixime&weight=0")[0] == 422)
check("weight 200 -> 422", get("generic=Cefixime&weight=200")[0] == 422)
check("bad age -> 422", get("generic=Cefixime&weight=10&age_months=-3")[0] == 422)
check("no drug -> 422", get("weight=10")[0] == 422)

finish()
