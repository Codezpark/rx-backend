<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';
require_once ROOT_PATH . '/app/controllers/PatientController.php';

/**
 * AppointmentController — the day's queue and the booking diary.
 *
 * GET  /appointments?from=&to=&patient_id=&upcoming=1   → list()
 * POST /appointments                                    → create()   walk_in=true joins the queue now
 * PUT  /appointments/{id}                               → update()   reschedule / edit (not yet seen)
 * POST /appointments/{id}/status                        → setStatus()
 *
 * A walk-in is just an appointment that has already arrived, so one list
 * serves "who is waiting", "who is coming" and "who was seen". Finalising a
 * prescription linked to an appointment completes it (see PrescriptionController).
 */
class AppointmentController extends BaseController
{
    private const ACTIVE = ['scheduled', 'confirmed', 'arrived', 'not_available'];

    /** from-status => allowed next statuses */
    private const FLOW = [
        'scheduled'     => ['confirmed', 'arrived', 'cancelled', 'no_show'],
        'confirmed'     => ['arrived', 'cancelled', 'no_show'],
        'arrived'       => ['completed', 'cancelled', 'not_available'],
        'not_available' => ['arrived', 'cancelled'],   // stepped out / came back
        'no_show'       => ['arrived'],                // turned up late
        'completed'     => [],
        'cancelled'     => [],
    ];

    /** Receptionist-captured intake vitals: column => [min, max, integer?]. */
    private const INTAKE_VITALS = [
        'weight_kg' => [0.3, 500, false], 'height_cm' => [20, 250, false],
        'bp_systolic' => [40, 300, true], 'bp_diastolic' => [20, 200, true],
        'pulse_bpm' => [20, 250, true], 'temperature_f' => [90, 110, false],
        'spo2_pct' => [50, 100, true], 'rbs_fasting_mg_dl' => [10, 1500, true], 'rbs_pp_mg_dl' => [10, 1500, true],
    ];

    // ── GET /appointments ────────────────────────────────────────────────────
    public function list(): void
    {
        $doctor = $this->requireClinic();
        $from = $this->utc($this->request->get('from'));
        $to   = $this->utc($this->request->get('to'));
        $this->autoCloseStale((int) $doctor['id'], $from, $to);
        $sql    = $this->baseSelect() . ' WHERE a.doctor_id = :d';
        $params = [':d' => $doctor['id']];
        // ?consultant_id=7 one doctor's queue, 'all' the whole clinic. Default: a doctor sees their own,
        // reception sees everyone's. A patient's own history is always the whole clinic's.
        $asked = (string) $this->request->get('consultant_id', '');
        $who   = $asked === 'all' ? null : ((int) $asked > 0 ? (int) $asked : $this->actorId);
        if ($asked === '' && (int) $this->request->get('patient_id', 0) > 0) $who = null;
        $sql  .= self::byDoctor('a.consultant_id', $who);

        if ($from) { $sql .= ' AND a.scheduled_at >= :from'; $params[':from'] = $from; }
        if ($to)   { $sql .= ' AND a.scheduled_at < :to';    $params[':to']   = $to; }

        $pid = (int) $this->request->get('patient_id', 0);
        if ($pid > 0) { $sql .= ' AND a.patient_id = :pid'; $params[':pid'] = $pid; }

        if ($this->request->get('upcoming')) {
            $sql .= " AND a.status IN ('scheduled','confirmed') AND a.scheduled_at >= :now";
            $params[':now'] = gmdate('Y-m-d H:i:s');
        }
        $sql .= ' ORDER BY a.scheduled_at ASC, a.id ASC LIMIT 300';

        $rows  = $this->db->getRows($sql, $params);
        $items = $this->attachBills(array_map([self::class, 'shape'], $rows), (int) $doctor['id']);
        Response::success(['items' => $items], 'Appointments fetched');
    }

