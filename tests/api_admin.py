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

sql(f"DELETE FROM admins WHERE email='{EMAIL}'")
sql("DELETE FROM clinic_staff WHERE doctor_id IN (SELECT id FROM doctors WHERE name='Dr Created'); DELETE FROM auth_tokens WHERE doctor_id IN (SELECT id FROM doctors WHERE name='Dr Created'); DELETE FROM clinics WHERE doctor_id IN (SELECT id FROM doctors WHERE name='Dr Created'); DELETE FROM doctors WHERE name='Dr Created'")
finish()
