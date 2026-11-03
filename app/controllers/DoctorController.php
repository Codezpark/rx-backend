<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';

/**
 * DoctorController — the signed-in doctor's own profile.
 *
 * GET /doctor/profile          → profile()
 * PUT /doctor/profile          → updateProfile()
 * PUT /doctor/change-password  → changePassword()
 * GET /dashboard/summary       → summary()
 * GET/PUT /doctor/clinic       → clinic() / updateClinic()
 */
class DoctorController extends BaseController
{
    /**
     * Numbers for the home screen. `since` (UTC datetime) is the start of the
     * doctor's local day, so "today" follows their clock, not the server's.
     */
    public function summary(): void
    {
        $d   = (int) $this->requireClinic()['id'];
        $row = $this->db->getRow(
            'SELECT COUNT(*) AS total, COALESCE(SUM(created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)), 0) AS this_week
             FROM patients WHERE doctor_id = :d AND deleted_at IS NULL AND is_active = 1',
            [':d' => $d]
        );
        $since = strtotime((string) $this->request->get('since', ''));
        $since = $since === false ? strtotime('today') : $since;
        $rx = $this->db->getRow(
            "SELECT COUNT(*) AS total, COALESCE(SUM(visit_date >= :s), 0) AS today
             FROM prescriptions WHERE doctor_id = :d AND deleted_at IS NULL AND status = 'final'",
            [':d' => $d, ':s' => gmdate('Y-m-d H:i:s', $since)]
        );
        $until = gmdate('Y-m-d H:i:s', $since + 86400);
        $ap = $this->db->getRow(
            "SELECT COALESCE(SUM(status = 'arrived'), 0) AS waiting,
                    COALESCE(SUM(status IN ('scheduled','confirmed')), 0) AS upcoming,
                    COALESCE(SUM(status = 'completed'), 0) AS done,
                    COALESCE(SUM(status <> 'cancelled'), 0) AS total
             FROM appointments WHERE doctor_id = :d AND scheduled_at >= :s AND scheduled_at < :u",
            [':d' => $d, ':s' => gmdate('Y-m-d H:i:s', $since), ':u' => $until]
        );
        $pending = $this->db->getRow(
            "SELECT
               (SELECT COUNT(*) FROM lab_orders o JOIN prescriptions p ON p.id = o.prescription_id AND p.status = 'final' AND p.deleted_at IS NULL
                 WHERE o.doctor_id = :d1 AND o.deleted_at IS NULL AND o.status IN ('ordered','sample_collected','processing'))
             + (SELECT COUNT(*) FROM radiology_orders o JOIN prescriptions p ON p.id = o.prescription_id AND p.status = 'final' AND p.deleted_at IS NULL
                 WHERE o.doctor_id = :d2 AND o.deleted_at IS NULL AND o.status = 'ordered') AS n",
            [':d1' => $d, ':d2' => $d]
        );
        $drafts = $this->db->getRow("SELECT COUNT(*) AS n FROM prescriptions WHERE doctor_id = :d AND deleted_at IS NULL AND status = 'draft'", [':d' => $d]);
        if ($this->isStaff()) {
            $coll = $this->db->getRow('SELECT COALESCE(SUM(amount), 0) AS n FROM bill_payments WHERE doctor_id = :d AND paid_at >= :s AND paid_at < :u', [':d' => $d, ':s' => gmdate('Y-m-d H:i:s', $since), ':u' => $until]);
            Response::success([
                'collected_today' => (float) $coll['n'], 'patients_total' => (int) $row['total'], 'patients_this_week' => (int) $row['this_week'],
                'queue_waiting' => (int) $ap['waiting'], 'queue_upcoming' => (int) $ap['upcoming'], 'queue_done' => (int) $ap['done'], 'appointments_today' => (int) $ap['total'],
            ], 'Summary fetched');
        }
        $coll = $this->db->getRow('SELECT COALESCE(SUM(amount), 0) AS n FROM bill_payments WHERE doctor_id = :d AND paid_at >= :s AND paid_at < :u', [':d' => $d, ':s' => gmdate('Y-m-d H:i:s', $since), ':u' => $until]);
        Response::success([
            'collected_today'     => (float) $coll['n'],
            'patients_total'      => (int) $row['total'],
            'patients_this_week'  => (int) $row['this_week'],
            'prescriptions_total' => (int) $rx['total'],
            'prescriptions_today' => (int) $rx['today'],
            'drafts'              => (int) $drafts['n'],
            'orders_pending'      => (int) $pending['n'],
            'queue_waiting'       => (int) $ap['waiting'],
            'queue_upcoming'      => (int) $ap['upcoming'],
            'queue_done'          => (int) $ap['done'],
            'appointments_today'  => (int) $ap['total'],
            'attention'           => FollowupHelper::compute($d, (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->request->get('today', '')) ? (string) $this->request->get('today') : gmdate('Y-m-d')))['attention'],
        ], 'Summary fetched');
    }

    // ── Clinic (printed on every prescription) ───────────────────────────────
    public function clinic(): void
    {
        $d = (int) $this->requireClinic()['id'];
        Response::success(['clinic' => $this->clinicPayload($this->primaryClinic($d))], 'Clinic fetched');
    }

    /** GET doctor/consult-fee?patient_id= — suggest the case type + fee for a visit (Module 4.2). */
    public function consultFee(): void
    {
        $d   = (int) $this->requireClinic()['id'];
        $pid = (int) $this->request->get('patient_id', 0);
        if ($pid <= 0 || !$this->db->getRow('SELECT id FROM patients WHERE id = :i AND doctor_id = :d AND deleted_at IS NULL', [':i' => $pid, ':d' => $d])) {
            Response::validationError(['patient_id' => ['Choose a patient from your register.']]);
        }
        // Honour the case type the receptionist tagged at walk-in, when a queue
        // appointment is given, so the suggested fee matches the front-desk tag.
        $caseType = null;
        $apId = (int) $this->request->get('appointment_id', 0);
        if ($apId > 0) {
            $ap = $this->db->getRow('SELECT case_type FROM appointments WHERE id = :i AND doctor_id = :d', [':i' => $apId, ':d' => $d]);
            if ($ap && $ap['case_type'] !== null && $ap['case_type'] !== '') $caseType = (string) $ap['case_type'];
        }
        Response::success(['fee' => FeeHelper::suggest($d, $pid, null, $caseType)], 'Fee suggested');
    }

    public function updateClinic(): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $this->validate([
            'name'          => 'required|string|min:2|max:200',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city'          => 'nullable|string|max:100',
            'state'         => 'nullable|string|max:100',
            'pincode'       => 'nullable|string|max:10',
            'phone'         => 'nullable|string|max:20',
            'landline'      => 'nullable|string|max:20',
            'mobile'        => 'nullable|string|max:20',
            'timings'       => 'nullable|string|max:500',
        ]);
        $fee = $this->request->get('consultation_fee');
        if ($fee !== null && $fee !== '' && (!is_numeric($fee) || (float) $fee < 0 || (float) $fee > 1000000)) {
            Response::validationError(['consultation_fee' => ['Enter a fee between 0 and 10,00,000.']]);
        }
        // Fee tiers (Module 4.2): each optional; consultation_fee stays the fallback.
        $tiers = [];
        foreach (['fee_new', 'fee_old', 'fee_emergency'] as $tk) {
            $tv = $this->request->get($tk);
            if ($tv === null || $tv === '') { $tiers[$tk] = null; continue; }
            if (!is_numeric($tv) || (float) $tv < 0 || (float) $tv > 1000000) {
                Response::validationError([$tk => ['Enter a fee between 0 and 10,00,000.']]);
            }
            $tiers[$tk] = round((float) $tv, 2);
        }
        $window = $this->request->get('old_case_window_days');
        if ($window === null || $window === '') { $window = 30; }
        elseif (!ctype_digit((string) $window) || (int) $window < 0 || (int) $window > 3650) {
            Response::validationError(['old_case_window_days' => ['Enter a number of days between 0 and 3650.']]);
        } else { $window = (int) $window; }
        $freeNext = filter_var($this->request->get('free_next_day'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $opt = fn (string $k) => ($v = self::clean((string) ($this->request->get($k) ?? ''))) === '' ? null : $v;
        $name = self::clean((string) $this->request->get('name'));
        if (mb_strlen($name) < 2) {
            Response::validationError(['name' => ['Please enter the clinic name.']]);
        }

        // Separate landline + mobile for the letterhead, stored in clinic_contacts.
        $mobile = $opt('mobile');
        if ($mobile !== null) {
            $mobile = preg_replace('/\D/', '', $mobile);
            if (!preg_match('/^\d{10}$/', (string) $mobile)) {
                Response::validationError(['mobile' => ['Enter a 10-digit mobile number.']]);
            }
        }
        $landline = $opt('landline');  // landline may carry an STD code and separators

        $data = ['name' => $name] + array_combine(
            ['address_line1', 'address_line2', 'city', 'state', 'pincode', 'timings'],
            array_map($opt, ['address_line1', 'address_line2', 'city', 'state', 'pincode', 'timings'])
        );
        // Keep the legacy single `phone` column in sync so older views still show a number.
        $data['phone'] = $mobile ?? $landline ?? $opt('phone');
        $data['consultation_fee'] = ($fee === null || $fee === '') ? null : round((float) $fee, 2);
        $data += $tiers;
        $data['old_case_window_days'] = $window;
        $data['free_next_day'] = $freeNext;

        $existing = $this->primaryClinic($d);
        if ($existing) {
            $this->db->update('clinics', $data, 'id = :id', [':id' => $existing['id']]);
            $clinicId = (int) $existing['id'];
        } else {
            $clinicId = (int) $this->db->insert('clinics', $data + ['doctor_id' => $d, 'is_primary' => 1]);
        }
        $this->saveClinicPhones($clinicId, $landline, $mobile);

        Response::success(['clinic' => $this->clinicPayload($this->primaryClinic($d))], 'Clinic saved');
    }

    private function primaryClinic(int $doctorId): ?array
    {
        return $this->db->getRow(
            'SELECT * FROM clinics WHERE doctor_id = :d AND deleted_at IS NULL ORDER BY is_primary DESC, id ASC LIMIT 1',
            [':d' => $doctorId]
        );
    }

    private static function publicClinic(?array $c): ?array
    {
        if (!$c) return null;
        return [
            'name' => $c['name'], 'address_line1' => $c['address_line1'], 'address_line2' => $c['address_line2'],
            'city' => $c['city'], 'state' => $c['state'], 'pincode' => $c['pincode'], 'phone' => $c['phone'], 'timings' => $c['timings'],
            'consultation_fee' => $c['consultation_fee'] === null ? null : (float) $c['consultation_fee'],
            'fee_new' => isset($c['fee_new']) && $c['fee_new'] !== null ? (float) $c['fee_new'] : null,
            'fee_old' => isset($c['fee_old']) && $c['fee_old'] !== null ? (float) $c['fee_old'] : null,
            'fee_emergency' => isset($c['fee_emergency']) && $c['fee_emergency'] !== null ? (float) $c['fee_emergency'] : null,
            'old_case_window_days' => isset($c['old_case_window_days']) ? (int) $c['old_case_window_days'] : 30,
            'free_next_day' => (bool) ($c['free_next_day'] ?? false),
            'has_logo' => !empty($c['logo_url']),
        ];
    }

    /** publicClinic() plus the landline/mobile pulled from clinic_contacts. */
    private function clinicPayload(?array $c): ?array
    {
        $out = self::publicClinic($c);
        if ($out !== null && $c) {
            $out = array_merge($out, $this->phonesFor((int) $c['id']));
        }
        return $out;
    }

    /** The clinic's primary landline + mobile from clinic_contacts (one of each). */
    private function phonesFor(int $clinicId): array
    {
        $pick = fn (string $type) => $this->db->getRow(
            'SELECT number FROM clinic_contacts WHERE clinic_id = :c AND type = :t AND is_active = 1 ORDER BY sort_order, id LIMIT 1',
            [':c' => $clinicId, ':t' => $type]
        );
        return ['landline' => $pick('landline')['number'] ?? null, 'mobile' => $pick('mobile')['number'] ?? null];
    }

    /** Upsert a single landline + a single mobile row; clearing a field deactivates it. */
    private function saveClinicPhones(int $clinicId, ?string $landline, ?string $mobile): void
    {
        foreach (['landline' => $landline, 'mobile' => $mobile] as $type => $number) {
            $existing = $this->db->getRow(
                'SELECT id FROM clinic_contacts WHERE clinic_id = :c AND type = :t ORDER BY sort_order, id LIMIT 1',
                [':c' => $clinicId, ':t' => $type]
            );
            if ($number === null || $number === '') {
                if ($existing) $this->db->update('clinic_contacts', ['is_active' => 0], 'id = :i', [':i' => $existing['id']]);
                continue;
            }
            if ($existing) {
                $this->db->update('clinic_contacts', ['number' => $number, 'is_active' => 1, 'show_on_rx' => 1], 'id = :i', [':i' => $existing['id']]);
            } else {
                $this->db->insert('clinic_contacts', [
                    'clinic_id' => $clinicId, 'type' => $type, 'number' => $number,
                    'label' => $type === 'landline' ? 'Clinic landline' : 'Clinic mobile',
                    'show_on_rx' => 1, 'sort_order' => $type === 'landline' ? 1 : 2, 'is_active' => 1,
                ]);
            }
        }
    }

    public function profile(): void
    {
        Response::success(['user' => self::publicDoctor($this->requireDoctor())], 'Profile fetched');
    }

    public function updateProfile(): void
    {
        $doctor = $this->requireDoctor();

        $data = $this->validate([
            'name'            => 'required|string|min:2|max:100',
            'email'           => 'nullable|email|max:191',
            'degree'          => 'nullable|string|max:255',
            'specialization'  => 'nullable|string|max:255',
            'registration_no' => 'nullable|string|max:100',
            'experience_yrs'  => 'nullable|integer',
        ]);

        $text = fn (string $key) => ($v = self::clean((string) ($data[$key] ?? ''))) === '' ? null : $v;

        $name = self::clean((string) $data['name']);
        if (mb_strlen($name) < 2) {
            Response::validationError(['name' => ['Please enter your full name.']]);
        }

        $email = $text('email');
        $email = $email === null ? null : strtolower($email);
        $regNo = $text('registration_no');

        $years = $data['experience_yrs'] ?? null;
        if ($years !== null && $years !== '') {
            if ((int) $years < 0 || (int) $years > 70) {
                Response::validationError(['experience_yrs' => ['Experience must be between 0 and 70 years.']]);
            }
            $years = (int) $years;
        } else {
            $years = null;
        }

        foreach (['email' => $email, 'registration_no' => $regNo] as $column => $value) {
            if ($value === null) continue;
            $clash = $this->db->getRow("SELECT id FROM doctors WHERE {$column} = :v AND id <> :id", [':v' => $value, ':id' => $doctor['id']]);
            if ($clash) {
                $label = $column === 'email' ? 'e-mail address' : 'registration number';
                Response::validationError([$column => ["This {$label} is already used by another account."]]);
            }
        }

        $this->db->update('doctors', [
            'name'            => $name,
            'email'           => $email,
            'degree'          => $text('degree'),
            'specialization'  => $text('specialization'),
            'registration_no' => $regNo,
            'experience_yrs'  => $years,
        ], 'id = :id', [':id' => $doctor['id']]);

        $fresh = $this->db->getRow('SELECT * FROM doctors WHERE id = :id', [':id' => $doctor['id']]);
        Response::success(['user' => self::publicDoctor($fresh)], 'Profile updated');
    }

    public function changePassword(): void
    {
        $doctor = $this->requireDoctor();

        $data = $this->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|max:64',
        ]);

        if (!CommonHelper::verifyPassword($data['current_password'], $doctor['password_hash'])) {
            Response::validationError(['current_password' => ['Your current password is incorrect.']]);
        }
        if (!preg_match('/[A-Za-z]/', $data['password']) || !preg_match('/\d/', $data['password'])) {
            Response::validationError(['password' => ['Use at least one letter and one number.']]);
        }
        if ($data['password'] === $data['current_password']) {
            Response::validationError(['password' => ['Choose a password different from the current one.']]);
        }

        $this->db->update('doctors', ['password_hash' => CommonHelper::hashPassword($data['password'])], 'id = :id', [':id' => $doctor['id']]);

        // Sign out everywhere else, but keep this device signed in with a fresh session.
        $this->auth->revokeAllForDoctor((int) $doctor['id']);

        Response::success([
            'access_token'  => $this->auth->generateToken(['doctor_id' => (int) $doctor['id'], 'role' => 'doctor']),
            'refresh_token' => $this->auth->generateRefreshToken((int) $doctor['id']),
            'token_type'    => 'Bearer',
            'expires_in'    => Config::get('jwt.expiry'),
        ], 'Password changed');
    }

    // ── Signature image (printed on the prescription) ────────────────────────
    private const SIG_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function uploadSignature(): void
    {
        $doctor = $this->requireDoctor();
        $did    = (int) $doctor['id'];
        $f      = $this->request->file('file');
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Response::validationError(['file' => ['Choose a signature image.']]);
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            Response::validationError(['file' => ['The upload did not complete. Please try again.']]);
        }
        if ($f['size'] > 2 * 1024 * 1024) {
            Response::validationError(['file' => ['The image is too large (max 2 MB).']]);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset(self::SIG_MIME[$mime])) {
            Response::validationError(['file' => ['Only JPG, PNG or WebP images are accepted.']]);
        }

        $dir = ROOT_PATH . '/storage/signatures/' . $did;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the signatures folder.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . self::SIG_MIME[$mime];
        if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) {
            throw new RuntimeException('Could not save the uploaded file.');
        }
        @chmod("$dir/$name", 0664);
        @chmod($dir, 0775);

        $this->removeSignatureFile($doctor['signature_url'] ?? null);
        $this->db->update('doctors', ['signature_url' => "signatures/$did/$name"], 'id = :id', [':id' => $did]);

        Response::success(['user' => self::publicDoctor($this->db->getRow('SELECT * FROM doctors WHERE id = :id', [':id' => $did]))], 'Signature saved');
    }

    public function signatureFile(): never
    {
        $rel  = $this->requireDoctor()['signature_url'] ?? null;
        if (!$rel) Response::notFound('No signature uploaded.');
        $full = realpath(ROOT_PATH . '/storage/' . $rel);
        $base = realpath(ROOT_PATH . '/storage/signatures');
        if (!$full || !$base || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) {
            Response::notFound('The signature file is missing.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($full);
        header_remove('Content-Type');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($full));
        header('Cache-Control: private, max-age=3600');
        readfile($full);
        exit;
    }

    public function removeSignature(): void
    {
        $doctor = $this->requireDoctor();
        $this->removeSignatureFile($doctor['signature_url'] ?? null);
        $this->db->update('doctors', ['signature_url' => null], 'id = :id', [':id' => (int) $doctor['id']]);
        Response::success(['user' => self::publicDoctor($this->db->getRow('SELECT * FROM doctors WHERE id = :id', [':id' => (int) $doctor['id']]))], 'Signature removed');
    }

    private function removeSignatureFile(?string $rel): void
    {
        if (!$rel) return;
        $full = realpath(ROOT_PATH . '/storage/' . $rel);
        $base = realpath(ROOT_PATH . '/storage/signatures');
        if ($full && $base && str_starts_with($full, $base . DIRECTORY_SEPARATOR) && is_file($full)) @unlink($full);
    }

    // ── Clinic logo (shown top-left on the Rx letterhead) ────────────────────
    public function uploadClinicLogo(): void
    {
        $doctor = $this->requireDoctor();
        $did    = (int) $doctor['id'];
        $clinic = $this->primaryClinic($did);
        if (!$clinic) Response::validationError(['clinic' => ['Save your clinic details first.']]);

        $f = $this->request->file('file');
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Response::validationError(['file' => ['Choose a logo image.']]);
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            Response::validationError(['file' => ['The upload did not complete. Please try again.']]);
        }
        if ($f['size'] > 2 * 1024 * 1024) {
            Response::validationError(['file' => ['The image is too large (max 2 MB).']]);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset(self::SIG_MIME[$mime])) {
            Response::validationError(['file' => ['Only JPG, PNG or WebP images are accepted.']]);
        }

        $dir = ROOT_PATH . '/storage/logos/' . $did;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the logos folder.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . self::SIG_MIME[$mime];
        if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) {
            throw new RuntimeException('Could not save the uploaded file.');
        }
        @chmod("$dir/$name", 0664);
        @chmod($dir, 0775);

        $this->removeLogoFile($clinic['logo_url'] ?? null);
        $this->db->update('clinics', ['logo_url' => "logos/$did/$name"], 'id = :id', [':id' => (int) $clinic['id']]);

        Response::success(['clinic' => $this->clinicPayload($this->primaryClinic($did))], 'Logo saved');
    }

    public function clinicLogoFile(): never
    {
        $clinic = $this->primaryClinic((int) $this->requireDoctor()['id']);
        $rel    = $clinic['logo_url'] ?? null;
        if (!$rel) Response::notFound('No logo uploaded.');
        $full = realpath(ROOT_PATH . '/storage/' . $rel);
        $base = realpath(ROOT_PATH . '/storage/logos');
        if (!$full || !$base || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) {
            Response::notFound('The logo file is missing.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($full);
        header_remove('Content-Type');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($full));
        header('Cache-Control: private, max-age=3600');
        readfile($full);
        exit;
    }

    public function removeClinicLogo(): void
    {
        $did    = (int) $this->requireDoctor()['id'];
        $clinic = $this->primaryClinic($did);
        if ($clinic) {
            $this->removeLogoFile($clinic['logo_url'] ?? null);
            $this->db->update('clinics', ['logo_url' => null], 'id = :id', [':id' => (int) $clinic['id']]);
        }
        Response::success(['clinic' => $this->clinicPayload($this->primaryClinic($did))], 'Logo removed');
    }

    private function removeLogoFile(?string $rel): void
    {
        if (!$rel) return;
        $full = realpath(ROOT_PATH . '/storage/' . $rel);
        $base = realpath(ROOT_PATH . '/storage/logos');
        if ($full && $base && str_starts_with($full, $base . DIRECTORY_SEPARATOR) && is_file($full)) @unlink($full);
    }
}