    /**
     * Lazy end-of-day cleanup (Module 4.4, no cron): any still-active appointment
     * from a previous day is auto-cancelled on the next queue load.
     */
    private function autoCloseStale(int $doctorId, ?string $from = null, ?string $to = null): void
    {
        // The clinic's "today" is what the app asks for (its local midnight to midnight); without that,
        // fall back to the UTC day.
        $cut = gmdate('Y-m-d 00:00:00');
        if ($from !== null && $to !== null) {
            $f = strtotime($from . ' UTC');
            $t = strtotime($to . ' UTC');
            if ($f <= time() && time() < $t && $t - $f <= 26 * 3600) $cut = $from;
        }
        $this->db->update('appointments',
            ['status' => 'cancelled', 'cancelled_by' => 'system', 'cancel_reason' => 'Auto-closed at day end'],
            "doctor_id = :d AND scheduled_at < :today AND status IN ('scheduled','confirmed','arrived','not_available')",
            [':d' => $doctorId, ':today' => $cut]
        );
    }

    /** Attach the most-recent open bill (if any) to each appointment, for the "Collect ₹X" prompt. */
    private function attachBills(array $items, int $doctorId): array
    {
        $ids = array_values(array_filter(array_map(fn ($i) => $i['id'], $items)));
        if (!$ids) return $items;
        $ph = []; $params = [':d' => $doctorId];
        foreach ($ids as $k => $v) { $ph[] = ":a$k"; $params[":a$k"] = $v; }
        $rows = $this->db->getRows(
            "SELECT appointment_id, id, bill_no, total, paid, status FROM bills
              WHERE doctor_id = :d AND status <> 'cancelled' AND appointment_id IN (" . implode(',', $ph) . ')',
            $params
        );
        $map = [];
        foreach ($rows as $b) {
            $apId = (int) $b['appointment_id'];
            if (!isset($map[$apId]) || (int) $b['id'] > $map[$apId]['id']) {
                $map[$apId] = [
                    'id' => (int) $b['id'], 'bill_no' => $b['bill_no'], 'total' => (float) $b['total'], 'paid' => (float) $b['paid'],
                    'due' => round((float) $b['total'] - (float) $b['paid'], 2), 'status' => $b['status'],
                ];
            }
        }
        foreach ($items as &$it) { $it['bill'] = $map[$it['id']] ?? null; }
        return $items;
    }

    // ── POST /appointments ───────────────────────────────────────────────────
    public function create(): void
    {
        $doctor  = $this->requireClinic();
        $patient = $this->ownPatient((int) $this->request->get('patient_id'), (int) $doctor['id']);
        $walkIn  = filter_var($this->request->get('walk_in'), FILTER_VALIDATE_BOOLEAN);
        $in      = $this->parse($walkIn);

        $when = $walkIn ? time() : $in['ts'];
        $when = $walkIn ? gmdate('Y-m-d H:i:s', $when) : gmdate('Y-m-d H:i:s', $when);

        // Which doctor the patient is going to.
        $consultant = $this->consultantFor((int) $doctor['id'], $this->request->get('consultant_id'));

        // The same person twice in one day (for the same doctor) is almost always a slip.
        if (!filter_var($this->request->get('force'), FILTER_VALIDATE_BOOLEAN)) {
            $dup = $this->db->getRow(
                $this->baseSelect() . " WHERE a.doctor_id = :d AND a.patient_id = :p AND a.status IN ('scheduled','confirmed','arrived','not_available')" . self::byDoctor('a.consultant_id', $consultant) . "
                   AND ABS(TIMESTAMPDIFF(HOUR, a.scheduled_at, :w)) < 12 ORDER BY a.scheduled_at LIMIT 1",
                [':d' => $doctor['id'], ':p' => $patient['id'], ':w' => $when]
            );
            if ($dup) {
                $msg = in_array($dup['status'], ['arrived', 'not_available'], true)
                    ? Lang::t('{name} is already waiting in the queue.', ['name' => $patient['name']])
                    : Lang::t('{name} already has an appointment around that time.', ['name' => $patient['name']]);
                Response::send(409, ['existing' => self::shape($dup)], $msg, false);
            }
        }

        $caseType = $this->request->get('case_type');
        $caseType = in_array($caseType, ['new', 'old', 'emergency'], true) ? $caseType : null;

        $clinic = $this->db->getRow('SELECT id FROM clinics WHERE doctor_id = :d AND deleted_at IS NULL ORDER BY is_primary DESC, id LIMIT 1', [':d' => $doctor['id']]);
        $id = $this->db->insert('appointments', [
            'doctor_id'    => $doctor['id'],
            'consultant_id' => $consultant,
            'clinic_id'    => $clinic['id'] ?? null,
            'patient_id'   => $patient['id'],
            'scheduled_at' => $when,
            'duration_min' => $in['duration'],
            'reason'       => $in['reason'],
            'notes'        => $in['notes'],
            'status'       => $walkIn ? 'arrived' : 'scheduled',
            'arrived_at'   => $walkIn ? gmdate('Y-m-d H:i:s') : null,
            'case_type'    => $caseType,
        ]);
        $this->db->update('patients', ['updated_at' => CommonHelper::now()], 'id = :id', [':id' => $patient['id']]);

        Response::created(['appointment' => $this->load($id, (int) $doctor['id'])], $walkIn ? 'Added to the queue' : 'Appointment booked');
    }

    // ── PUT /appointments/{id} ───────────────────────────────────────────────
    public function update(array $params): void
    {
        $doctor = $this->requireClinic();
        $id     = (int) $params['id'];
        $appt   = $this->rowOrFail($id, (int) $doctor['id']);
        if (!in_array($appt['status'], ['scheduled', 'confirmed'], true)) {
            Response::error('Only upcoming appointments can be changed.', 409);
        }
        $in = $this->parse(false);

        $asked = $this->request->get('consultant_id');
        $move  = $asked === null || $asked === '' ? [] : ['consultant_id' => $this->consultantFor((int) $doctor['id'], $asked)];

        $this->db->update('appointments', $move + [
            'scheduled_at' => gmdate('Y-m-d H:i:s', $in['ts']),
            'duration_min' => $in['duration'],
            'reason'       => $in['reason'],
            'notes'        => $in['notes'],
            'status'       => 'scheduled',            // a moved appointment needs confirming again
            'reminder_24h_sent' => 0, 'reminder_1h_sent' => 0, 'reminder_24h_at' => null, 'reminder_1h_at' => null,
        ], 'id = :id', [':id' => $id]);

        Response::success(['appointment' => $this->load($id, (int) $doctor['id'])], 'Appointment updated');
    }

    // ── POST /appointments/{id}/status ───────────────────────────────────────
    public function setStatus(array $params): void
    {
        $doctor = $this->requireClinic();
        $id     = (int) $params['id'];
        $appt   = $this->rowOrFail($id, (int) $doctor['id']);

        $this->validate(['status' => 'required|in:scheduled,confirmed,arrived,not_available,completed,cancelled,no_show']);
        if ($this->isStaff() && $this->request->input('status') === 'completed') {
            Response::forbidden('A visit is completed when the doctor finishes the prescription.');
        }
        $next = (string) $this->request->get('status');
        if (!in_array($next, self::FLOW[$appt['status']] ?? [], true)) {
            Response::error(Lang::t('An appointment that is “{status}” can’t be marked “{next}”.', ['status' => $appt['status'], 'next' => $next]), 409);
        }

        $data = ['status' => $next];
        if ($next === 'arrived' && empty($appt['arrived_at'])) {
            $data['arrived_at'] = gmdate('Y-m-d H:i:s');   // keep the original arrival across step-out / call-in
        }
        if ($next === 'cancelled') {
            $reason = self::clean((string) ($this->request->get('cancel_reason') ?? ''));
            $data['cancelled_by'] = in_array($this->request->get('cancelled_by'), ['doctor', 'patient'], true) ? $this->request->get('cancelled_by') : 'doctor';
            $data['cancel_reason'] = $reason === '' ? null : mb_substr($reason, 0, 300);
        }
        $this->db->update('appointments', $data, 'id = :id', [':id' => $id]);

        Response::success(['appointment' => $this->load($id, (int) $doctor['id'])], 'Appointment updated');
    }

    // ── POST /appointments/{id}/call ─────────────────────────────────────────
    /** The doctor (or receptionist) calls the patient in; the queue device announces them. */
    public function call(array $params): void
    {
        $doctor = $this->requireClinic();
        $id     = (int) $params['id'];
        $appt   = $this->rowOrFail($id, (int) $doctor['id']);
        if (!in_array($appt['status'], ['scheduled', 'confirmed', 'arrived', 'not_available'], true)) {
            Response::error('Only a waiting patient can be called in.', 409);
        }
        $calledAt = gmdate('Y-m-d H:i:s');
        $this->db->update('appointments', ['called_at' => $calledAt], 'id = :id', [':id' => $id]);
        $this->notifyCallIn((int) $doctor['id'], $id, $calledAt);
        Response::success(['appointment' => $this->load($id, (int) $doctor['id'])], 'Patient called');
    }

    /**
     * Tell the reception device(s) to announce the patient. Sent as a SILENT,
     * high-priority, data-only message so the app speaks the name itself — even
     * when it is in the background or closed — and the same way no matter who
     * pressed "Call in" (doctor's phone or desktop, or the receptionist's own
     * device: the caller is NOT excluded). Goes to the clinic's receptionist
     * devices; a clinic with none registered falls back to all its devices.
     * Short TTL so a phone that was offline never announces an old call.
     * Best-effort — never breaks the call action.
     */
    private function notifyCallIn(int $clinicDoctorId, int $apptId, string $calledAt): void
    {
        try {
            $row  = $this->db->getRow(
                'SELECT p.name, d.id AS doctor_id, d.name AS doctor_name FROM appointments a JOIN patients p ON p.id = a.patient_id
                 LEFT JOIN doctors d ON d.id = a.consultant_id WHERE a.id = :i', [':i' => $apptId]);
            $name = trim((string) ($row['name'] ?? '')) ?: 'The next patient';
            $devices = $this->db->getRows('SELECT token, owner_type, owner_id FROM device_tokens WHERE doctor_id = :d', [':d' => $clinicDoctorId]);
            // Reception announces. A clinic with no reception device falls back to the consulting doctor's.
            if (!array_filter($devices, fn ($t) => $t['owner_type'] === 'staff')) {
                $own = array_values(array_filter($devices, fn ($t) => (int) $t['owner_id'] === (int) ($row['doctor_id'] ?? 0)));
                $devices = $own ?: $devices;
            }
            $tokens = PushHelper::callInTokens($devices);
            $many = (int) $this->db->getRow('SELECT COUNT(*) AS n FROM doctors WHERE (id = :a OR clinic_owner_id = :b) AND deleted_at IS NULL AND is_active = 1', [':a' => $clinicDoctorId, ':b' => $clinicDoctorId])['n'] > 1;
            if ($tokens) {
                PushHelper::sendDataToTokens($tokens, [
                    'type'           => 'call_in',
                    'name'           => $name,
                    // Said aloud only when the clinic has more than one doctor.
                    'doctor'         => $many ? (string) ($row['doctor_name'] ?? '') : '',
                    'appointment_id' => (string) $apptId,
                    // Same id on every device and in the poll, so one call is spoken once.
                    'call_id'        => $apptId . '@' . strtotime($calledAt . ' UTC'),
                ], 45);
            }
        } catch (\Throwable $e) {
            // ignore — the call still succeeds without the push
        }
    }

    // ── GET /appointments/{id}/intake ────────────────────────────────────────
    public function getIntake(array $params): void
    {
        $doctor = $this->requireClinic();
        $id     = (int) $params['id'];
        $this->rowOrFail($id, (int) $doctor['id']);
        Response::success(['intake' => $this->intakeFor($id)], 'Intake fetched');
    }

    // ── POST /appointments/{id}/intake ───────────────────────────────────────
    /** The receptionist records vitals at intake; the doctor's Rx editor prefills from them. */
    public function saveIntake(array $params): void
    {
        $doctor = $this->requireClinic();
        $id     = (int) $params['id'];
        $appt   = $this->rowOrFail($id, (int) $doctor['id']);

        $vit = []; $err = [];
        foreach (self::INTAKE_VITALS as $col => [$min, $max, $int]) {
            $raw = $this->request->input($col);
            if ($raw === null || $raw === '') { $vit[$col] = null; continue; }
            if (!is_numeric($raw)) { $err[$col] = ['Enter a number.']; continue; }
            $v = $int ? (int) $raw : round((float) $raw, 1);
            if ($v < $min || $v > $max) { $err[$col] = [Lang::t('Enter a value between {min} and {max}.', ['min' => $min, 'max' => $max])]; continue; }
            $vit[$col] = $v;
        }
        if (($vit['bp_systolic'] ?? null) !== null && ($vit['bp_diastolic'] ?? null) !== null && $vit['bp_diastolic'] >= $vit['bp_systolic']) {
            $err['bp_diastolic'] = ['Diastolic should be below systolic.'];
        }
        if ($err) Response::validationError($err);

        $data     = $vit + ['recorded_by_staff_id' => $this->staff['id'] ?? null];
        $existing = $this->db->getRow('SELECT id FROM intake_vitals WHERE appointment_id = :a', [':a' => $id]);
        if ($existing) {
            $this->db->update('intake_vitals', $data, 'id = :i', [':i' => $existing['id']]);
        } else {
            $this->db->insert('intake_vitals', $data + ['doctor_id' => (int) $doctor['id'], 'appointment_id' => $id, 'patient_id' => (int) $appt['patient_id']]);
        }
        Response::success(['intake' => $this->intakeFor($id)], 'Vitals saved');
    }

    private function intakeFor(int $apptId): ?array
    {
        $r = $this->db->getRow('SELECT * FROM intake_vitals WHERE appointment_id = :a', [':a' => $apptId]);
        if (!$r) return null;
        $num = fn ($v) => $v === null ? null : $v + 0;
        return [
            'weight_kg' => $num($r['weight_kg']), 'height_cm' => $num($r['height_cm']),
            'bp_systolic' => $num($r['bp_systolic']), 'bp_diastolic' => $num($r['bp_diastolic']),
            'pulse_bpm' => $num($r['pulse_bpm']), 'temperature_f' => $num($r['temperature_f']),
            'spo2_pct' => $num($r['spo2_pct']), 'rbs_fasting_mg_dl' => $num($r['rbs_fasting_mg_dl']), 'rbs_pp_mg_dl' => $num($r['rbs_pp_mg_dl']),
            'recorded_at' => $r['updated_at'],
        ];
    }

    // ── Input ────────────────────────────────────────────────────────────────
    private function parse(bool $walkIn): array
    {
        $this->validate([
            'reason'       => 'nullable|string|max:500',
            'notes'        => 'nullable|string|max:500',
            'duration_min' => 'nullable|integer',
        ]);

        $ts = null;
        if (!$walkIn) {
            $raw = trim((string) ($this->request->get('scheduled_at') ?? ''));
            $ts  = $raw === '' ? false : strtotime($raw . (preg_match('/(Z|[+-]\d\d:?\d\d)$/', $raw) ? '' : ' UTC'));
            if ($ts === false) {
                Response::validationError(['scheduled_at' => ['Choose a date and time for the appointment.']]);
            }
            if ($ts < time() - 15 * 60) {
                Response::validationError(['scheduled_at' => ['That time has already passed.']]);
            }
            if ($ts > strtotime('+2 years')) {
                Response::validationError(['scheduled_at' => ['That date is too far ahead.']]);
            }
        }

        $dur = $this->request->get('duration_min');
        $dur = ($dur === null || $dur === '') ? 15 : (int) $dur;
        if ($dur < 5 || $dur > 180) {
            Response::validationError(['duration_min' => ['Duration must be between 5 and 180 minutes.']]);
        }
        $opt = fn (string $k) => ($v = self::clean((string) ($this->request->get($k) ?? ''))) === '' ? null : $v;

        return ['ts' => $ts, 'duration' => $dur, 'reason' => $opt('reason'), 'notes' => $opt('notes')];
    }

    private function utc(mixed $raw): ?string
    {
        $raw = trim((string) ($raw ?? ''));
        if ($raw === '') return null;
        $ts = strtotime($raw . (preg_match('/(Z|[+-]\d\d:?\d\d)$/', $raw) ? '' : ' UTC'));
        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    // ── Data ─────────────────────────────────────────────────────────────────
    private function ownPatient(int $id, int $doctorId): array
    {
        $p = $id > 0 ? $this->db->getRow('SELECT * FROM patients WHERE id = :i AND doctor_id = :d AND deleted_at IS NULL', [':i' => $id, ':d' => $doctorId]) : null;
        if (!$p) Response::validationError(['patient_id' => ['Choose a patient from your register.']]);
        return $p;
    }

    /**
     * The doctor a visit is for: the one asked for, else the signed-in doctor, else (reception, or an
     * older app) the clinic owner.
     */
    private function consultantFor(int $tenant, mixed $asked): int
    {
        if ($asked !== null && $asked !== '') {
            if (!$this->clinicDoctor($tenant, (int) $asked)) {
                Response::validationError(['consultant_id' => ['Choose a doctor from this clinic.']]);
            }
            return (int) $asked;
        }
        return $this->actorId ?? $tenant;
    }

    private function rowOrFail(int $id, int $doctorId): array
    {
        $r = $this->db->getRow('SELECT * FROM appointments WHERE id = :i AND doctor_id = :d', [':i' => $id, ':d' => $doctorId]);
        if (!$r) Response::notFound('Appointment not found.');
        return $r;
    }

    private function baseSelect(): string
    {
        return 'SELECT a.*, p.name AS patient_name, p.sex AS patient_sex, p.mobile AS patient_mobile, p.uhid AS patient_uhid,
                       p.age_years, p.date_of_birth, p.known_allergies, c.name AS consultant_name
                FROM appointments a JOIN patients p ON p.id = a.patient_id LEFT JOIN doctors c ON c.id = a.consultant_id';
    }

    private function load(int $id, int $doctorId): array
    {
        $r = $this->db->getRow($this->baseSelect() . ' WHERE a.id = :i AND a.doctor_id = :d', [':i' => $id, ':d' => $doctorId]);
        return $this->attachBills([self::shape($r)], $doctorId)[0];
    }

    private static function shape(array $r): array
    {
        return [
            'id'              => (int) $r['id'],
            'status'          => $r['status'],
            'consultant'      => ['id' => (int) ($r['consultant_id'] ?? $r['doctor_id']), 'name' => $r['consultant_name'] ?? null],
            'scheduled_at'    => $r['scheduled_at'],
            // When they checked in (the row's last change): drives "waiting 12 min".
            'arrived_at'      => $r['status'] === 'arrived' ? ($r['arrived_at'] ?? $r['updated_at']) : null,
            'called_at'       => $r['called_at'] ?? null,
            'case_type'       => $r['case_type'] ?? null,
            'duration_min'    => (int) $r['duration_min'],
            'reason'          => $r['reason'],
            'notes'           => $r['notes'],
            'cancel_reason'   => $r['cancel_reason'],
            'prescription_id' => $r['prescription_id'] === null ? null : (int) $r['prescription_id'],
            'patient'         => [
                'id' => (int) $r['patient_id'], 'name' => $r['patient_name'], 'sex' => $r['patient_sex'], 'uhid' => $r['patient_uhid'],
                'mobile' => $r['patient_mobile'], 'known_allergies' => $r['known_allergies'],
                'age_label' => PatientController::ageLabel(['date_of_birth' => $r['date_of_birth'], 'age_years' => $r['age_years']]), 'date_of_birth' => (!empty($r['date_of_birth']) && $r['date_of_birth'] !== '0000-00-00') ? $r['date_of_birth'] : null,
            ],
        ];
    }
}
