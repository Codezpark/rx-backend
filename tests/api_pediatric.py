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
check("age and weight warnings come back", len([a for a in d["alerts"] if a["level"] == "warning"]) == 2, d["alerts"])
d = get("generic=Cefixime&weight=10&form=TAB")[1]["data"]
check("a tablet medicine gets a tablet formulation", d["formulations"][d["chosen"]]["is_liquid"] is False, d["formulations"][d["chosen"]])

# list / search
s, r = call("GET", "catalog/pediatric-drugs", token=tok)
check("list: the full reference", s == 200 and r["data"]["total"] >= 50, r["data"]["total"])
names = [i["name"] for i in call("GET", "catalog/pediatric-drugs?q=scabies", token=tok)[1]["data"]["items"]]
check("search by indication", "Ivermectin" in names, names)
names = [i["name"] for i in call("GET", "catalog/pediatric-drugs?q=calpol", token=tok)[1]["data"]["items"]]
check("search by brand", names == ["Paracetamol"], names)

# validation
check("no weight -> 422", get("generic=Cefixime&weight=")[0] == 422)
check("weight 0 -> 422", get("generic=Cefixime&weight=0")[0] == 422)
check("weight 200 -> 422", get("generic=Cefixime&weight=200")[0] == 422)
check("bad age -> 422", get("generic=Cefixime&weight=10&age_months=-3")[0] == 422)
check("no drug -> 422", get("weight=10")[0] == 422)

print("\nALL PASSED" if not fails else f"\n{fails} FAILED")
sys.exit(1 if fails else 0)
