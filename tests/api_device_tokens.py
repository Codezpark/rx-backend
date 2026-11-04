"""Device-token registration for call-in push notifications (Module 4.4)."""
import random
from _client import call, check, new_doctor, finish

PW = "Secret123"

tok, did = new_doctor("Dr Push")

# ── auth required ──────────────────────────────────────────────────────────
check("register needs sign-in", call("POST", "device-tokens", {"token": "t_anon"})[0] == 401)

# ── doctor registers a device ──────────────────────────────────────────────
dtok = "dtoken_" + "".join(random.choice("abcdef0123456789") for _ in range(40))
check("doctor registers a device token", call("POST", "device-tokens", {"token": dtok, "platform": "android"}, tok)[0] == 200)

# ── validation ─────────────────────────────────────────────────────────────
check("empty token rejected", call("POST", "device-tokens", {"token": ""}, tok)[0] == 422)
check("over-long token rejected", call("POST", "device-tokens", {"token": "x" * 513}, tok)[0] == 422)

# ── staff (receptionist) registers a device, scoped to the clinic owner ─────
phone = "9" + "".join(random.choice("0123456789") for _ in range(9))
sid = call("POST", "staff", {"name": "Reception Push", "countrycode": "+91", "phone": phone, "password": PW}, tok)[1]["data"]["staff"]["id"]
rtok = call("POST", "auth/login-new", {"identifier": phone, "password": PW})[1]["data"]["access_token"]
rdtok = "rtoken_" + "".join(random.choice("abcdef0123456789") for _ in range(40))
check("receptionist registers a device token", call("POST", "device-tokens", {"token": rdtok}, rtok)[0] == 200)

# ── re-register same token re-points it (idempotent, no duplicate) ──────────
check("re-registering the same token succeeds", call("POST", "device-tokens", {"token": dtok}, tok)[0] == 200)

# ── the call-in endpoint accepts a caller_token and still succeeds ──────────
pid = call("POST", "patients", {"name": "Push Patient", "sex": "male", "age_years": 30, "mobile": "9" + "6" * 9}, rtok)[1]["data"]["patient"]["id"]
ap = call("POST", "appointments", {"patient_id": pid, "walk_in": True}, rtok)[1]["data"]["appointment"]["id"]
s, r = call("POST", f"appointments/{ap}/call", {"caller_token": dtok}, tok)
check("call-in with a caller_token succeeds", s == 200 and r["data"]["appointment"]["id"] == ap, r)
check("call-in without a caller_token still succeeds", call("POST", f"appointments/{ap}/call", {}, tok)[0] == 200)

# ── remove a device on sign-out ─────────────────────────────────────────────
check("remove a device token", call("POST", "device-tokens/remove", {"token": dtok}, tok)[0] == 200)
check("removing an unknown token is a no-op", call("POST", "device-tokens/remove", {"token": "never_seen"}, tok)[0] == 200)

finish()
