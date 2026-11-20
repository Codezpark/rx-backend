<?php

declare(strict_types=1);

/**
 * Back-office admin API (the Rx Admin panel). Admin accounts live in their own table and get a token with
 * role "admin" - it is rejected by every doctor / reception endpoint, and doctor tokens are rejected here.
 * Only counts and account facts are exposed, never patient or prescription content.
 */
class AdminController extends BaseController
{
    private const TOKEN_TTL  = 43200; // 12 h
    private const PAGE_SIZE  = 20;
    private const NEVER      = '1970-01-01 00:00:00';
    private const PLANS      = ['trial', 'basic', 'pro', 'enterprise'];

    // ── Auth ─────────────────────────────────────────────────────────────────
    /** POST admin/login {email, password} */
    public function login(): void
    {
        $email = strtolower(trim((string) $this->request->input('email')));
        $pw    = (string) ($this->request->all()['password'] ?? '');
        $a     = $this->db->getRow('SELECT * FROM admins WHERE email = :e', [':e' => $email]);
        if (!$a || (int) $a['is_active'] !== 1 || !CommonHelper::verifyPassword($pw, $a['password_hash'])) {
            Response::error('Incorrect email or password.', 401);
        }
        $this->db->update('admins', ['last_login_at' => gmdate('Y-m-d H:i:s')], 'id = :i', [':i' => $a['id']]);
        Response::success([
            'admin'        => ['id' => (int) $a['id'], 'name' => $a['name'], 'email' => $a['email']],
            'access_token' => $this->auth->generateToken(['admin_id' => (int) $a['id'], 'role' => 'admin'], self::TOKEN_TTL),
            'expires_in'   => self::TOKEN_TTL,
        ], 'Login successful');
    }

    /** GET admin/me */
    public function me(): void
    {
        $a = $this->requireAdmin();
        Response::success(['admin' => ['id' => (int) $a['id'], 'name' => $a['name'], 'email' => $a['email']]]);
    }

