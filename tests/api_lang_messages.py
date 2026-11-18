#!/usr/bin/env python3
"""Server messages in the clinic's language (Accept-Language): English by default, translated when asked,
English again for an unknown language, field errors and counted messages included, and every
message listed in lang/_keys.json has an entry in every language file with matching {placeholders}."""
import json, os, re
from _client import *

HERE = os.path.dirname(os.path.abspath(__file__))
LANG = os.path.join(HERE, "..", "lang")
CODES = ["hi", "gu", "mr", "ta", "te", "bn", "kn", "ml", "pa", "ur", "ne", "si", "ar", "ha", "yo", "ig", "tw"]
ENG = "Incorrect mobile number or password."

def login(lang=None):
    return call("POST", "auth/login-new", {"identifier": "+919000000001", "password": "WrongPass1"}, lang=lang)

s, r = login()
check("no Accept-Language: English", s == 401 and r["message"] == ENG, r)
s, r = login("hi")
check("Accept-Language: hi gives a Hindi message", s == 401 and r["message"] != ENG and any("ऀ" <= c <= "ॿ" for c in r["message"]), r)
s, r = login("ar")
check("Accept-Language: ar gives an Arabic message", s == 401 and any("؀" <= c <= "ۿ" for c in r["message"]), r)
s, r = login("hi-IN,hi;q=0.9,en;q=0.8")
check("a full browser-style header picks the first language we have", r["message"] != ENG, r)
s, r = login("xx")
check("an unknown language falls back to English", r["message"] == ENG, r)

tok, _ = new_doctor("Dr Lang")
s, r = call("POST", "patients", {"name": "", "sex": "male", "age_years": 30}, tok)
en_err = r["errors"]["name"][0]
s, r = call("POST", "patients", {"name": "", "sex": "male", "age_years": 30}, tok, lang="hi")
check("field errors are translated too", s == 422 and r["errors"]["name"][0] != en_err, r)

# counted messages: "attempts left" picks the right form per language
phone = "9" + "".join(random.choice("0123456789") for _ in range(9))
s, r = call("POST", "auth/register-new", {"name": "Dr Count", "password": "Secret123", "phone": phone, "countrycode": "+91"})
if s in (200, 201) and r.get("data", {}).get("dev_otp"):
    s, r = call("POST", "auth/verify-otp", {"mobile": "+91" + phone, "otp": "000000"}, lang="ar")
    check("a counted message is translated (Arabic)", s == 400 and "attempt" not in r["message"].lower(), r)
    s, r = call("POST", "auth/verify-otp", {"mobile": "+91" + phone, "otp": "000001"})
    check("and stays English by default, with the count filled in", re.search(r"\d attempts? left", r["message"]) is not None, r)

# every message has a translation in every language file, with the same placeholders
keys = json.load(open(os.path.join(LANG, "_keys.json"), encoding="utf-8"))
ph = lambda t: sorted(re.findall(r"\{\w+\}", t))
for code in CODES:
    t = json.load(open(os.path.join(LANG, code + ".json"), encoding="utf-8"))
    missing = [k for k in keys if k not in t]
    check(f"{code}: every message is translated", not missing, missing[:3])
    bad = []
    for k, v in keys.items():
        e = t.get(k)
        if e is None: continue
        forms = [e] if isinstance(e, str) else list(e.values())
        if any(ph(f) != ph(k) and not isinstance(e, dict) for f in forms): bad.append(k)
    check(f"{code}: placeholders match", not bad, bad[:3])
finish()
