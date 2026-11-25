#!/usr/bin/env python3
"""Erase-all-user-data: password + phrase gate, what is removed, what survives, backup.

DESTRUCTIVE. It only runs against a throw-away database and app copy:
  RX_WIPE_TEST=1 RX_API_BASE=http://127.0.0.1:8900 RX_APP_DIR=<copy of rx-backend>
  RX_MYSQL="mysql -uroot -h127.0.0.1 -P3307 rx_wipe_test" python3 tests/api_admin_wipe.py
"""
import gzip, json, os, random, shlex, subprocess, sys
from _client import BASE, call, check, finish, new_doctor

if os.environ.get("RX_WIPE_TEST") != "1" or ":8900" not in BASE or "wipe_test" not in os.environ.get("RX_MYSQL", ""):
    sys.exit("Refusing to run: this test erases data. See the docstring.")
APP = os.environ["RX_APP_DIR"]
MY = shlex.split(os.environ["RX_MYSQL"])
def sql(q): return subprocess.run(MY + ["-N", "-e", q], check=True, capture_output=True, text=True).stdout.strip()
n = lambda t, w="1": int(sql(f"SELECT COUNT(*) FROM {t} WHERE {w}"))

H = subprocess.run(["php", "-r", "echo password_hash('AdminPass123', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
sql("DELETE FROM rate_limits"); sql("DELETE FROM admins WHERE email='wipe@example.com'")
sql(f"INSERT INTO admins (name,email,password_hash) VALUES ('Wiper','wipe@example.com','{H}')")
admin = call("POST", "admin/login", {"email": "wipe@example.com", "password": "AdminPass123"})[1]["data"]["access_token"]

# user data of every kind
doc, did = new_doctor("Dr Wipe")
call("POST", "doctor/clinic", {"name": "Wipe Clinic"}, doc); call("PUT", "doctor/clinic", {"name": "Wipe Clinic", "address_line1": "1 Road", "city": "Pune"}, doc)
pid = call("POST", "patients", {"name": "राजेश Test", "sex": "male", "age_years": 40}, doc)[1]["data"]["patient"]["id"]
call("POST", "appointments", {"patient_id": pid, "walk_in": True}, doc)
rx = call("POST", "prescriptions", {"patient_id": pid, "status": "final", "diagnosis": "Fever", "medicines": [{"medicine_name": "My own syrup", "qty_unit": "ml", "duration_days": 3, "dose_schedule": [{"slot": "morning", "qty": 5}]}]}, doc)
call("POST", "bills", {"patient_id": pid, "items": [{"description": "Consult", "amount": 100}]}, doc)
st = call("POST", "staff", {"name": "Wipe Desk", "countrycode": "+91", "phone": "9" + "".join(random.choice("0123456789") for _ in range(9)), "password": "Desk12345"}, doc)
sql(f"INSERT INTO medicines (category_id,name,is_system,doctor_id) SELECT category_id,'Doctor Only Med',0,{did} FROM medicines LIMIT 1")
os.makedirs(f"{APP}/storage/signatures/{did}", exist_ok=True)
open(f"{APP}/storage/signatures/{did}/sig.png", "wb").write(b"png")

before = {t: n(t) for t in ["patients", "prescriptions", "bills", "clinic_staff", "doctors"]}
check("test data exists", all(v > 0 for v in before.values()), before)
system_med = n("medicines", "doctor_id IS NULL"); lab = n("lab_tests_catalog"); sym = n("symptoms", "doctor_id IS NULL")
admins = n("admins"); ped = n("pediatric_drugs")

check("anonymous cannot see the summary", call("GET", "admin/data/summary")[0] == 401)
check("doctor cannot see the summary", call("GET", "admin/data/summary", token=doc)[0] == 403)
check("doctor cannot erase", call("POST", "admin/data/wipe", {"password": "Secret123", "confirm": "DELETE ALL DATA"}, doc)[0] == 403)
s, r = call("GET", "admin/data/summary", token=admin)
check("summary counts what will go and stay", s == 200 and r["data"]["remove"]["patients"] == before["patients"] and r["data"]["keep"]["medicines"] == system_med and not r["data"]["unknown_tables"], r)
s, r = call("POST", "admin/data/wipe", {"password": "wrong", "confirm": "DELETE ALL DATA"}, admin)
check("wrong password refused", s == 422 and n("patients") == before["patients"], r)
s, r = call("POST", "admin/data/wipe", {"password": "AdminPass123", "confirm": "delete"}, admin)
check("wrong phrase refused", s == 422 and n("patients") == before["patients"], r)
sql("DELETE FROM rate_limits"); sql("CREATE TABLE mystery_table (id int)")
s, r = call("POST", "admin/data/wipe", {"password": "AdminPass123", "confirm": "DELETE ALL DATA"}, admin)
check("unclassified table stops the erase", s == 409 and n("patients") == before["patients"], r)
sql("DROP TABLE mystery_table")
open(f"{APP}/storage/wipe.disabled", "w").close()
s, r = call("POST", "admin/data/wipe", {"password": "AdminPass123", "confirm": "DELETE ALL DATA"}, admin)
check("switch-off file stops the erase", s == 403 and n("patients") == before["patients"], r)
os.remove(f"{APP}/storage/wipe.disabled"); sql("DELETE FROM rate_limits")

s, r = call("POST", "admin/data/wipe", {"password": "AdminPass123", "confirm": "DELETE ALL DATA"}, admin)
check("erase succeeds", s == 200 and r["data"]["rows"] > 0 and r["data"]["files"] >= 1, r)
left = {t: n(t) for t in ["doctors", "clinic_staff", "patients", "appointments", "prescriptions", "prescription_medicines", "bills", "bill_items", "clinics", "auth_tokens", "staff_tokens", "device_tokens"]}
check("all user tables are empty", not any(left.values()), left)
check("doctor-private medicines removed", n("medicines", "doctor_id IS NOT NULL") == 0)
check("shipped medicines kept", n("medicines", "doctor_id IS NULL") == system_med)
check("catalogues kept", n("lab_tests_catalog") == lab and n("pediatric_drugs") == ped and n("symptoms", "doctor_id IS NULL") == sym)
check("admins kept", n("admins") == admins)
check("one audit entry records the erase", n("audit_logs", "action='data.wiped'") == 1)
check("signature file moved out", not os.path.exists(f"{APP}/storage/signatures/{did}/sig.png"))
path = f"{APP}/{r['data']['backup']}"
rows = [json.loads(l) for l in gzip.open(path, "rt", encoding="utf8")]
check("backup holds the removed rows", any(x["table"] == "patients" and x["row"]["name"] == "राजेश Test" for x in rows), len(rows))
check("backup folder is closed to the web", "denied" in open(f"{APP}/storage/backups/.htaccess").read())
check("admin still signed in", call("GET", "admin/dashboard", token=admin)[0] == 200)
check("erased doctor's token is useless", call("GET", "patients", token=doc)[0] >= 400)
s, r = call("POST", "admin/clinics", {"name": "Dr Fresh", "phone": "9" + str(did).zfill(9)[-9:], "country": "IN", "clinic_name": "Fresh"}, admin)
check("a new clinic can be created afterwards", s == 201, r)
finish()
