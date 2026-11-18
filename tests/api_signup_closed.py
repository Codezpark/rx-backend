#!/usr/bin/env python3
"""Registration is closed when ALLOW_SIGNUP is off. Run against a server started with ALLOW_SIGNUP=false, e.g.
   ALLOW_SIGNUP=false php -S 127.0.0.1:8901 index.php   then   RX_API_BASE=http://127.0.0.1:8901 python3 tests/api_signup_closed.py"""
from _client import *
import random

phone = "9" + "".join(random.choice("0123456789") for _ in range(9))
s, r = call("POST", "auth/register-new", {"name": "Fake Doctor", "password": "Secret123", "phone": phone, "countrycode": "+91"})
check("registration is refused with 403", s == 403, (s, r))
check("the message says invite-only", "invitation" in (r.get("message") or ""), r)
check("no doctor was created (the number cannot sign in)", call("POST", "auth/login", {"identifier": "+91" + phone, "password": "Secret123"})[0] in (401, 404, 422), None)
print("\nALL PASSED" if not fails else f"\n{fails} FAILED")
sys.exit(1 if fails else 0)
