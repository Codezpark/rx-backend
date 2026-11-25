<?php

declare(strict_types=1);

/**
 * BaseController — All controllers extend this class.
 *
 * Provides:
 *   $this->request    — Request instance
 *   $this->db         — Database instance
 *   $this->auth       — Auth instance
 *   $this->authUser   — Decoded JWT payload (null if route has no auth)
 *   $this->validate() — Shorthand validation that auto-returns 422 on failure
 *   $this->input()    — Validated & sanitized input
 */
abstract class BaseController
{
    protected Request    $request;
    protected Database   $db;
    protected Auth       $auth;
    protected Logger     $logger;
    protected ?array     $authUser;

    public function __construct(Request $request, ?array $authUser = null)
    {
        $this->request  = $request;
        $this->db       = Database::getInstance();
        $this->auth     = Auth::getInstance();
        $this->logger   = Logger::getInstance();
        $this->authUser = $authUser;
    }

    // ── Validation shorthand ─────────────────────────────────────────────────
    /**
     * Validate input against rules. Automatically sends 422 on failure.
     *
     * @param  array  $rules    ['field' => 'required|email|min:3']
     * @param  array|null $data Override data source (defaults to all request input)
     * @return array  Validated data
     */
    protected function validate(array $rules, ?array $data = null): array
    {
        $data      = $data ?? $this->request->all();
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        return $data;
    }

    // ── Input helpers ────────────────────────────────────────────────────────
    /** Get sanitized input value */
    protected function input(string $key, mixed $default = null): mixed
    {
        return $this->request->input($key, $default);
    }

    /** Get all sanitized input */
    protected function allInput(): array
    {
        return CommonHelper::sanitizeArray($this->request->all());
    }

    /** Require authenticated user or abort 401 */
    protected function requireAuth(): array
    {
        if (!$this->authUser) {
            Response::unauthorized('Authentication required.');
        }
        return $this->authUser;
    }

    /*
     * Clinics with several doctors.
     *
     * The clinic owner's doctor id is the TENANT KEY: it is what `doctor_id` holds on patients, visits,
     * bills and everything else. Other doctors of the clinic point at the owner (`clinic_owner_id`), and
     * records carry `consultant_id`, the doctor who treated.
     *
     *   requireSelf()   the signed-in doctor's own account (profile, password, signature)
     *   requireDoctor() a doctor working in their clinic: returns the OWNER row (->['id'] = tenant)
     *   requireOwner()  clinic management: staff, settings, fees
     *   requireClinic() the same as requireDoctor(), but receptionists may use it too
     */

    /** The owner's id for this request, set by requireDoctor() / requireClinic(). */
    protected int $tenantId = 0;
    /** The signed-in doctor's id; null for a receptionist. */
    protected ?int $actorId = null;
    protected bool $isOwner = false;

    /** The signed-in, still-active doctor's own row. */
    protected function requireSelf(): array
    {
        $auth = $this->requireAuth();
        if (($auth['role'] ?? 'doctor') !== 'doctor') {
            Response::forbidden('This is only available to the doctor.');
        }
        $doctor = $this->db->getRow(
            'SELECT * FROM doctors WHERE id = :id AND deleted_at IS NULL AND is_active = 1',
            [':id' => (int) ($auth['doctor_id'] ?? 0)]
        );
        if (!$doctor) {
            Response::unauthorized('Your session is no longer valid. Please sign in again.');
        }
        // A second doctor is locked out with the clinic: the owner must still be active.
        if (!empty($doctor['clinic_owner_id']) && !$this->db->getRow(
            'SELECT id FROM doctors WHERE id = :id AND deleted_at IS NULL AND is_active = 1', [':id' => (int) $doctor['clinic_owner_id']])) {
            Response::unauthorized('Your session is no longer valid. Please sign in again.');
        }
        // A removed doctor or a reset password ends older sessions at once.
        if (!empty($doctor['sessions_valid_from'])
            && (int) ($auth['iat'] ?? 0) < strtotime((string) $doctor['sessions_valid_from'])) {
            Response::unauthorized('Your session was ended. Please sign in again.');
        }
        return $doctor;
    }

