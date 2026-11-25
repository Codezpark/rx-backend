#!/usr/bin/env python3
"""Documents sent to patients as a link: who may send, what opens it, and what switches it off."""
import json, random, subprocess, urllib.request, urllib.error
from _client import BASE, call, check, finish, new_doctor

MY = ["/Applications/XAMPP/xamppfiles/bin/mysql", "-uroot", "php_rest_api", "-N", "-e"]
def sql(q): return subprocess.run(MY + [q], check=True, capture_output=True, text=True).stdout.strip()
def clear(): sql("DELETE FROM rate_limits")

PDF = b"%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"

def share(token, kind, ref, content=PDF, name="doc.pdf"):
    b = "----rx" + str(random.randint(10**8, 10**9))
    body = b""
    for k, v in (("kind", kind), ("ref_id", ref)):
        body += f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    body += f'--{b}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: application/pdf\r\n\r\n'.encode() + content + f"\r\n--{b}--\r\n".encode()
    h = {"Content-Type": "multipart/form-data; boundary=" + b}
    if token: h["Authorization"] = "Bearer " + token
    try:
        with urllib.request.urlopen(urllib.request.Request(f"{BASE}/documents/share", data=body, headers=h, method="POST")) as r: return r.status, json.load(r)
    except urllib.error.HTTPError as e: return e.code, json.load(e)

def open_(tok, pin):
    req = urllib.request.Request(f"{BASE}/shared/file", method="POST", headers={"Content-Type": "application/json"}, data=json.dumps({"token": tok, "pin": pin}).encode())
    try:
        with urllib.request.urlopen(req) as r: return r.status, r.read(), dict(r.headers)
    except urllib.error.HTTPError as e: return e.code, e.read(), dict(e.headers)

code = lambda r: r["data"]["url"].split("#")[1]
clear()
doc, did = new_doctor("Dr Share")
other, _ = new_doctor("Dr Other")
mobile = "9" + "".join(random.choice("0123456789") for _ in range(9))
pin = mobile[-4:]
wrong = "0000" if pin != "0000" else "1111"
p = call("POST", "patients", {"name": "Share Patient", "sex": "female", "age_years": 30, "mobile": mobile}, doc)[1]["data"]["patient"]
nophone = call("POST", "patients", {"name": "No Phone", "sex": "male", "age_years": 50}, doc)[1]["data"]["patient"]
cbc = sql("SELECT id FROM lab_tests_catalog ORDER BY id LIMIT 1")
def make_rx(patient, status="final", lab=True):
    body = {"patient_id": patient["id"], "status": status, "diagnosis": "Fever"}
    if lab: body["investigations"] = {"lab": {"tests": [{"test_id": int(cbc)}]}}
    return call("POST", "prescriptions", body, doc)[1]["data"]["prescription"]
rx = make_rx(p)
draft = make_rx(p, "draft", lab=False)
plain = make_rx(p, lab=False)

s, r = share(doc, "prescription", rx["id"], name="Prescription_Share.pdf")
check("a doctor gets a link for a finalised prescription", s == 201 and len(code(r)) == 22 and "#" in r["data"]["url"], r)
t = code(r)
check("signing in is needed to send", share(None, "prescription", rx["id"])[0] == 401)
check("another clinic cannot send it", share(other, "prescription", rx["id"])[0] == 404)
check("a draft cannot be sent", share(doc, "prescription", draft["id"])[0] == 404)
s, r = share(doc, "prescription", make_rx(nophone, lab=False)["id"])
check("a patient without a mobile number cannot be sent a link", s == 422 and "mobile" in r["errors"], r)
check("only a PDF is accepted", share(doc, "prescription", rx["id"], content=b"<html>hello</html>")[0] == 422)
check("unknown kind refused", share(doc, "passport", rx["id"])[0] == 422)
check("test request needs tests", share(doc, "test_request", plain["id"])[0] == 404)
check("test request with tests is sent", share(doc, "test_request", rx["id"])[0] == 201)

s, r = call("POST", "shared/meta", {"token": t}, lang="hi")
check("the page learns the clinic and kind, never the patient", s == 200 and r["data"]["kind"] == "prescription" and r["data"]["clinic"] and "Share Patient" not in json.dumps(r), r)
check("a made-up code is not found", call("POST", "shared/meta", {"token": "A" * 22})[0] == 404)
check("the file needs 4 digits", open_(t, "12")[0] == 422)
check("wrong digits are refused", open_(t, wrong)[0] == 403)
s, body, h = open_(t, pin)
check("the right digits open the exact file, uncached", s == 200 and body == PDF and h.get("Content-Type") == "application/pdf" and "no-store" in h.get("Cache-Control", "") and "Prescription_Share.pdf" in h.get("Content-Disposition", ""), (s, h))

