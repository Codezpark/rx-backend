"""Module 1 backend checks: company in search, generic+strength search, pediatric dose, form_prefix."""
import os, sys
sys.path.insert(0, os.path.dirname(__file__))
from _client import call, check, new_doctor, finish

tok, did = new_doctor("Dr Mod1")
print(f"CLEANUP_IDS: {did}")

# 1.2 — search returns manufacturer/company
s, r = call("GET", "catalog/medicines?q=Paracetamol", token=tok)
check("medicine search ok", s == 200, s)
items = r["data"]["items"]
dolo = next((m for m in items if m["name"] == "Dolo 650"), None)
check("Dolo 650 found by generic 'Paracetamol'", dolo is not None, [m["name"] for m in items][:6])
check("company returned", (dolo or {}).get("manufacturer") == "Micro Labs", dolo)

# 1.2 — generic + strength search
s, r = call("GET", "catalog/medicines?q=Paracetamol%20650", token=tok)
names = [m["name"] for m in r["data"]["items"]]
check("generic+strength 'Paracetamol 650' finds Dolo 650", "Dolo 650" in names, names[:6])

# search by company name
s, r = call("GET", "catalog/medicines?q=Micro%20Labs", token=tok)
check("search by company returns brands", any(m.get("manufacturer") == "Micro Labs" for m in r["data"]["items"]), [m["name"] for m in r["data"]["items"]][:6])

# 1.3 — pediatric dose (Cefixime 8 mg/kg/day, 10 kg, /2, syrup 100/5ml → 40 mg/dose = 2 ml)
s, r = call("GET", "catalog/pediatric-dose?generic=Cefixime&weight=10", token=tok)
check("pediatric-dose ok", s == 200, s)
d = r["data"]
check("pediatric found", d.get("found") is True, d)
check("daily 80 mg", d.get("daily_mg_max") == 80, d)
check("per-dose 40 mg (BD)", d.get("per_dose_mg_max") == 40 and d.get("divisions") == 2, d)
check("per-dose 2 ml at 100 mg/5ml", d.get("per_dose_ml_max") == 2, d)

# unknown generic → found false
s, r = call("GET", "catalog/pediatric-dose?generic=Nonexistol&weight=12", token=tok)
check("unknown generic -> found false", r["data"].get("found") is False, r["data"])

# bad weight → 422
s, r = call("GET", "catalog/pediatric-dose?generic=Cefixime&weight=0", token=tok)
check("bad weight rejected", s == 422, s)

# 1.1 — form_prefix round-trips on a prescription line
pid = None
s, r = call("POST", "patients", {"name": "Form Tester", "sex": "female", "age_years": 5, "mobile": "9123400002"}, tok)
check("create patient", s == 201, f"{s} {r}")
pid = r["data"]["patient"]["id"]

s, r = call("GET", "catalog/medicines?q=Dolo", token=tok)
med = next((m for m in r["data"]["items"] if m["name"] == "Dolo 650"), r["data"]["items"][0])
body = {
    "patient_id": pid, "status": "final", "diagnosis": "Fever",
    "medicines": [{
        "medicine_id": med["medicine_id"], "medicine_name": med["name"],
        "form_prefix": "TAB", "qty_unit": "piece",
        "dose_schedule": [{"slot": "morning", "qty": 1}, {"slot": "night", "qty": 1}],
        "duration_days": 3,
    }],
}
s, r = call("POST", "prescriptions", body, tok)
check("create rx with form_prefix", s == 201, f"{s} {r}")
m0 = r["data"]["prescription"]["medicines"][0]
check("form_prefix round-trips", m0.get("form_prefix") == "TAB", m0)
check("generic_name joined back", m0.get("generic_name") == "Paracetamol", m0)
check("manufacturer joined back", m0.get("manufacturer") == "Micro Labs", m0)

# ── form-first: filter the search by form/category (Module 1) ─────────────────
s, r = call("GET", "catalog/medicines?q=cough&category=3", token=tok)  # 3 = Syrup / Suspension
check("form-filtered search ok", s == 200, s)
cats = {m["category_id"] for m in r["data"]["items"]}
check("search narrowed to the chosen form", cats <= {3}, [(m["name"], m["category_id"]) for m in r["data"]["items"]][:6])
s, r = call("GET", "catalog/medicines?category=1", token=tok)  # browse tablets, no text
check("browse-by-form returns that form", r["data"].get("kind") == "browse" and all(m["category_id"] == 1 for m in r["data"]["items"]), r["data"].get("kind"))
s, r = call("GET", "catalog/medicines?q=Dolo&category=3", token=tok)  # Dolo is a tablet, not a syrup
check("form filter excludes other forms", all(m["name"] != "Dolo 650" for m in r["data"]["items"]), [m["name"] for m in r["data"]["items"]][:6])

# ── Syrup vs Suspension split within the one oral-liquid category (bug fix) ────
s, r = call("GET", "catalog/medicines?category=3&form=SUS", token=tok)  # browse suspensions only
sus = [m["name"] for m in r["data"]["items"]]
check("Suspension filter returns only suspensions", sus and all("susp" in n.lower() for n in sus), sus)

s, r = call("GET", "catalog/medicines?category=3&form=SYR", token=tok)  # browse syrups only
syr = [m["name"] for m in r["data"]["items"]]
check("Syrup filter excludes every suspension", syr and not any("suspension" in n.lower() for n in syr), syr)
check("Syrup filter still returns real syrups", any("syrup" in n.lower() for n in syr), syr)

s, r = call("GET", "catalog/medicines?q=a&category=3&form=SYR", token=tok)  # typing under Syrup
check("typing under Syrup never surfaces a suspension", all("suspension" not in m["name"].lower() for m in r["data"]["items"]), [m["name"] for m in r["data"]["items"]][:8])

finish()
