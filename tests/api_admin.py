"""Admin panel API: login, dashboard, clinic list / detail / create / actions, and token separation."""
import random, subprocess
from _client import call, check, finish, new_doctor

MY = ["/Applications/XAMPP/xamppfiles/bin/mysql", "-uroot", "php_rest_api", "-e"]
def sql(q): return subprocess.run(MY + [q], check=True, capture_output=True, text=True).stdout
EMAIL = "admin-test@example.com"
H = subprocess.run(["php", "-r", "echo password_hash('AdminPass123', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
sql("DELETE FROM rate_limits")
sql(f"DELETE FROM admins WHERE email='{EMAIL}'")
sql(f"INSERT INTO admins (name,email,password_hash) VALUES ('Test Admin','{EMAIL}','{H}')")

s, r = call("POST", "admin/login", {"email": EMAIL, "password": "wrong"})
check("wrong password refused", s == 401, r)
s, r = call("POST", "admin/login", {"email": EMAIL, "password": "AdminPass123"})
check("admin logs in", s == 200 and r["data"]["access_token"], r)
tok = r["data"]["access_token"]

s, r = call("POST", "admin/password", {"current": "nope", "password": "NewAdminPass1"}, tok)
check("wrong current password refused", s == 422, r)
s, r = call("POST", "admin/password", {"current": "AdminPass123", "password": "short1"}, tok)
check("short new password refused", s == 422, r)
s, r = call("POST", "admin/password", {"current": "AdminPass123", "password": "NewAdminPass1"}, tok)
check("password changed", s == 200, r)
check("old password no longer works", call("POST", "admin/login", {"email": EMAIL, "password": "AdminPass123"})[0] == 401)
s, r = call("POST", "admin/login", {"email": EMAIL, "password": "NewAdminPass1"})
check("new password works", s == 200, r)
tok = r["data"]["access_token"]

dtok, did = new_doctor("Dr Panel Probe")
check("doctor token cannot use admin API", call("GET", "admin/dashboard", token=dtok)[0] == 403)
check("no token cannot use admin API", call("GET", "admin/dashboard")[0] == 401)
check("admin token cannot use doctor API", call("GET", "patients", token=tok)[0] in (401, 403))

s, r = call("GET", "admin/dashboard", token=tok)
check("dashboard", s == 200 and r["data"]["clinics"]["total"] >= 1 and len(r["data"]["signups"]) == 30, r)

phone = "9" + "".join(random.choice("0123456789") for _ in range(9))
s, r = call("POST", "admin/clinics", {"name": "Dr Created", "phone": phone, "country": "IN", "clinic_name": "Clinic " + phone, "trial_days": 90}, tok)
check("admin creates a clinic with a generated password", s == 201 and len(r["data"]["password"]) == 12, r)
cid, pw, mob = r["data"]["id"], r["data"]["password"], r["data"]["mobile"]
s, r = call("POST", "admin/clinics", {"name": "Dr Dup", "phone": phone, "country": "IN"}, tok)
check("duplicate mobile refused", s == 409, r)
s, r = call("POST", "auth/login-new", {"identifier": mob, "password": pw})
check("the doctor can sign in with that password", s == 200 and r["data"]["user"]["plan_days_left"] == 90, r)
dt2 = r["data"]["access_token"]

s, r = call("GET", f"admin/clinics/{cid}", token=tok)
c = r["data"]["clinic"]
check("detail shows trial, days left, last active", s == 200 and c["state"] == "trial" and c["days_left"] == 90 and c["last_active"], r)
check("detail has counts only", set(r["data"]["counts"]) >= {"patients", "prescriptions", "appointments", "bills"}, r)

# a receptionist's sign-in counts as clinic activity
s, r = call("POST", "staff", {"name": "Rita", "countrycode": "+91", "phone": "98" + phone[2:], "password": "Reception1"}, dt2)
s, r = call("GET", "admin/clinics?q=" + phone, token=tok)
check("search finds the clinic", r["data"]["total"] >= 1 and r["data"]["items"][0]["staff"] == 1, r)

s, r = call("POST", f"admin/clinics/{cid}/action", {"action": "extend", "days": 30}, tok)
s, r = call("GET", f"admin/clinics/{cid}", token=tok)
check("extend adds 30 days", r["data"]["clinic"]["days_left"] == 120, r)
s, r = call("POST", f"admin/clinics/{cid}/action", {"action": "reset_password"}, tok)
newpw = r["data"]["password"]
check("old password stops working, new works", call("POST", "auth/login-new", {"identifier": mob, "password": pw})[0] == 401
      and call("POST", "auth/login-new", {"identifier": mob, "password": newpw})[0] == 200)
s, r = call("POST", f"admin/clinics/{cid}/action", {"action": "disable"}, tok)
check("disabled clinic cannot sign in", call("POST", "auth/login-new", {"identifier": mob, "password": newpw})[0] == 403)
s, r = call("GET", "admin/clinics?status=disabled", token=tok)
check("shows under disabled", any(i["id"] == cid for i in r["data"]["items"]), r)
call("POST", f"admin/clinics/{cid}/action", {"action": "enable"}, tok)
s, r = call("POST", f"admin/clinics/{cid}/action", {"action": "set_plan", "plan": "pro", "expires_at": "2099-01-01"}, tok)
s, r = call("GET", f"admin/clinics/{cid}", token=tok)
check("set plan", r["data"]["clinic"]["plan"] == "pro" and r["data"]["clinic"]["state"] == "active", r)
sql(f"UPDATE doctors SET plan_expires_at = NOW() - INTERVAL 1 DAY WHERE id={cid}")
s, r = call("GET", "admin/clinics?status=expired", token=tok)
check("expired filter", any(i["id"] == cid and i["state"] == "expired" for i in r["data"]["items"]), r)

# staff management across clinics
s, r = call("POST", f"admin/clinics/{cid}/staff", {"name": "Sam Desk", "phone": "97" + phone[2:]}, tok)
check("admin adds a receptionist (generated password)", s == 201 and len(r["data"]["password"]) == 12, r)
sid, smob, spw = r["data"]["id"], r["data"]["mobile"], r["data"]["password"]
check("receptionist can sign in", call("POST", "auth/login-new", {"identifier": smob, "password": spw})[0] == 200)
s, r = call("GET", "admin/staff?q=Sam%20Desk", token=tok)
check("global staff list finds them with their clinic", r["data"]["total"] == 1 and r["data"]["items"][0]["clinic_id"] == cid, r)
call("POST", f"admin/staff/{sid}/action", {"action": "disable"}, tok)
check("disabled receptionist cannot sign in", call("POST", "auth/login-new", {"identifier": smob, "password": spw})[0] == 403)
s, r = call("GET", "admin/staff?status=off&q=Sam", token=tok)
check("off filter", r["data"]["total"] == 1, r)
call("POST", f"admin/staff/{sid}/action", {"action": "enable"}, tok)
s, r = call("POST", f"admin/staff/{sid}/action", {"action": "reset_password"}, tok)
check("reset staff password", call("POST", "auth/login-new", {"identifier": smob, "password": r["data"]["password"]})[0] == 200, r)
call("POST", f"admin/staff/{sid}/action", {"action": "remove"}, tok)
check("removed receptionist is gone", call("GET", "admin/staff?q=Sam%20Desk", token=tok)[1]["data"]["total"] == 0)

# admin management
s, r = call("POST", "admin/admins", {"name": "Second", "email": "second-admin@example.com"}, tok)
check("adds an admin", s == 201 and len(r["data"]["password"]) >= 10, r)
aid, apw = r["data"]["id"], r["data"]["password"]
s, r = call("POST", "admin/login", {"email": "second-admin@example.com", "password": apw})
check("new admin signs in", s == 200, r)
t2 = r["data"]["access_token"]
check("duplicate admin email refused", call("POST", "admin/admins", {"name": "Xx", "email": "second-admin@example.com"}, tok)[0] == 409)
call("POST", f"admin/admins/{aid}/action", {"action": "disable"}, tok)
check("switched-off admin is signed out", call("GET", "admin/me", token=t2)[0] == 401)
check("switched-off admin cannot sign in", call("POST", "admin/login", {"email": "second-admin@example.com", "password": apw})[0] == 401)
me = call("GET", "admin/me", token=tok)[1]["data"]["admin"]["id"]
check("cannot switch off yourself", call("POST", f"admin/admins/{me}/action", {"action": "disable"}, tok)[0] == 422)
check("cannot remove yourself", call("POST", f"admin/admins/{me}/action", {"action": "delete"}, tok)[0] == 422)
call("POST", f"admin/admins/{aid}/action", {"action": "delete"}, tok)
check("admin list no longer has them", all(a["email"] != "second-admin@example.com" for a in call("GET", "admin/admins", token=tok)[1]["data"]["items"]))

sql(f"DELETE FROM admins WHERE email='{EMAIL}'")
sql("DELETE FROM clinic_staff WHERE doctor_id IN (SELECT id FROM doctors WHERE name='Dr Created'); DELETE FROM auth_tokens WHERE doctor_id IN (SELECT id FROM doctors WHERE name='Dr Created'); DELETE FROM clinics WHERE doctor_id IN (SELECT id FROM doctors WHERE name='Dr Created'); DELETE FROM doctors WHERE name='Dr Created'")
finish()