s, r = share(doc, "prescription", rx["id"], content=PDF + b"v2")
check("sending again keeps the link and replaces the file", code(r) == t and open_(t, pin)[1] == PDF + b"v2")
check("only the newest copy is kept on disk", int(sql(f"SELECT COUNT(*) FROM shared_documents WHERE doctor_id={did} AND kind='prescription' AND ref_id={rx['id']}")) == 1)

# guessing
clear()
codes = [open_(t, wrong)[0] for _ in range(5)]
check("the 5th wrong try pauses the link", codes == [403, 403, 403, 403, 429], codes)
check("even the right digits wait during the pause", open_(t, pin)[0] == 429)
for i in range(15):
    sql(f"UPDATE shared_documents SET locked_until=NULL WHERE token='{t}'"); clear(); last = open_(t, wrong)[0]
check("after 20 wrong tries the link is off for good", last == 404 and open_(t, pin)[0] == 404, last)
s, r = share(doc, "prescription", rx["id"])
check("sending again then makes a new link", code(r) != t and open_(code(r), pin)[0] == 200 and open_(t, pin)[0] == 404)
t = code(r)

# expiry and cancelling
sql(f"UPDATE shared_documents SET expires_at=UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE token='{t}'")
check("an expired link is gone", open_(t, pin)[0] == 404)
r = share(doc, "prescription", rx["id"])[1]; t = code(r)
days = int(sql(f"SELECT TIMESTAMPDIFF(DAY, UTC_TIMESTAMP(), expires_at) FROM shared_documents WHERE token='{t}'"))
check("a link lasts 90 days", days in (89, 90), days)

# lab report: only with results, and a corrected result switches the old copy off
order = rx["investigations"]["lab"]["id"]
check("a lab report needs results", share(doc, "lab_report", order)[0] == 404)
o = call("GET", f"lab-orders/{order}", token=doc)[1]["data"]["order"]
save = lambda v: call("PUT", f"lab-orders/{order}/results", {"tests": [{"id": o["tests"][0]["id"], "result_value": v}]}, doc)
save("10")
s, r = share(doc, "lab_report", order); lt = code(r)
check("a lab report is sent once results are in", s == 201 and open_(lt, pin)[0] == 200, r)
save("10")
check("saving the same results keeps the link", open_(lt, pin)[0] == 200)
save("12")
check("a corrected result switches the old copy off", open_(lt, pin)[0] == 404)
check("and re-sending brings the same link back", code(share(doc, "lab_report", order)[1]) == lt and open_(lt, pin)[0] == 200)

# receipts: reception may send them; clinical documents not
dphone = "9" + "".join(random.choice("0123456789") for _ in range(9))
call("POST", "staff", {"name": "Desk", "countrycode": "+91", "phone": dphone, "password": "Desk12345"}, doc)
desk = call("POST", "auth/login-new", {"identifier": "+91" + dphone, "password": "Desk12345"})[1]["data"]["access_token"]
bill = call("POST", "bills", {"patient_id": p["id"], "items": [{"description": "Consult", "amount": 100}]}, desk)[1]["data"]["bill"]
s, r = share(desk, "receipt", bill["id"]); bt = code(r)
check("reception sends a receipt", s == 201 and open_(bt, pin)[0] == 200, r)
check("reception cannot send a prescription", share(desk, "prescription", rx["id"])[0] in (401, 403))
call("POST", f"bills/{bill['id']}/cancel", {}, doc)
check("a cancelled bill's receipt is off", open_(bt, pin)[0] == 404)

call("POST", f"prescriptions/{rx['id']}/cancel", {}, doc)
check("cancelling the prescription switches off its link, request and report", [open_(x, pin)[0] for x in (t, lt)] == [404, 404])

# trial over: records can still be sent; and the file is not reachable from the web
sql(f"UPDATE doctors SET plan_expires_at=UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id={did}")
clear()
check("sending still works after the trial ends", share(doc, "prescription", plain["id"])[0] == 201)
path = sql(f"SELECT file_path FROM shared_documents WHERE doctor_id={did} AND ref_id={plain['id']} AND kind='prescription'")
if "localhost/" in BASE:
    try: urllib.request.urlopen(f"{BASE}/storage/{path}"); web = 200
    except urllib.error.HTTPError as e: web = e.code
    check("the stored file cannot be fetched directly", web == 403, web)
sql(f"UPDATE doctors SET is_active=0 WHERE id={did}")
last = sql(f"SELECT token FROM shared_documents WHERE doctor_id={did} AND ref_id={plain['id']} AND kind='prescription'")
check("a switched-off clinic's links stop", call("POST", "shared/meta", {"token": last})[0] == 404)
clear()
finish()