    /** POST admin/password {current, password} */
    public function changePassword(): void
    {
        $a  = $this->requireAdmin();
        $in = $this->request->all();
        $cur = (string) ($in['current'] ?? '');
        $new = (string) ($in['password'] ?? '');
        if (!CommonHelper::verifyPassword($cur, $a['password_hash'])) {
            Response::validationError(['current' => ['Your current password is not correct.']]);
        }
        if (strlen($new) < 10 || strlen($new) > 64) {
            Response::validationError(['password' => ['Use 10 to 64 characters.']]);
        }
        if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            Response::validationError(['password' => ['Use at least one letter and one number.']]);
        }
        $this->db->update('admins', ['password_hash' => CommonHelper::hashPassword($new)], 'id = :i', [':i' => $a['id']]);
        Response::success([], 'Password changed.');
    }

    private function requireAdmin(): array
    {
        $auth = $this->requireAuth();
        if (($auth['role'] ?? '') !== 'admin') {
            Response::forbidden('Admins only.');
        }
        $a = $this->db->getRow('SELECT * FROM admins WHERE id = :i AND is_active = 1', [':i' => (int) ($auth['admin_id'] ?? 0)]);
        if (!$a) {
            Response::unauthorized('Your session is no longer valid. Please sign in again.');
        }
        if (!empty($a['sessions_valid_from']) && (int) ($auth['iat'] ?? 0) < strtotime((string) $a['sessions_valid_from'])) {
            Response::unauthorized('Your session was ended. Please sign in again.');
        }
        return $a;
    }

    private static function shapeAdmin(array $a, int $me): array
    {
        return ['id' => (int) $a['id'], 'name' => $a['name'], 'email' => $a['email'], 'active' => (int) $a['is_active'] === 1,
                'last_login_at' => $a['last_login_at'], 'created_at' => $a['created_at'], 'you' => (int) $a['id'] === $me];
    }

    // ── Admins ───────────────────────────────────────────────────────────────
    /** GET admin/admins */
    public function admins(): void
    {
        $me = $this->requireAdmin();
        Response::success(['items' => array_map(fn ($a) => self::shapeAdmin($a, (int) $me['id']), $this->db->getRows('SELECT * FROM admins ORDER BY id'))]);
    }

    /** POST admin/admins {name, email, password?} */
    public function createAdmin(): void
    {
        $this->requireAdmin();
        $in    = $this->request->all();
        $name  = self::clean((string) ($in['name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if (mb_strlen($name) < 2) Response::validationError(['name' => ['Please enter a name.']]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::validationError(['email' => ['Enter a valid email address.']]);
        if ($this->db->getRow('SELECT id FROM admins WHERE email = :e', [':e' => $email])) {
            Response::error('This email is already an admin.', 409, ['email' => ['This email is already an admin.']]);
        }
        $pw = trim((string) ($in['password'] ?? ''));
        if ($pw === '') {
            $pw = self::generatePassword();
        } elseif (strlen($pw) < 10 || strlen($pw) > 64 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            Response::validationError(['password' => ['Use 10 to 64 characters with a letter and a number.']]);
        }
        $id = $this->db->insert('admins', ['name' => mb_substr($name, 0, 100), 'email' => $email, 'password_hash' => CommonHelper::hashPassword($pw)]);
        Response::created(['id' => $id, 'email' => $email, 'password' => $pw], 'Admin added.');
    }

    /** POST admin/admins/{id}/action {action: reset_password|disable|enable|delete} */
    public function adminAction(array $params): void
    {
        $me = $this->requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $a  = $this->db->getRow('SELECT * FROM admins WHERE id = :i', [':i' => $id]);
        if (!$a) Response::notFound('Admin not found.');
        $act = (string) ($this->request->all()['action'] ?? '');
        $self = $id === (int) $me['id'];
        $now  = date('Y-m-d H:i:s');
        switch ($act) {
            case 'reset_password':
                if ($self) Response::error('Use "Change password" for your own account.', 422);
                $pw = self::generatePassword() . '-' . random_int(10, 99); // 15 chars: meets the 10+ admin rule
                $this->db->update('admins', ['password_hash' => CommonHelper::hashPassword($pw), 'sessions_valid_from' => $now], 'id = :i', [':i' => $id]);
                Response::success(['email' => $a['email'], 'password' => $pw], 'New password set.');
            case 'disable':
                if ($self) Response::error('You cannot switch off your own account.', 422);
                $this->db->update('admins', ['is_active' => 0, 'sessions_valid_from' => $now], 'id = :i', [':i' => $id]);
                Response::success([], 'Admin switched off.');
            case 'enable':
                $this->db->update('admins', ['is_active' => 1], 'id = :i', [':i' => $id]);
                Response::success([], 'Admin switched on.');
            case 'delete':
                if ($self) Response::error('You cannot remove your own account.', 422);
                $this->db->delete('admins', 'id = :i', [':i' => $id]);
                Response::success([], 'Admin removed.');
            default:
                Response::validationError(['action' => ['Unknown action.']]);
        }
    }

    // ── Staff (receptionists) across all clinics ─────────────────────────────
    /** GET admin/staff?q=&status=all|on|off|quiet&page= */
    public function staffList(): void
    {
        $this->requireAdmin();
        $q = trim((string) $this->request->get('q', ''));
        $status = (string) $this->request->get('status', 'all');
        $page = max(1, (int) $this->request->get('page', 1));
        $where = 's.deleted_at IS NULL AND d.deleted_at IS NULL';
        $p = [];
        if ($q !== '') {
            $where .= ' AND (s.name LIKE :q1 OR s.mobile LIKE :q2 OR d.name LIKE :q3 OR EXISTS (SELECT 1 FROM clinics c WHERE c.doctor_id = d.id AND c.deleted_at IS NULL AND c.name LIKE :q4))';
            $p = [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%", ':q4' => "%$q%"];
        }
        $where .= match ($status) {
            'on'    => ' AND s.is_active = 1',
            'off'   => ' AND s.is_active = 0',
            'quiet' => ' AND s.is_active = 1 AND (s.last_login_at IS NULL OR s.last_login_at < NOW() - INTERVAL 7 DAY)',
            default => '',
        };
        $from  = 'FROM clinic_staff s JOIN doctors d ON d.id = s.doctor_id WHERE ' . $where;
        $total = $this->count('SELECT COUNT(*) AS n ' . $from, $p);
        $rows  = $this->db->getRows('SELECT s.id, s.name, s.mobile, s.is_active, s.last_login_at, s.created_at, d.id AS clinic_id, d.name AS doctor,
                (SELECT c.name FROM clinics c WHERE c.doctor_id = d.id AND c.deleted_at IS NULL ORDER BY c.is_primary DESC, c.id LIMIT 1) AS clinic
            ' . $from . ' ORDER BY s.created_at DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE), $p);
        Response::success(['items' => array_map(fn ($r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'mobile' => $r['mobile'], 'active' => (int) $r['is_active'] === 1,
            'last_login_at' => $r['last_login_at'], 'created_at' => $r['created_at'],
            'clinic_id' => (int) $r['clinic_id'], 'clinic' => $r['clinic'] ?: null, 'doctor' => $r['doctor'],
        ], $rows), 'page' => $page, 'per_page' => self::PAGE_SIZE, 'total' => $total]);
    }

    /** POST admin/staff/{id}/action {action: reset_password|enable|disable|remove|rename, name?} */
    public function staffAction(array $params): void
    {
        $this->requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $s  = $this->db->getRow('SELECT * FROM clinic_staff WHERE id = :i AND deleted_at IS NULL', [':i' => $id]);
        if (!$s) Response::notFound('Team member not found.');
        $in = $this->request->all();
        $now = date('Y-m-d H:i:s');
        switch ((string) ($in['action'] ?? '')) {
            case 'reset_password':
                $pw = self::generatePassword();
                $this->db->update('clinic_staff', ['password_hash' => CommonHelper::hashPassword($pw), 'sessions_valid_from' => $now], 'id = :i', [':i' => $id]);
                $this->auth->revokeAllForStaff($id);
                Response::success(['password' => $pw, 'mobile' => $s['mobile']], 'New password set. Share it with the team member.');
            case 'disable':
                $this->db->update('clinic_staff', ['is_active' => 0, 'sessions_valid_from' => $now], 'id = :i', [':i' => $id]);
                $this->auth->revokeAllForStaff($id);
                Response::success([], 'Switched off and signed out.');
            case 'enable':
                $this->db->update('clinic_staff', ['is_active' => 1], 'id = :i', [':i' => $id]);
                Response::success([], 'Switched on.');
            case 'remove':
                $this->db->update('clinic_staff', ['deleted_at' => gmdate('Y-m-d H:i:s'), 'is_active' => 0, 'sessions_valid_from' => $now], 'id = :i', [':i' => $id]);
                $this->auth->revokeAllForStaff($id);
                Response::success([], 'Removed.');
            case 'rename':
                $name = self::clean((string) ($in['name'] ?? ''));
                if (mb_strlen($name) < 2 || mb_strlen($name) > 100) Response::validationError(['name' => ['Please enter a name (2-100 characters).']]);
                $this->db->update('clinic_staff', ['name' => $name], 'id = :i', [':i' => $id]);
                Response::success([], 'Renamed.');
            default:
                Response::validationError(['action' => ['Unknown action.']]);
        }
    }

    /** POST admin/clinics/{id}/staff {name, countrycode?, phone, password?} adds a receptionist to a clinic. */
    public function addStaff(array $params): void
    {
        $this->requireAdmin();
        $id  = (int) ($params['id'] ?? 0);
        if (!$this->db->getRow('SELECT id FROM doctors WHERE id = :i AND deleted_at IS NULL', [':i' => $id])) Response::notFound('Clinic not found.');
        $in   = $this->request->all();
        $name = self::clean((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2) Response::validationError(['name' => ['Please enter a name.']]);
        $country = LocaleHelper::countryFor($id);
        $mobile  = AuthController::normalizeMobile((string) ($in['countrycode'] ?? ('+' . (LocaleHelper::PHONE_RULES[$country][0] ?? '91'))), (string) ($in['phone'] ?? ''));
        if ($this->db->getRow('SELECT id FROM doctors WHERE mobile = :m', [':m' => $mobile])
            || $this->db->getRow('SELECT id FROM clinic_staff WHERE mobile = :m AND deleted_at IS NULL', [':m' => $mobile])) {
            Response::error('This mobile number is already registered.', 409, ['phone' => ['This mobile number is already registered.']]);
        }
        if ($this->count('SELECT COUNT(*) AS n FROM clinic_staff WHERE doctor_id = :d AND deleted_at IS NULL', [':d' => $id]) >= 10) {
            Response::error('A clinic can have up to 10 staff members.', 422);
        }
        $pw = trim((string) ($in['password'] ?? ''));
        if ($pw === '') {
            $pw = self::generatePassword();
        } else {
            if (strlen($pw) < 8 || strlen($pw) > 64) Response::validationError(['password' => ['Use 8 to 64 characters.']]);
            AuthController::assertPasswordStrength($pw);
        }
        $sid = $this->db->insert('clinic_staff', ['doctor_id' => $id, 'name' => $name, 'mobile' => $mobile, 'role' => 'receptionist', 'is_active' => 1, 'password_hash' => CommonHelper::hashPassword($pw)]);
        Response::created(['id' => $sid, 'mobile' => $mobile, 'password' => $pw, 'name' => $name], 'Team member added.');
    }

    // ── Shared SQL ───────────────────────────────────────────────────────────
    /** Most recent sign-in / session refresh by the doctor or any receptionist of the clinic. */
    private const LAST_ACTIVE = "GREATEST(
        COALESCE(d.last_login_at, '1970-01-01'),
        COALESCE((SELECT MAX(s.last_login_at) FROM clinic_staff s WHERE s.doctor_id = d.id AND s.deleted_at IS NULL), '1970-01-01'),
        COALESCE((SELECT t.created_at FROM auth_tokens t WHERE t.doctor_id = d.id), '1970-01-01'))";

    private function clinicRows(string $where, array $params, string $order, int $limit, int $offset = 0): array
    {
        $sql = 'SELECT d.id, d.name, d.mobile, d.email, d.plan, d.plan_started_at, d.plan_expires_at, d.is_active, d.created_at, d.admin_note,
                    ' . self::LAST_ACTIVE . ' AS last_active,
                    (SELECT c.name FROM clinics c WHERE c.doctor_id = d.id AND c.deleted_at IS NULL ORDER BY c.is_primary DESC, c.id LIMIT 1) AS clinic_name,
                    (SELECT c.country FROM clinics c WHERE c.doctor_id = d.id AND c.deleted_at IS NULL ORDER BY c.is_primary DESC, c.id LIMIT 1) AS country,
                    (SELECT COUNT(*) FROM verification v WHERE v.doctor_id = d.id) AS pending_v,
                    (SELECT COUNT(*) FROM clinic_staff s WHERE s.doctor_id = d.id AND s.deleted_at IS NULL) AS staff_count,
                    (SELECT COUNT(*) FROM patients p WHERE p.doctor_id = d.id AND p.deleted_at IS NULL) AS patients,
                    (SELECT COUNT(*) FROM prescriptions r WHERE r.doctor_id = d.id AND r.deleted_at IS NULL) AS prescriptions
                FROM doctors d WHERE d.deleted_at IS NULL AND ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        return array_map([self::class, 'shapeClinic'], $this->db->getRows($sql, $params));
    }

    private static function shapeClinic(array $r): array
    {
        $exp   = $r['plan_expires_at'];
        $left  = $exp ? (int) ceil((strtotime($exp) - time()) / 86400) : null;
        $last  = ($r['last_active'] && !str_starts_with((string) $r['last_active'], '1970')) ? $r['last_active'] : null;
        $state = (int) $r['is_active'] !== 1 ? ((int) $r['pending_v'] > 0 ? 'pending' : 'disabled') : ($exp && $left <= 0 ? 'expired' : ($r['plan'] === 'trial' ? 'trial' : 'active'));
        return [
            'id' => (int) $r['id'], 'doctor' => $r['name'], 'mobile' => $r['mobile'], 'email' => $r['email'],
            'clinic' => $r['clinic_name'] ?: null, 'country' => $r['country'] ?: null,
            'plan' => $r['plan'], 'state' => $state,
            'started_at' => $r['plan_started_at'] ?: $r['created_at'], 'expires_at' => $exp,
            'days_left' => $left === null ? null : max(0, $left),
            'joined_at' => $r['created_at'], 'last_active' => $last,
            'staff' => (int) $r['staff_count'], 'patients' => (int) $r['patients'], 'prescriptions' => (int) $r['prescriptions'],
            'note' => $r['admin_note'],
        ];
    }

    private function count(string $sql, array $p = []): int
    {
        return (int) ($this->db->getRow($sql, $p)['n'] ?? 0);
    }

    // ── GET admin/dashboard ──────────────────────────────────────────────────
    public function dashboard(): void
    {
        $this->requireAdmin();
        $live = 'd.deleted_at IS NULL AND d.is_active = 1';
        $now  = date('Y-m-d H:i:s');
        $c = fn (string $w, array $p = []) => $this->count("SELECT COUNT(*) AS n FROM doctors d WHERE $live AND $w", $p);
        $act = fn (int $days) => $this->count('SELECT COUNT(*) AS n FROM doctors d WHERE ' . $live . ' AND ' . self::LAST_ACTIVE . ' >= :t', [':t' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);

        $series = [];
        foreach ($this->db->getRows('SELECT DATE(created_at) AS day, COUNT(*) AS n FROM doctors WHERE deleted_at IS NULL AND created_at >= :t GROUP BY DATE(created_at)', [':t' => date('Y-m-d', strtotime('-29 days'))]) as $r) {
            $series[$r['day']] = (int) $r['n'];
        }
        $signups = [];
        for ($i = 29; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $signups[] = ['day' => $d, 'n' => $series[$d] ?? 0]; }

        $rx = [];
        foreach ($this->db->getRows('SELECT DATE(created_at) AS day, COUNT(*) AS n FROM prescriptions WHERE deleted_at IS NULL AND created_at >= :t GROUP BY DATE(created_at)', [':t' => date('Y-m-d', strtotime('-13 days'))]) as $r) {
            $rx[$r['day']] = (int) $r['n'];
        }
        $rxSeries = [];
        for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $rxSeries[] = ['day' => $d, 'n' => $rx[$d] ?? 0]; }

        Response::success([
            'clinics' => [
                'total'      => $c('1=1'),
                'on_trial'   => $c("d.plan = 'trial' AND (d.plan_expires_at IS NULL OR d.plan_expires_at > :n)", [':n' => $now]),
                'paid'       => $c("d.plan <> 'trial' AND (d.plan_expires_at IS NULL OR d.plan_expires_at > :n)", [':n' => $now]),
                'expired'    => $c('d.plan_expires_at IS NOT NULL AND d.plan_expires_at <= :n', [':n' => $now]),
                'expiring_7' => $c('d.plan_expires_at > :n AND d.plan_expires_at <= :e', [':n' => $now, ':e' => date('Y-m-d H:i:s', strtotime('+7 days'))]),
                'disabled'   => $this->count('SELECT COUNT(*) AS n FROM doctors d WHERE d.deleted_at IS NULL AND d.is_active = 0 AND NOT EXISTS (SELECT 1 FROM verification v WHERE v.doctor_id = d.id)'),
                'new_7'      => $c('d.created_at >= :t', [':t' => date('Y-m-d H:i:s', strtotime('-7 days'))]),
                'new_30'     => $c('d.created_at >= :t', [':t' => date('Y-m-d H:i:s', strtotime('-30 days'))]),
            ],
            'activity' => ['active_24h' => $act(1), 'active_7d' => $act(7), 'active_30d' => $act(30)],
            'totals'   => [
                'staff'         => $this->count('SELECT COUNT(*) AS n FROM clinic_staff WHERE deleted_at IS NULL'),
                'patients'      => $this->count('SELECT COUNT(*) AS n FROM patients WHERE deleted_at IS NULL'),
                'prescriptions' => $this->count('SELECT COUNT(*) AS n FROM prescriptions WHERE deleted_at IS NULL'),
                'rx_7d'         => $this->count('SELECT COUNT(*) AS n FROM prescriptions WHERE deleted_at IS NULL AND created_at >= :t', [':t' => date('Y-m-d H:i:s', strtotime('-7 days'))]),
                'appointments'  => $this->count('SELECT COUNT(*) AS n FROM appointments'),
                'bills'         => $this->count('SELECT COUNT(*) AS n FROM bills'),
            ],
            'signups'       => $signups,
            'rx_series'     => $rxSeries,
            'expiring_soon' => $this->clinicRows($live . ' AND d.plan_expires_at > NOW() AND d.plan_expires_at <= NOW() + INTERVAL 14 DAY', [], 'd.plan_expires_at ASC', 8),
            'recent'        => $this->clinicRows($live, [], 'd.created_at DESC', 6),
            'gone_quiet'    => $this->clinicRows($live . ' AND (d.plan_expires_at IS NULL OR d.plan_expires_at > NOW()) AND ' . self::LAST_ACTIVE . ' < NOW() - INTERVAL 7 DAY', [], self::LAST_ACTIVE . ' ASC', 8),
        ]);
    }

    // ── GET admin/clinics ────────────────────────────────────────────────────
    public function clinics(): void
    {
        $this->requireAdmin();
        $q      = trim((string) $this->request->get('q', ''));
        $status = (string) $this->request->get('status', 'all');
        $page   = max(1, (int) $this->request->get('page', 1));
        $where  = '1=1';
        $p      = [];
        if ($q !== '') {
            $where .= ' AND (d.name LIKE :q1 OR d.mobile LIKE :q2 OR d.email LIKE :q3 OR EXISTS (SELECT 1 FROM clinics c WHERE c.doctor_id = d.id AND c.deleted_at IS NULL AND c.name LIKE :q4))';
            $p = [':q1' => '%' . $q . '%', ':q2' => '%' . $q . '%', ':q3' => '%' . $q . '%', ':q4' => '%' . $q . '%'];
        }
        $where .= match ($status) {
            'trial'    => " AND d.is_active = 1 AND d.plan = 'trial' AND (d.plan_expires_at IS NULL OR d.plan_expires_at > NOW())",
            'paid'     => " AND d.is_active = 1 AND d.plan <> 'trial' AND (d.plan_expires_at IS NULL OR d.plan_expires_at > NOW())",
            'expired'  => ' AND d.plan_expires_at IS NOT NULL AND d.plan_expires_at <= NOW()',
            'expiring' => ' AND d.plan_expires_at > NOW() AND d.plan_expires_at <= NOW() + INTERVAL 14 DAY',
            'quiet'    => ' AND d.is_active = 1 AND ' . self::LAST_ACTIVE . ' < NOW() - INTERVAL 7 DAY',
            'disabled' => ' AND d.is_active = 0 AND NOT EXISTS (SELECT 1 FROM verification v WHERE v.doctor_id = d.id)',
            default    => '',
        };
        $order = match ((string) $this->request->get('sort', 'new')) {
            'active'  => self::LAST_ACTIVE . ' DESC',
            'expiry'  => 'd.plan_expires_at IS NULL, d.plan_expires_at ASC',
            'name'    => 'd.name ASC',
            default   => 'd.created_at DESC',
        };
        $total = $this->count('SELECT COUNT(*) AS n FROM doctors d WHERE d.deleted_at IS NULL AND ' . $where, $p);
        Response::success([
            'items' => $this->clinicRows($where, $p, $order, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'page' => $page, 'per_page' => self::PAGE_SIZE, 'total' => $total,
        ]);
    }

    // ── GET admin/clinics/{id} ───────────────────────────────────────────────
    public function clinic(array $params): void
    {
        $this->requireAdmin();
        $id  = (int) ($params['id'] ?? 0);
        $row = $this->clinicRows('d.id = :id', [':id' => $id], 'd.id', 1);
        if (!$row) Response::notFound('Clinic not found.');
        $c = $row[0];

        $staff = array_map(fn ($s) => [
            'id' => (int) $s['id'], 'name' => $s['name'], 'mobile' => $s['mobile'], 'role' => $s['role'],
            'active' => (int) $s['is_active'] === 1, 'last_login_at' => $s['last_login_at'], 'created_at' => $s['created_at'],
        ], $this->db->getRows('SELECT * FROM clinic_staff WHERE doctor_id = :d AND deleted_at IS NULL ORDER BY name', [':d' => $id]));

        $loc = $this->db->getRow('SELECT language, currency, country, city, state FROM clinics WHERE doctor_id = :d AND deleted_at IS NULL ORDER BY is_primary DESC, id LIMIT 1', [':d' => $id]) ?: [];
        $doc = $this->db->getRow('SELECT degree, specialization, registration_no, last_login_at FROM doctors WHERE id = :d', [':d' => $id]);
        $n   = fn (string $sql) => $this->count($sql, [':d' => $id]);
        $since = date('Y-m-d H:i:s', strtotime('-30 days'));
        Response::success([
            'clinic' => $c,
            'profile' => $loc + ['degree' => $doc['degree'], 'specialization' => $doc['specialization'], 'registration_no' => $doc['registration_no'], 'doctor_last_login' => $doc['last_login_at']],
            'staff' => $staff,
            'counts' => [
                'patients'      => $c['patients'],
                'prescriptions' => $c['prescriptions'],
                'rx_30d'        => $this->count('SELECT COUNT(*) AS n FROM prescriptions WHERE doctor_id = :d AND deleted_at IS NULL AND created_at >= :t', [':d' => $id, ':t' => $since]),
                'appointments'  => $n('SELECT COUNT(*) AS n FROM appointments WHERE doctor_id = :d'),
                'bills'         => $n('SELECT COUNT(*) AS n FROM bills WHERE doctor_id = :d'),
                'lab_orders'    => $n('SELECT COUNT(*) AS n FROM lab_orders WHERE doctor_id = :d'),
            ],
        ]);
    }

    // ── POST admin/clinics ───────────────────────────────────────────────────
    public function create(): void
    {
        $this->requireAdmin();
        $in = $this->request->all();
        $name = self::clean((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2) Response::validationError(['name' => ['Please enter the doctor\'s name.']]);

        $country = strtoupper(trim((string) ($in['country'] ?? 'IN')));
        if (!isset(LocaleHelper::COUNTRY_DEFAULTS[$country])) Response::validationError(['country' => ['Choose a supported country.']]);
        [$currency, $language] = LocaleHelper::COUNTRY_DEFAULTS[$country];
        $dial   = '+' . (LocaleHelper::PHONE_RULES[$country][0] ?? '91');
        $mobile = AuthController::normalizeMobile((string) ($in['countrycode'] ?? $dial), (string) ($in['phone'] ?? ''));

        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) Response::validationError(['email' => ['Enter a valid email or leave it empty.']]);

        if ($this->db->getRow('SELECT id FROM doctors WHERE mobile = :m', [':m' => $mobile])
            || $this->db->getRow('SELECT id FROM clinic_staff WHERE mobile = :m AND deleted_at IS NULL', [':m' => $mobile])) {
            Response::error('This mobile number is already registered.', 409, ['phone' => ['This mobile number is already registered.']]);
        }
        if ($email !== '' && $this->db->getRow('SELECT id FROM doctors WHERE email = :e', [':e' => $email])) {
            Response::error('This email is already registered.', 409, ['email' => ['This email is already registered.']]);
        }

        $pw = trim((string) ($in['password'] ?? ''));
        if ($pw === '') {
            $pw = self::generatePassword();
        } else {
            if (strlen($pw) < 8 || strlen($pw) > 64) Response::validationError(['password' => ['Use 8 to 64 characters.']]);
            AuthController::assertPasswordStrength($pw);
        }
        $days = (int) ($in['trial_days'] ?? AuthController::TRIAL_DAYS);
        if ($days < 1 || $days > 730) Response::validationError(['trial_days' => ['Choose between 1 and 730 days.']]);

        $clinicName = self::clean((string) ($in['clinic_name'] ?? ''));
        if ($clinicName === '') $clinicName = 'Dr. ' . preg_replace('/^dr\.?\s+/i', '', $name) . "'s Clinic";

        $this->db->beginTransaction();
        try {
            $id = $this->db->insert('doctors', [
                'name' => $name, 'mobile' => $mobile, 'email' => $email !== '' ? $email : null,
                'password_hash' => CommonHelper::hashPassword($pw),
                'plan' => 'trial', 'plan_started_at' => date('Y-m-d H:i:s'),
                'plan_expires_at' => date('Y-m-d H:i:s', strtotime("+$days days")),
                'is_active' => 1,
                'admin_note' => ($n = self::clean((string) ($in['note'] ?? ''))) !== '' ? mb_substr($n, 0, 500) : null,
            ]);
            $this->db->insert('clinics', [
                'doctor_id' => $id, 'name' => mb_substr($clinicName, 0, 200), 'is_primary' => 1,
                'country' => $country, 'currency' => $currency, 'language' => $language,
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->logger->info('Admin created clinic', ['doctor_id' => $id]);
        Response::created(['id' => $id, 'mobile' => $mobile, 'password' => $pw, 'trial_days' => $days, 'clinic' => $clinicName], 'Clinic created.');
    }

    // ── POST admin/clinics/{id}/action ───────────────────────────────────────
    /** {action: extend|set_plan|disable|enable|reset_password|reset_staff_password|note|remove_staff} */
    public function action(array $params): void
    {
        $this->requireAdmin();
        $id  = (int) ($params['id'] ?? 0);
        $doc = $this->db->getRow('SELECT * FROM doctors WHERE id = :i AND deleted_at IS NULL', [':i' => $id]);
        if (!$doc) Response::notFound('Clinic not found.');
        $in  = $this->request->all();
        $act = (string) ($in['action'] ?? '');

        switch ($act) {
            case 'extend':
                $days = (int) ($in['days'] ?? 0);
                if ($days < -365 || $days > 730 || $days === 0) Response::validationError(['days' => ['Choose a number of days between -365 and 730.']]);
                // Extend from today if the plan already ended, otherwise from its current end date.
                $base = ($doc['plan_expires_at'] && strtotime($doc['plan_expires_at']) > time()) ? strtotime($doc['plan_expires_at']) : time();
                $this->db->update('doctors', ['plan_expires_at' => date('Y-m-d H:i:s', strtotime(($days > 0 ? '+' : '') . $days . ' days', $base))], 'id = :i', [':i' => $id]);
                Response::success([], $days > 0 ? "Added $days days." : 'Shortened the plan.');
            case 'set_plan':
                $plan = (string) ($in['plan'] ?? '');
                if (!in_array($plan, self::PLANS, true)) Response::validationError(['plan' => ['Choose a valid plan.']]);
                $exp = trim((string) ($in['expires_at'] ?? ''));
                $ts  = $exp === '' ? null : strtotime($exp . ' 23:59:59');
                if ($exp !== '' && !$ts) Response::validationError(['expires_at' => ['Enter a valid date.']]);
                $data = ['plan' => $plan, 'plan_expires_at' => $ts ? date('Y-m-d H:i:s', $ts) : null];
                if ($plan !== $doc['plan']) $data['plan_started_at'] = date('Y-m-d H:i:s');
                $this->db->update('doctors', $data, 'id = :i', [':i' => $id]);
                Response::success([], 'Plan updated.');
            case 'disable':
                $this->db->update('doctors', ['is_active' => 0], 'id = :i', [':i' => $id]);
                $this->auth->revokeAllForDoctor($id);
                Response::success([], 'Clinic switched off. Its doctor and reception are signed out.');
            case 'enable':
                $this->db->update('doctors', ['is_active' => 1], 'id = :i', [':i' => $id]);
                Response::success([], 'Clinic switched on.');
            case 'reset_password':
                $pw = self::generatePassword();
                $this->db->update('doctors', ['password_hash' => CommonHelper::hashPassword($pw)], 'id = :i', [':i' => $id]);
                $this->auth->revokeAllForDoctor($id);
                Response::success(['password' => $pw, 'mobile' => $doc['mobile']], 'New password set. Share it with the doctor.');
            case 'reset_staff_password':
                $s = $this->db->getRow('SELECT * FROM clinic_staff WHERE id = :s AND doctor_id = :d AND deleted_at IS NULL', [':s' => (int) ($in['staff_id'] ?? 0), ':d' => $id]);
                if (!$s) Response::notFound('Team member not found.');
                $pw = self::generatePassword();
                $this->db->update('clinic_staff', ['password_hash' => CommonHelper::hashPassword($pw), 'sessions_valid_from' => date('Y-m-d H:i:s')], 'id = :i', [':i' => $s['id']]);
                $this->auth->revokeAllForStaff((int) $s['id']);
                Response::success(['password' => $pw, 'mobile' => $s['mobile']], 'New password set. Share it with the team member.');
            case 'note':
                $n = self::clean((string) ($in['note'] ?? ''));
                $this->db->update('doctors', ['admin_note' => $n !== '' ? mb_substr($n, 0, 500) : null], 'id = :i', [':i' => $id]);
                Response::success([], 'Note saved.');
            default:
                Response::validationError(['action' => ['Unknown action.']]);
        }
    }

    /** 12 characters, always at least one letter and one digit, no look-alike characters. */
    private static function generatePassword(): string
    {
        $L = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $D = '23456789';
        $pick = fn (string $s) => $s[random_int(0, strlen($s) - 1)];
        $chars = [$pick($L), $pick($D)];
        while (count($chars) < 12) $chars[] = $pick($L . $D);
        for ($i = count($chars) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]]; }
        return implode('', $chars);
    }
}
