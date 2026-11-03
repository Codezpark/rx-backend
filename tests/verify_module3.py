"""Module 3 backend checks: clinic phones on the rx payload, signature upload/stream/remove."""
import io, json, os, sys, urllib.request, uuid
sys.path.insert(0, os.path.dirname(__file__))
from _client import call, check, new_doctor, finish, BASE

# A valid 1x1 PNG.
_PNG = bytes.fromhex(
    '89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c489'
    '0000000a49444154789c63000100000500010d0a2db40000000049454e44ae426082')


def upload(path, token):
    boundary = '----rx' + uuid.uuid4().hex
    b = io.BytesIO()
    b.write(f'--{boundary}\r\n'.encode())
    b.write(b'Content-Disposition: form-data; name="file"; filename="sig.png"\r\n')
    b.write(b'Content-Type: image/png\r\n\r\n')
    b.write(_PNG)
    b.write(f'\r\n--{boundary}--\r\n'.encode())
    req = urllib.request.Request(f'{BASE}/{path}', data=b.getvalue(), method='POST',
        headers={'Content-Type': f'multipart/form-data; boundary={boundary}', 'Authorization': 'Bearer ' + token})
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, json.load(r)
    except urllib.error.HTTPError as e:
        return e.code, json.load(e)


tok, did = new_doctor("Dr Mod3")
print(f"CLEANUP_IDS: {did}")

# Clinic landline + mobile should ride along on a prescription's clinic payload (for the letterhead).
call("PUT", "doctor/clinic", {"name": "Sunrise Clinic", "landline": "0261-2345678", "mobile": "9876501234", "city": "Rajkot"}, tok)
s, r = call("POST", "patients", {"name": "Sig Tester", "sex": "male", "age_years": 30, "mobile": "9123400003"}, tok)
pid = r["data"]["patient"]["id"]
s, r = call("POST", "prescriptions", {"patient_id": pid, "status": "final", "diagnosis": "Test"}, tok)
cl = r["data"]["prescription"]["clinic"]
check("rx clinic carries landline", cl.get("landline") == "0261-2345678", cl)
check("rx clinic carries mobile", cl.get("mobile") == "9876501234", cl)

# Signature: starts absent.
s, r = call("GET", "doctor/profile", None, tok)
check("no signature initially", r["data"]["user"].get("has_signature") is False, r["data"]["user"])

# Signature streaming before upload → 404.
s, r = call("GET", "doctor/signature", None, tok)
check("signature 404 before upload", s == 404, s)

# Upload.
s, r = upload("doctor/signature", tok)
check("upload signature ok", s == 200, f"{s} {r}")
check("has_signature now true", r["data"]["user"].get("has_signature") is True, r["data"]["user"])

# Streams back as an image.
req = urllib.request.Request(f'{BASE}/doctor/signature', headers={"Authorization": "Bearer " + tok})
with urllib.request.urlopen(req) as resp:
    ct = resp.headers.get("Content-Type") or ""
    n = len(resp.read())
check("signature streams as image", ct.startswith("image/") and n > 0, f"{ct} {n}")

# Another doctor cannot read this doctor's signature (each streams their own).
tokB, didB = new_doctor("Dr Mod3B")
print(f"CLEANUP_IDS: {didB}")
s, r = call("GET", "doctor/signature", None, tokB)
check("other doctor has no signature", s == 404, s)

# Remove.
s, r = call("DELETE", "doctor/signature", None, tok)
check("remove signature ok", s == 200 and r["data"]["user"].get("has_signature") is False, f"{s} {r}")

# ── Clinic logo (shown top-left on the letterhead) ────────────────────────────
s, r = call("GET", "doctor/clinic", None, tok)
check("no clinic logo initially", r["data"]["clinic"].get("has_logo") is False, r["data"]["clinic"])
s, r = call("GET", "doctor/clinic-logo", None, tok)
check("clinic logo 404 before upload", s == 404, s)
s, r = upload("doctor/clinic-logo", tok)
check("upload clinic logo ok", s == 200 and r["data"]["clinic"].get("has_logo") is True, f"{s} {r}")
req = urllib.request.Request(f'{BASE}/doctor/clinic-logo', headers={"Authorization": "Bearer " + tok})
with urllib.request.urlopen(req) as resp:
    ct = resp.headers.get("Content-Type", "")
    n = len(resp.read())
check("clinic logo streams as image", ct.startswith("image/") and n > 0, f"{ct} {n}")
# The rx payload should now advertise the logo for the PDF letterhead.
s, r = call("POST", "prescriptions", {"patient_id": pid, "status": "final", "diagnosis": "Logo test"}, tok)
check("rx clinic carries has_logo", r["data"]["prescription"]["clinic"].get("has_logo") is True, r["data"]["prescription"]["clinic"])
s, r = call("GET", "doctor/clinic-logo", None, tokB)
check("other doctor has no clinic logo", s == 404, s)
s, r = call("DELETE", "doctor/clinic-logo", None, tok)
check("remove clinic logo ok", s == 200 and r["data"]["clinic"].get("has_logo") is False, f"{s} {r}")

finish()
