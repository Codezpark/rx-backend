"""Module 2 backend checks: radiology catalogue search + PDF upload to a lab report."""
import io, json, os, sys, urllib.request, uuid
sys.path.insert(0, os.path.dirname(__file__))
from _client import call, check, new_doctor, finish, BASE

_PNG = bytes.fromhex(
    '89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c489'
    '0000000a49444154789c63000100000500010d0a2db40000000049454e44ae426082')
_PDF = b'%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n'


def upload(path, token, data, filename, ctype):
    boundary = '----rx' + uuid.uuid4().hex
    b = io.BytesIO()
    b.write(f'--{boundary}\r\n'.encode())
    b.write(f'Content-Disposition: form-data; name="file"; filename="{filename}"\r\n'.encode())
    b.write(f'Content-Type: {ctype}\r\n\r\n'.encode())
    b.write(data)
    b.write(f'\r\n--{boundary}--\r\n'.encode())
    req = urllib.request.Request(f'{BASE}/{path}', data=b.getvalue(), method='POST',
        headers={'Content-Type': f'multipart/form-data; boundary={boundary}', 'Authorization': 'Bearer ' + token})
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, json.load(r)
    except urllib.error.HTTPError as e:
        return e.code, json.load(e)


tok, did = new_doctor("Dr Mod2")
print(f"CLEANUP_IDS: {did}")

# Radiology catalogue search
s, r = call("GET", "catalog/radiology?q=ct", token=tok)
check("radiology search ok", s == 200, s)
names = [x["name"] for x in r["data"]["items"]]
check("radiology returns CT studies", any("CT" in n for n in names), names[:6])

s, r = call("GET", "catalog/radiology?q=abdomen", token=tok)
check("radiology search by body part", any("Abdomen" in x["name"] or (x.get("body_part") or "") == "Abdomen" for x in r["data"]["items"]), r["data"]["items"][:3])

# Build a lab order to attach a report to.
s, r = call("GET", "catalog/lab-tests?q=CBC", token=tok)
cbc = next((t for t in r["data"]["items"] if "Blood Count" in t["name"]), r["data"]["items"][0])
s, r = call("POST", "patients", {"name": "OCR Tester", "sex": "male", "age_years": 40, "mobile": "9123400004"}, tok)
pid = r["data"]["patient"]["id"]
s, r = call("POST", "prescriptions", {"patient_id": pid, "status": "final", "diagnosis": "Work-up",
                                      "investigations": {"lab": {"tests": [{"test_id": cbc["id"]}]}}}, tok)
check("create rx with lab order", s == 201, f"{s} {r}")
lab_id = r["data"]["prescription"]["investigations"]["lab"]["id"]

# PDF upload is now accepted.
s, r = upload(f"lab-orders/{lab_id}/file", tok, _PDF, "report.pdf", "application/pdf")
check("PDF report upload ok", s == 200, f"{s} {r}")
check("lab order has file after PDF", r["data"]["order"].get("has_file") is True, r["data"]["order"])

# The PDF streams back with a pdf content type.
req = urllib.request.Request(f'{BASE}/lab-orders/{lab_id}/file', headers={"Authorization": "Bearer " + tok})
with urllib.request.urlopen(req) as resp:
    ct = resp.headers.get("Content-Type") or ""
    body = resp.read()
check("PDF streams back as application/pdf", ct == "application/pdf" and body.startswith(b"%PDF"), f"{ct} {body[:8]}")

# Image upload still works (replaces the PDF).
s, r = upload(f"lab-orders/{lab_id}/file", tok, _PNG, "report.png", "image/png")
check("image report upload still works", s == 200 and r["data"]["order"].get("has_file") is True, f"{s} {r}")

finish()