    /**
     * A doctor acting inside their clinic. Returns the clinic OWNER's row (so `id` is the tenant key);
     * `actor_id` is the doctor who is signed in and `is_owner` says whether they own the clinic.
     */
    protected function requireDoctor(): array
    {
        $self  = $this->requireSelf();
        $owner = $self;
        if (!empty($self['clinic_owner_id'])) {
            $owner = $this->db->getRow(
                'SELECT * FROM doctors WHERE id = :id AND deleted_at IS NULL AND is_active = 1',
                [':id' => (int) $self['clinic_owner_id']]
            );
            if (!$owner) {
                Response::unauthorized('Your session is no longer valid. Please sign in again.');
            }
        }
        $this->tenantId = (int) $owner['id'];
        $this->actorId  = (int) $self['id'];
        $this->isOwner  = empty($self['clinic_owner_id']);
        return ['actor_id' => $this->actorId, 'is_owner' => $this->isOwner] + $owner;
    }

    /** Clinic management is for the doctor who owns the clinic. */
    protected function requireOwner(): array
    {
        $clinic = $this->requireDoctor();
        if (!$this->isOwner) {
            Response::forbidden('Only the clinic owner can do this.');
        }
        return $clinic;
    }

    /** The signed-in receptionist's row, set by requireClinic(); null for a doctor. */
    protected ?array $staff = null;

    protected function isStaff(): bool
    {
        return ($this->authUser['role'] ?? 'doctor') !== 'doctor';
    }

    /**
     * For endpoints a receptionist may use too: returns the clinic owner's row, so every query stays
     * scoped to that clinic. A receptionist is re-checked against the database on every call, so
     * switching them off (or deleting them) takes effect immediately.
     */
    protected function requireClinic(): array
    {
        $auth = $this->requireAuth();
        if (!$this->isStaff()) {
            return $this->requireDoctor();
        }
        $staff = $this->db->getRow(
            'SELECT s.* FROM clinic_staff s JOIN doctors d ON d.id = s.doctor_id AND d.deleted_at IS NULL AND d.is_active = 1
             WHERE s.id = :s AND s.doctor_id = :d AND s.deleted_at IS NULL AND s.is_active = 1',
            [':s' => (int) ($auth['staff_id'] ?? 0), ':d' => (int) ($auth['doctor_id'] ?? 0)]
        );
        if (!$staff) {
            Response::unauthorized('Your session is no longer valid. Please sign in again.');
        }
        // Force-logout: a password reset / disable bumps sessions_valid_from, so an
        // access token issued (iat) before that instant is rejected even if unexpired.
        if (!empty($staff['sessions_valid_from'])
            && (int) ($auth['iat'] ?? 0) < strtotime((string) $staff['sessions_valid_from'])) {
            Response::unauthorized('Your session was ended by the clinic. Please sign in again.');
        }
        $this->staff    = $staff;
        $this->tenantId = (int) $staff['doctor_id'];
        $this->actorId  = null;
        $this->isOwner  = false;
        return $this->db->getRow('SELECT * FROM doctors WHERE id = :id', [':id' => (int) $staff['doctor_id']]);
    }

    /**
     * A doctor who does not own the clinic works with their own queue, bills and numbers.
     * Returns that doctor's id, or null when the whole clinic is visible (owner, receptionist).
     */
    protected function ownScope(): ?int
    {
        return $this->actorId !== null && !$this->isOwner ? $this->actorId : null;
    }

    /**
     * Whose work a report shows: a second doctor only their own; the owner the whole clinic, or one
     * doctor when ?consultant_id= names one.
     */
    protected function viewScope(): ?int
    {
        if (($own = $this->ownScope()) !== null) return $own;
        $id = (int) $this->request->get('consultant_id', 0);
        return $this->clinicDoctor($this->tenantId, $id) ? $id : null;
    }

