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
