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
s, r = call("GET", "auth/me", token=r["data"]["access_token"])
check("/auth/me shows the same 90 days", r["data"]["user"]["plan_days_left"] == 90, r)
finish()
