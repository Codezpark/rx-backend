"""Remote force-logout of a receptionist: disabling or resetting their password
ends every active session immediately (access token + refresh token), so they
cannot keep using a still-unexpired token. Pairs with the silent FCM push."""
import random
import time
from _client import call, check, new_doctor, finish

PW = "Secret123"
NEWPW = "Secret456"

tok, did = new_doctor("Dr ForceLogout")


def make_staff():
    phone = "9" + "".join(random.choice("0123456789") for _ in range(9))
    sid = call("POST", "staff", {"name": "Recep", "countrycode": "+91", "phone": phone, "password": PW}, tok)[1]["data"]["staff"]["id"]
    login = call("POST", "auth/login-new", {"identifier": phone, "password": PW})[1]["data"]
    return phone, sid, login["access_token"], login["refresh_token"]


# ── disabling a receptionist logs them out immediately ──────────────────────
phone, sid, atok, rtok = make_staff()
check("active receptionist can use the clinic", call("GET", "auth/me", None, atok)[0] == 200)
check("doctor disables the receptionist", call("PUT", f"staff/{sid}", {"is_active": False}, tok)[0] == 200)
check("disabled receptionist's access token is rejected at once", call("GET", "auth/me", None, atok)[0] == 401)
check("disabled receptionist cannot refresh", call("POST", "auth/refresh", {"refresh_token": rtok})[0] in (401, 403))

# ── resetting a receptionist's password logs them out immediately ───────────
phone2, sid2, atok2, rtok2 = make_staff()
check("receptionist can use the clinic before reset", call("GET", "auth/me", None, atok2)[0] == 200)
time.sleep(1.1)  # ensure the old token's iat is strictly before sessions_valid_from
check("doctor resets the receptionist password", call("PUT", f"staff/{sid2}", {"password": NEWPW}, tok)[0] == 200)
check("old access token rejected after password reset", call("GET", "auth/me", None, atok2)[0] == 401)
check("old refresh token rejected after password reset", call("POST", "auth/refresh", {"refresh_token": rtok2})[0] in (401, 403))
check("old password no longer signs in", call("POST", "auth/login-new", {"identifier": phone2, "password": PW})[0] == 401)
fresh = call("POST", "auth/login-new", {"identifier": phone2, "password": NEWPW})
check("new password signs in", fresh[0] == 200)
check("the new session works", call("GET", "auth/me", None, fresh[1]["data"]["access_token"])[0] == 200)

# ── deleting a receptionist also ends their session ─────────────────────────
phone3, sid3, atok3, rtok3 = make_staff()
check("receptionist can use the clinic before removal", call("GET", "auth/me", None, atok3)[0] == 200)
check("doctor removes the receptionist", call("DELETE", f"staff/{sid3}", None, tok)[0] == 200)
check("removed receptionist's access token is rejected", call("GET", "auth/me", None, atok3)[0] == 401)

finish()
