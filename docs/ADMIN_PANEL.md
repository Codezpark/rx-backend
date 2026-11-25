# Rx Admin (back office)

- **UI:** static PWA in `prescriptionrx/web/public/admin/` (no build step), served at `https://prescriptionrx.in/admin/` by `deploy_front.sh`. Installable ("Rx Admin", black Rx icon). `noindex`, and `/admin/` is disallowed in robots.txt.
- **API:** `app/controllers/AdminController.php`, routes `admin/*`. Admin accounts are in the `admins` table; their token has `role: admin` (12 h) and is refused by every doctor / reception endpoint, and doctor tokens are refused by `admin/*`.
- **Shows counts and account facts only**, never patient or prescription content.
- **Last active** = latest sign-in or session refresh by the doctor or any receptionist (`doctors.last_login_at`, `clinic_staff.last_login_at`).

## Go live
1. Apply `migrations/sql/021_admin_panel.sql` and `022_admin_sessions.sql` on production (SQL file directly, like 019/020).
2. Deploy the backend, then `deploy_front.sh`.
3. Create yourself an admin on the server: `php tools/create_admin.php you@example.com "Your Name"` (asks for the password).
4. Open `/admin/` and use "Add to Home Screen".

## Local
Admin UI against local XAMPP: serve `public/admin` with `python3 -m http.server 8765` (it talks to `http://localhost/prescription-rx/rx-backend`). Test: `python3 tests/api_admin.py`.

## Erase all user data (Admins page, "Danger zone")

Removes every clinic, doctor, staff account, patient, appointment, prescription, bill and upload. Keeps admins, the shipped medicine / lab / imaging / paediatric / advice lists (rows with no `doctor_id`) and the legacy `users` table. Needs the signed-in admin's password again plus the phrase `DELETE ALL DATA` (`POST admin/data/wipe`).

- Before deleting, every removed row is written to `storage/backups/wipe-*.jsonl.gz` and uploaded files are moved to `storage/backups/wipe-*-files/`. These contain patient data: delete them once they are no longer needed.
- A new database table must be added to one of the lists in `helpers/DataWipeHelper.php`, otherwise the erase refuses to run.
- **Once real clinics are on the system, switch it off:** create an empty file `storage/wipe.disabled` on the server.
- Test: `tests/api_admin_wipe.py` (throw-away database only; see its docstring).
