#!/usr/bin/env python3
"""Country layer (G8): the clinic's mobile follows its country's rule, a clinic can carry a tax / VAT number,
a patient a national ID number, and staff phones follow the clinic country's dial code."""
from _client import *

tok, did = new_doctor("Dr Country")
clinic = {"name": "Gulf Clinic", "address_line1": "Sheikh Zayed Rd", "city": "Dubai"}

# India (default): 10 digits
s, r = call("PUT", "doctor/clinic", dict(clinic, mobile="9876543210"), tok)
check("India: a 10-digit mobile is accepted", s == 200, r)
s, r = call("PUT", "doctor/clinic", dict(clinic, mobile="501234567"), tok)
check("India: a 9-digit mobile is refused", s == 422 and "mobile" in (r.get("errors") or {}), r)

# switch the clinic to the UAE: 9 digits
call("PUT", "doctor/clinic-settings", {"language": "en", "currency": "AED", "country": "AE"}, tok)
s, r = call("PUT", "doctor/clinic", dict(clinic, mobile="501234567"), tok)
check("UAE: a 9-digit mobile is accepted", s == 200, r)
s, r = call("PUT", "doctor/clinic", dict(clinic, mobile="9876543210"), tok)
check("UAE: a 10-digit mobile is refused", s == 422, r)
s, r = call("PUT", "doctor/clinic", dict(clinic, mobile="0501234567"), tok)
check("UAE: the trunk 0 is allowed (0501234567)", s == 200, r)

# Oman: 8 digits
call("PUT", "doctor/clinic-settings", {"currency": "OMR", "country": "OM"}, tok)
check("Oman: 8 digits accepted", call("PUT", "doctor/clinic", dict(clinic, mobile="91234567"), tok)[0] == 200)
check("Oman: 9 digits refused", call("PUT", "doctor/clinic", dict(clinic, mobile="501234567"), tok)[0] == 422)

# tax number round trip + clearing
s, r = call("PUT", "doctor/clinic", dict(clinic, mobile="91234567", tax_number="100234567800003"), tok)
check("the tax number is saved and returned", s == 200 and r["data"]["clinic"].get("tax_number") == "100234567800003", r)
s, r = call("GET", "doctor/clinic", token=tok)
check("it comes back on the clinic", r["data"]["clinic"]["tax_number"] == "100234567800003", r)
s, r = call("PUT", "doctor/clinic", dict(clinic, mobile="91234567", tax_number=""), tok)
check("an empty tax number clears it", r["data"]["clinic"]["tax_number"] is None, r)
s, r = call("PUT", "doctor/clinic", dict(clinic, tax_number="x" * 41), tok)
check("a too-long tax number is refused", s == 422, r)

# national id on a patient
s, r = call("POST", "patients", {"name": "Fatima Ali", "sex": "female", "age_years": 30, "mobile": "501234567", "national_id": "784-1990-1234567-1"}, tok)
check("a patient keeps a national ID", s in (200, 201) and r["data"]["patient"]["national_id"] == "784-1990-1234567-1", r)
pid = r["data"]["patient"]["id"]
s, r = call("GET", "patients?q=1234567", token=tok)
check("patients can be found by national ID", any(p["id"] == pid for p in r["data"]["items"]), r)
s, r = call("PUT", f"patients/{pid}", {"name": "Fatima Ali", "sex": "female", "age_years": 30, "national_id": ""}, tok)
check("and it can be cleared", s == 200 and r["data"]["patient"]["national_id"] is None, r)

# staff phone: the clinic's dial code and length
s, r = call("POST", "staff", {"name": "Reem", "phone": "91234567", "countrycode": "+968", "password": "Secret123"}, tok)
check("a receptionist with an Omani number (+968, 8 digits)", s in (200, 201), r)
s, r = call("POST", "staff", {"name": "Reem2", "phone": "9123456", "countrycode": "+968", "password": "Secret123"}, tok)
check("a too-short Omani number is refused", s == 422, r)
finish()