    /** " AND x.consultant_id = 7" for a scoped view, or ''. The id is an int, so it is safe inline. */
    protected static function byDoctor(string $column, ?int $doctorId): string
    {
        return $doctorId === null ? '' : ' AND ' . $column . ' = ' . $doctorId;
    }

    /** Clinical records are read across the clinic but changed only by the doctor who wrote them. */
    protected function assertAuthor(array $row): void
    {
        if ($this->actorId !== null && (int) ($row['consultant_id'] ?? $row['doctor_id']) !== $this->actorId) {
            Response::forbidden('Only the doctor who wrote this can change it.');
        }
    }

    /** An active doctor of this clinic, or null. */
    protected function clinicDoctor(int $tenant, int $id): ?array
    {
        return $id > 0 ? $this->db->getRow(
            'SELECT * FROM doctors WHERE id = :i AND (id = :t1 OR clinic_owner_id = :t2) AND deleted_at IS NULL AND is_active = 1',
            [':i' => $id, ':t1' => $tenant, ':t2' => $tenant]
        ) : null;
    }

    /** Trim, strip tags and collapse whitespace. Output encoding is the client's job. */
    protected static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($value)));
    }

    /** Doctor row safe to send to the app (no password hash) plus derived fields. */
    protected static function publicDoctor(array $doctor): array
    {
        // Plan and trial belong to the clinic: a second doctor sees the owner's.
        $db    = Database::getInstance();
        $owner = empty($doctor['clinic_owner_id']) ? $doctor
            : ($db->getRow('SELECT * FROM doctors WHERE id = :i', [':i' => $doctor['clinic_owner_id']]) ?: $doctor);
        $tenant = (int) $owner['id'];
        $count  = (int) ($db->getRow(
            'SELECT COUNT(*) AS n FROM doctors WHERE (id = :a OR clinic_owner_id = :b) AND deleted_at IS NULL AND is_active = 1',
            [':a' => $tenant, ':b' => $tenant])['n'] ?? 1);
        $fields   = ['degree', 'specialization', 'registration_no', 'experience_yrs'];
        $filled   = count(array_filter($fields, fn ($f) => $doctor[$f] !== null && $doctor[$f] !== ''));
        $expires  = $owner['plan_expires_at'];

        return [
            'id'               => (int) $doctor['id'],
            'name'             => $doctor['name'],
            'email'            => $doctor['email'],
            'mobile'           => $doctor['mobile'],
            'degree'           => $doctor['degree'],
            'specialization'   => $doctor['specialization'],
            'registration_no'  => $doctor['registration_no'],
            'experience_yrs'   => $doctor['experience_yrs'] === null ? null : (int) $doctor['experience_yrs'],
            'has_signature'    => !empty($doctor['signature_url'] ?? null),
            'is_owner'         => empty($doctor['clinic_owner_id']),
            'clinic_id'        => $tenant,
            'clinic_doctors'   => max(1, $count),
            'plan'             => $owner['plan'],
            'plan_expires_at'  => $expires,
            'plan_started_at'  => $owner['plan_started_at'] ?? null,
            'plan_days_left'   => $expires ? (int) max(0, ceil((strtotime($expires) - time()) / 86400)) : null,
            'profile_percent'  => (int) round((1 + $filled) / (1 + count($fields)) * 100), // name is always present
            'created_at'       => $doctor['created_at'],
        ];
    }

    /** Require specific role */
    protected function requireRole(string ...$roles): array
    {
        $user = $this->requireAuth();
        if (!in_array($user['role'] ?? '', $roles, true)) {
            Response::forbidden('You do not have permission to perform this action.');
        }
        return $user;
    }

    // ── Pagination helpers ───────────────────────────────────────────────────
    protected function getPage(): int
    {
        return max(1, (int) $this->request->get('page', 1));
    }

    protected function getLimit(int $default = 10, int $max = 100): int
    {
        return min($max, max(1, (int) $this->request->get('limit', $default)));
    }
}
