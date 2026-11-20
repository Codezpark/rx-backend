"""The free trial: 90 days, counted from sign-up / number verification."""
import random
from _client import call, check, finish

phone = "9" + "".join(random.choice("0123456789") for _ in range(9))
s, r = call("POST", "auth/register-new", {"name": "Dr Trial", "password": "Secret123", "phone": phone, "countrycode": "+91"})
check("registers", s in (200, 201), r)
s, r = call("POST", "auth/verify-otp", {"mobile": "+91" + phone, "otp": r["data"]["dev_otp"]})
u = r["data"]["user"]
check("new doctor is on the trial plan", u["plan"] == "trial", u)
check("trial is 90 days from sign-up", u["plan_days_left"] == 90, u["plan_days_left"])
tok = r["data"]["access_token"]
s, r = call("GET", "auth/me", token=tok)
check("/auth/me shows the same 90 days", r["data"]["user"]["plan_days_left"] == 90, r)

# after the plan ends the account is read-only: reads and sign-out work, writes are refused
import subprocess
MY = ["/Applications/XAMPP/xamppfiles/bin/mysql", "-uroot", "php_rest_api", "-e"]
subprocess.run(MY + ["UPDATE doctors SET plan_expires_at = NOW() - INTERVAL 1 DAY WHERE mobile LIKE '%" + phone + "'"], check=True)
s, r = call("GET", "patients", token=tok)
check("expired: reads still work", s == 200, r)
s, r = call("POST", "patients", {"name": "Blocked", "sex": "male", "age_years": 30}, tok)
check("expired: writes are refused", s == 403 and r.get("errors", {}).get("code") == "plan_expired", r)
s, r = call("POST", "auth/logout", {}, tok)
check("expired: sign-out still works", s != 403, r)
subprocess.run(MY + ["UPDATE doctors SET plan_expires_at = NOW() + INTERVAL 5 DAY WHERE mobile LIKE '%" + phone + "'"], check=True)
s, r = call("POST", "patients", {"name": "Allowed", "sex": "male", "age_years": 30}, tok)
check("renewed: writes work again", s in (200, 201), r)
finish()
