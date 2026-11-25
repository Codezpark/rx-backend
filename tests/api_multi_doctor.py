#!/usr/bin/env python3
"""Several doctors in one clinic: who sees what, who can change what, and that clinics stay apart."""
import datetime as dt, random, subprocess, time
from _client import call, check, finish, new_doctor

MY = ["/Applications/XAMPP/xamppfiles/bin/mysql", "-uroot", "php_rest_api", "-N", "-e"]
def sql(q): return subprocess.run(MY + [q], check=True, capture_output=True, text=True).stdout.strip()
def phone(): return "9" + "".join(random.choice("0123456789") for _ in range(9))
def mobile_of(i): return sql(f"SELECT mobile FROM doctors WHERE id={i}")
def login(mobile, pw): return call("POST", "auth/login-new", {"identifier": mobile, "password": pw})

EMAIL = "multi-admin@example.com"
H = subprocess.run(["php", "-r", "echo password_hash('AdminPass123', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
sql("DELETE FROM rate_limits"); sql(f"DELETE FROM admins WHERE email='{EMAIL}'")
sql(f"INSERT INTO admins (name,email,password_hash) VALUES ('Multi Admin','{EMAIL}','{H}')")
adm = call("POST", "admin/login", {"email": EMAIL, "password": "AdminPass123"})[1]["data"]["access_token"]

own, cid = new_doctor("Dr Owner")
other, other_id = new_doctor("Dr Elsewhere")

# ── the admin adds a second doctor ──
mp = phone()
s, r = call("POST", f"admin/clinics/{cid}/doctors", {"name": "Dr Member", "phone": mp, "countrycode": "+91", "degree": "MD", "specialization": "Paediatrics", "experience_yrs": "07"}, adm)
check("admin adds a doctor with a generated password", s == 201 and len(r["data"]["password"]) == 12, r)
mid, mpw = r["data"]["id"], r["data"]["password"]
check("the same number cannot be added twice", call("POST", f"admin/clinics/{cid}/doctors", {"name": "Twin", "phone": mp, "countrycode": "+91"}, adm)[0] == 409)
check("a doctor account cannot add doctors", call("POST", f"admin/clinics/{cid}/doctors", {"name": "X Y", "phone": phone()}, own)[0] == 403)
s, r = login("+91" + mp, mpw)
check("the second doctor signs in", s == 200 and r["data"]["user"]["is_owner"] is False and r["data"]["user"]["clinic_id"] == cid and r["data"]["user"]["clinic_doctors"] == 2, r)
mem, mem_refresh = r["data"]["access_token"], r["data"]["refresh_token"]
me_owner = call("GET", "auth/me", token=own)[1]["data"]["user"]
check("plan comes from the clinic owner", r["data"]["user"]["plan_expires_at"] == me_owner["plan_expires_at"] and me_owner["is_owner"] is True, r)

s, r = call("GET", "admin/clinics?q=" + mp, token=adm)
check("admin finds the clinic by a second doctor's number, listed once", s == 200 and [c["id"] for c in r["data"]["items"]] == [cid] and r["data"]["items"][0]["doctors"] == 2, r)
check("a second doctor is not a clinic of its own", call("GET", f"admin/clinics/{mid}", token=adm)[0] == 404)
s, r = call("GET", f"admin/clinics/{cid}", token=adm)
check("clinic page lists its doctors, owner first", s == 200 and [d["id"] for d in r["data"]["doctors"]] == [cid, mid] and r["data"]["doctors"][1]["experience_yrs"] == 7, r)

# reception
sp = phone()
s, r = call("POST", "staff", {"name": "Front", "countrycode": "+91", "phone": sp, "password": "Desk12345"}, own)
check("owner adds reception", s == 201, r)
desk = login("+91" + sp, "Desk12345")[1]["data"]["access_token"]
for path, method, body in [("staff", "GET", None), ("staff", "POST", {"name": "Z Z", "countrycode": "+91", "phone": phone(), "password": "Desk12345"}),
                           ("doctor/clinic", "PUT", {"name": "Hijack"}), ("doctor/hours", "PUT", {"timings": []}), ("doctor/holidays", "POST", {"date": "2027-01-01"})]:
    s, r = call(method, path, body, mem)
    check(f"second doctor cannot {method} {path}", s == 403, r)
for t, who in ((desk, "reception"), (mem, "second doctor"), (own, "owner")):
    s, r = call("GET", "clinic/doctors", token=t)
    check(f"{who} sees the clinic's doctors", s == 200 and [d["id"] for d in r["data"]["items"]] == [cid, mid], r)
check("another clinic sees only its own", [d["id"] for d in call("GET", "clinic/doctors", token=other)[1]["data"]["items"]] == [other_id])

# ── patients are the clinic's ──
mk = lambda t, name: call("POST", "patients", {"name": name, "sex": "female", "age_years": 30}, t)[1]["data"]["patient"]
p1 = mk(own, "Clinic Patient One"); p2 = mk(mem, "Clinic Patient Two"); px = mk(other, "Elsewhere Patient")
check("patient numbers run on across the clinic", int(p2["uhid"][1:]) == int(p1["uhid"][1:]) + 1, (p1, p2))
check("both doctors open both patients", call("GET", f"patients/{p2['id']}", token=own)[0] == 200 and call("GET", f"patients/{p1['id']}", token=mem)[0] == 200)
check("no one opens another clinic's patient", all(call("GET", f"patients/{px['id']}", token=t)[0] == 404 for t in (own, mem, desk)))

# ── queue routing ──
walk = lambda t, p, **kw: call("POST", "appointments", dict({"patient_id": p["id"], "walk_in": True}, **kw), t)
s, r = walk(desk, p1, consultant_id=mid)
check("reception sends a patient to the second doctor", s == 201 and r["data"]["appointment"]["consultant"] == {"id": mid, "name": "Dr Member"}, r)
a_mem = r["data"]["appointment"]["id"]
s, r = walk(desk, p2)
check("without a choice the patient goes to the owner (older apps)", s == 201 and r["data"]["appointment"]["consultant"]["id"] == cid, r)
a_own = r["data"]["appointment"]["id"]
check("a doctor from another clinic cannot be chosen", walk(desk, p2, consultant_id=other_id)[0] == 422)
s, r = walk(desk, p1, consultant_id=mid)
check("the same patient twice for one doctor is stopped", s == 409 and "already waiting" in r["message"], r)
s, r = walk(desk, p1, consultant_id=cid)
check("the same patient may also wait for the other doctor", s == 201, r)
call("POST", f"appointments/{r['data']['appointment']['id']}/status", {"status": "cancelled"}, desk)
ids = lambda t, q="": sorted(a["id"] for a in call("GET", "appointments" + q, token=t)[1]["data"]["items"] if a["status"] != "cancelled")
check("second doctor's queue is their own", ids(mem) == [a_mem], ids(mem))
check("owner's queue is their own", ids(own) == [a_own], ids(own))
check("reception sees every doctor", ids(desk) == sorted([a_mem, a_own]))
check("reception filters by doctor", ids(desk, f"?consultant_id={mid}") == [a_mem])
check("a doctor can look at the whole clinic", ids(mem, "?consultant_id=all") == sorted([a_mem, a_own]))
check("another clinic's queue is untouched", ids(other) == [])
check("call-in works for the second doctor's patient", call("POST", f"appointments/{a_mem}/call", token=desk)[0] == 200)
s, r = call("GET", "dashboard/summary", token=mem)
check("second doctor's home counts their own queue", s == 200 and r["data"]["queue_waiting"] == 1, r)

# ── prescriptions belong to their author ──
rx_body = {"patient_id": p1["id"], "status": "final", "diagnosis": "Fever", "appointment_id": a_mem, "consultation_fee": 300,
           "medicines": [{"medicine_name": "Paracetamol", "qty_unit": "piece", "duration_days": 3, "dose_schedule": [{"slot": "morning", "qty": 1}]}],
           "investigations": {"lab": {"tests": [{"custom_test": "CBC"}]}}}
s, r = call("POST", "prescriptions", rx_body, mem)
check("second doctor writes a prescription in their own name", s == 201 and r["data"]["prescription"]["doctor"]["name"] == "Dr Member" and r["data"]["prescription"]["mine"] is True, r)
rx = r["data"]["prescription"]; lab = rx["investigations"]["lab"]["id"]
s, r = call("GET", f"prescriptions/{rx['id']}", token=own)
check("owner reads it, marked as a colleague's", s == 200 and r["data"]["prescription"]["mine"] is False and r["data"]["prescription"]["doctor"]["id"] == mid, r)
check("owner cannot cancel a colleague's prescription", call("POST", f"prescriptions/{rx['id']}/cancel", token=own)[0] == 403)
check("reception still cannot read prescriptions", call("GET", f"prescriptions/{rx['id']}", token=desk)[0] == 403)
check("another clinic cannot read it", call("GET", f"prescriptions/{rx['id']}", token=other)[0] == 404)
draft = call("POST", "prescriptions", {"patient_id": p1["id"], "status": "draft", "diagnosis": "Private draft"}, mem)[1]["data"]["prescription"]["id"]
check("a colleague's draft is hidden", call("GET", f"prescriptions/{draft}", token=own)[0] == 404)
hist = [x["id"] for x in call("GET", f"patients/{p1['id']}/prescriptions", token=own)[1]["data"]["items"]]
check("patient history shows the clinic's finished work, not others' drafts", rx["id"] in hist and draft not in hist, hist)
check("the author still sees the draft", draft in [x["id"] for x in call("GET", f"patients/{p1['id']}/prescriptions", token=mem)[1]["data"]["items"]])
mine = lambda t, q="": [x["id"] for x in call("GET", "prescriptions" + q, token=t)[1]["data"]["items"]]
check("the prescription list is my own by default, the clinic's on request", rx["id"] not in mine(own) and rx["id"] in mine(own, "?doctor=all") and draft not in mine(own, "?doctor=all"))
check("signature of an author without one is 'not found', not someone else's", call("GET", f"prescriptions/{rx['id']}/signature", token=own)[0] == 404)

# ── lab orders ──
check("owner reads the order", call("GET", f"lab-orders/{lab}", token=own)[0] == 200)
check("owner cannot change a colleague's results", call("POST", f"lab-orders/{lab}/status", {"status": "sample_collected"}, own)[0] == 403)
check("the author can", call("POST", f"lab-orders/{lab}/status", {"status": "sample_collected"}, mem)[0] in (200, 201))

# ── money ──
bills = lambda t, q="": call("GET", "bills" + q, token=t)[1]["data"]["items"]
mb = [b for b in bills(mem) if b["consultant_id"] == mid]
check("the consultation bill belongs to the treating doctor", len(mb) == 1 and len(bills(mem)) == 1, bills(mem))
ob = call("POST", "bills", {"patient_id": p2["id"], "items": [{"description": "Dressing", "amount": 100}], "paid": 100}, own)[1]["data"]["bill"]
check("second doctor does not see the owner's bill", call("GET", f"bills/{ob['id']}", token=mem)[0] == 404)
check("owner and reception see all bills", len(bills(own)) == 2 and len(bills(desk)) == 2, (bills(own), bills(desk)))
call("POST", f"bills/{mb[0]['id']}/payment", {"amount": 300, "method": "cash"}, desk)
summ = lambda t, q="": call("GET", "bills/summary" + q, token=t)[1]["data"]["collected"]
check("collections: own for the second doctor, all for the owner, filterable", summ(mem) == 300 and summ(own) == 400 and summ(own, f"?consultant_id={mid}") == 300, (summ(mem), summ(own)))
eod = lambda t, q="": call("GET", "eod" + q, token=t)[1]["data"]["charges"]["collected"]
check("end of day follows the same rule", eod(mem) == 300 and eod(own) == 400 and eod(own, f"?consultant_id={mid}") == 300, (eod(mem), eod(own)))
ins = lambda t: call("GET", "insights?days=7", token=t)[1]["data"]["current"]
check("insights follow the same rule", ins(mem)["collected"] == 300 and ins(mem)["visits"] == 1 and ins(own)["collected"] == 400, (ins(mem), ins(own)))
check("a second doctor cannot filter into a colleague's numbers", eod(mem, f"?consultant_id={cid}") == 300)
check("follow-ups load for both", call("GET", "followups", token=mem)[0] == 200 and call("GET", "followups", token=own)[0] == 200)

# ── plan, lock-out, removal ──
sql(f"UPDATE doctors SET plan_expires_at = NOW() - INTERVAL 1 DAY WHERE id = {cid}")
s, r = call("POST", "patients", {"name": "Late", "sex": "male", "age_years": 20}, mem)
check("when the clinic's plan ends the second doctor is read-only too", s == 403 and r["errors"]["code"] == "plan_expired", r)
sql(f"UPDATE doctors SET plan_expires_at = NOW() + INTERVAL 30 DAY WHERE id = {cid}")
time.sleep(1.1)
s, r = call("POST", f"admin/doctors/{mid}/action", {"action": "disable"}, adm)
check("admin switches the second doctor off", s == 200, r)
check("their session ends at once", call("GET", "patients", token=mem)[0] == 401)
check("they cannot sign in", login("+91" + mp, mpw)[0] == 403)
check("the owner keeps working", call("GET", "patients", token=own)[0] == 200)
check("the owner cannot be switched off on their own", call("POST", f"admin/doctors/{cid}/action", {"action": "disable"}, adm)[0] == 422)
call("POST", f"admin/doctors/{mid}/action", {"action": "enable"}, adm)
time.sleep(1.1)
mem = login("+91" + mp, mpw)[1]["data"]["access_token"]
call("POST", f"admin/clinics/{cid}/action", {"action": "disable"}, adm)
check("switching the clinic off locks every doctor and reception", all(call("GET", "patients", token=t)[0] == 401 for t in (own, mem, desk)))
check("second doctor cannot sign in to a switched-off clinic", login("+91" + mp, mpw)[0] == 403)
call("POST", f"admin/clinics/{cid}/action", {"action": "enable"}, adm)
time.sleep(1.1)
s, r = call("POST", f"admin/doctors/{mid}/action", {"action": "remove"}, adm)
check("admin removes the second doctor", s == 200, r)
owner_pw = "Secret123"
own = login(mobile_of(cid), owner_pw)[1]["data"]["access_token"]
s, r = call("GET", f"prescriptions/{rx['id']}", token=own)
check("their records stay, under their name", s == 200 and r["data"]["prescription"]["doctor"]["name"] == "Dr Member", r)
check("they are gone from the doctor list", [d["id"] for d in call("GET", "clinic/doctors", token=own)[1]["data"]["items"]] == [cid])
s, r = call("POST", f"admin/clinics/{cid}/doctors", {"name": "Dr Member", "phone": mp, "countrycode": "+91"}, adm)
check("adding the same number again brings the doctor back", s == 201 and r["data"]["id"] == mid, r)

# moving existing accounts
_, empty_id = new_doctor("Dr Empty")
s, r = call("POST", f"admin/clinics/{cid}/doctors", {"existing_mobile": mobile_of(empty_id)}, adm)
check("an empty account can join a clinic", s == 201 and r["data"]["id"] == empty_id, r)
s, r = call("POST", f"admin/clinics/{cid}/doctors", {"existing_mobile": mobile_of(other_id)}, adm)
check("an account with its own patients cannot", s == 422, r)

# password change ends other sessions immediately
old = login(mobile_of(cid), owner_pw)[1]["data"]["access_token"]
time.sleep(1.1)
s, r = call("PUT", "doctor/change-password", {"current_password": owner_pw, "password": "Changed123"}, own)
check("owner changes password and keeps this session", s == 200 and call("GET", "auth/me", token=r["data"]["access_token"])[0] == 200, r)
check("older access tokens stop working", call("GET", "auth/me", token=old)[0] == 401)
finish()
