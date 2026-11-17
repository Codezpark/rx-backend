<?php

declare(strict_types=1);

/**
 * End of day: who the doctor saw on a day and the money taken, so the day can be closed.
 * Days are the doctor's local calendar days: the app sends `date` (Y-m-d, default today),
 * `today` (its own Y-m-d) and `tz_offset` (minutes east of UTC), like Insights.
 * Doctor only — the receptionist has no access to the day's totals.
 */
class EodController extends BaseController
{
    private const PAST_DAYS = 14;
    private const METHODS   = ['cash', 'upi', 'card', 'other'];
    /** Best status wins when a patient has several entries in a day. */
    private const RANK = ['seen' => 4, 'waiting' => 3, 'upcoming' => 2, 'missed' => 1];

    /** GET eod?date=YYYY-MM-DD&today=YYYY-MM-DD&tz_offset=330 */
    public function index(): void
    {
        $d   = (int) $this->requireDoctor()['id'];
        $off = max(-840, min(840, (int) $this->request->get('tz_offset', 0)));
        $ok  = fn (string $s): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1 && strtotime($s . ' UTC') !== false;
        $t   = (string) $this->request->get('today', '');
        $today = $ok($t) ? $t : gmdate('Y-m-d', time() + $off * 60);
        $dt  = (string) $this->request->get('date', '');
        $date = $ok($dt) ? $dt : $today;
        if (strtotime($date . ' UTC') > strtotime($today . ' UTC')) $date = $today;   // no future days

        $utc  = fn (string $localDate): string => gmdate('Y-m-d H:i:s', strtotime($localDate . ' 00:00:00 UTC') - $off * 60);
        $next = gmdate('Y-m-d', strtotime($date . ' UTC') + 86400);
        $from = $utc($date);
        $to   = $utc($next);

        $rows = [];   // patient_id => row
        $touch = function (int $pid) use (&$rows): void {
            $rows[$pid] ??= ['patient_id' => $pid, 'status' => 'missed', 'case_type' => null, 'time' => null, 'diagnosis' => null, 'prescription_id' => null, 'billed' => 0.0, 'paid' => 0.0, 'due' => 0.0, 'bill_no' => null, 'visits' => 0];
        };
        $promote = function (int $pid, string $status) use (&$rows): void {
            if (self::RANK[$status] > self::RANK[$rows[$pid]['status']]) $rows[$pid]['status'] = $status;
        };
        $earliest = function (int $pid, string $ts) use (&$rows): void {
            if ($rows[$pid]['time'] === null || $ts < $rows[$pid]['time']) $rows[$pid]['time'] = $ts;
        };

        // The queue: everyone booked or walked in that day.
        foreach ($this->db->getRows('SELECT patient_id, status, case_type, scheduled_at AS ts FROM appointments WHERE doctor_id = :d AND scheduled_at >= :f AND scheduled_at < :t', [':d' => $d, ':f' => $from, ':t' => $to]) as $a) {
            $pid = (int) $a['patient_id'];
            $touch($pid);
            $promote($pid, match ($a['status']) {
                'completed'                         => 'seen',
                'arrived', 'not_available'          => 'waiting',
                'scheduled', 'confirmed'            => 'upcoming',
                default                             => 'missed',
            });
            $earliest($pid, (string) $a['ts']);
            if ($a['case_type'] !== null) $rows[$pid]['case_type'] = $a['case_type'];
            $rows[$pid]['visits']++;
        }

        // Finished prescriptions count as "seen" even when the visit never went through the queue.
        foreach ($this->db->getRows("SELECT id, patient_id, visit_date, diagnosis, chief_complaint FROM prescriptions WHERE doctor_id = :d AND status = 'final' AND deleted_at IS NULL AND visit_date >= :f AND visit_date < :t ORDER BY visit_date ASC", [':d' => $d, ':f' => $from, ':t' => $to]) as $p) {
            $pid = (int) $p['patient_id'];
            $touch($pid);
            $promote($pid, 'seen');
            $earliest($pid, (string) $p['visit_date']);
            $rows[$pid]['prescription_id'] = (int) $p['id'];
            $dx = trim((string) ($p['diagnosis'] ?? '')) ?: trim((string) ($p['chief_complaint'] ?? ''));
            if ($dx !== '') $rows[$pid]['diagnosis'] = $dx;
            if ($rows[$pid]['visits'] === 0) $rows[$pid]['visits'] = 1;
        }

        // Bills raised that day (cancelled ones never count).
        $billed = 0.0; $billCount = 0; $due = 0.0;
        foreach ($this->db->getRows("SELECT patient_id, bill_no, total, paid FROM bills WHERE doctor_id = :d AND status <> 'cancelled' AND bill_date >= :f AND bill_date < :t ORDER BY id ASC", [':d' => $d, ':f' => $from, ':t' => $to]) as $b) {
            $pid = (int) $b['patient_id'];
            $touch($pid);
            $owed = max(0.0, (float) $b['total'] - (float) $b['paid']);
            $rows[$pid]['billed'] += (float) $b['total'];
            $rows[$pid]['due']    += $owed;
            $rows[$pid]['bill_no'] = $b['bill_no'];
            $billed += (float) $b['total']; $billCount++; $due += $owed;
        }

        // Money actually received that day, by payment time (a payment on an older bill still counts today).
        $by = array_fill_keys(self::METHODS, 0.0);
        $payN = 0; $earlier = 0.0;
        foreach ($this->db->getRows("SELECT bp.amount, bp.method, b.patient_id, b.bill_date FROM bill_payments bp JOIN bills b ON b.id = bp.bill_id WHERE bp.doctor_id = :d AND b.status <> 'cancelled' AND bp.paid_at >= :f AND bp.paid_at < :t", [':d' => $d, ':f' => $from, ':t' => $to]) as $p) {
            $amt = (float) $p['amount'];
            $by[$p['method']] = ($by[$p['method']] ?? 0.0) + $amt;
            $payN++;
            if ($p['bill_date'] < $from) $earlier += $amt;
            $pid = (int) $p['patient_id'];
            $touch($pid);
            $rows[$pid]['paid'] += $amt;
        }

        // Patient details for everyone listed.
        $people = [];
        if ($rows) {
            $ids = implode(',', array_map('intval', array_keys($rows)));
            foreach ($this->db->getRows("SELECT id, uhid, name, sex, date_of_birth, age_years, created_at FROM patients WHERE doctor_id = :d AND id IN ($ids)", [':d' => $d]) as $p) $people[(int) $p['id']] = $p;
        }
        $list = []; $counts = ['seen' => 0, 'waiting' => 0, 'upcoming' => 0, 'missed' => 0]; $new = 0;
        foreach ($rows as $pid => $r) {
            $p = $people[$pid] ?? null;
            if (!$p) continue;
            $isNew = $p['created_at'] >= $from && $p['created_at'] < $to;
            if ($isNew && $r['status'] === 'seen') $new++;
            // Someone who only has a bill or a payment today (no visit) is listed apart and not counted as a visit.
            if ($r['visits'] === 0) {
                $r['status'] = 'billing';
            } else {
                $counts[$r['status']]++;
            }
            $list[] = [
                'patient_id' => $pid, 'uhid' => $p['uhid'], 'name' => $p['name'], 'sex' => $p['sex'],
                'age_label' => PatientController::ageLabel(['date_of_birth' => $p['date_of_birth'], 'age_years' => $p['age_years']]),
                'status' => $r['status'], 'case_type' => $r['case_type'], 'time' => $r['time'], 'is_new' => $isNew,
                'diagnosis' => $r['diagnosis'], 'prescription_id' => $r['prescription_id'], 'bill_no' => $r['bill_no'],
                'billed' => round($r['billed'], 2), 'paid' => round($r['paid'], 2), 'due' => round($r['due'], 2),
            ];
        }
        $order = ['seen' => 0, 'waiting' => 1, 'upcoming' => 2, 'missed' => 3, 'billing' => 4];
        usort($list, fn ($a, $b) => [$order[$a['status']], (string) $a['time'], $a['name']] <=> [$order[$b['status']], (string) $b['time'], $b['name']]);

        Response::success([
            'date' => $date, 'is_today' => $date === $today, 'from' => $from, 'to' => $to,
            'totals' => ['patients' => $counts['seen'] + $counts['waiting'] + $counts['upcoming'], 'seen' => $counts['seen'], 'waiting' => $counts['waiting'], 'upcoming' => $counts['upcoming'], 'missed' => $counts['missed'], 'new_patients' => $new],
            'patients' => $list,
            'charges' => [
                'collected' => round(array_sum($by), 2), 'by_method' => array_map(fn ($v) => round($v, 2), $by), 'payments' => $payN,
                'from_earlier_bills' => round($earlier, 2), 'billed' => round($billed, 2), 'bills' => $billCount, 'due' => round($due, 2),
            ],
            'days' => $this->pastDays($d, $date, $off, $ok),
        ], 'End of day fetched');
    }

    /** The last PAST_DAYS local days up to and including $date: patients seen and money taken, for the "Past" view. */
    private function pastDays(int $d, string $date, int $off, callable $ok): array
    {
        $first = gmdate('Y-m-d', strtotime($date . ' UTC') - (self::PAST_DAYS - 1) * 86400);
        $from  = gmdate('Y-m-d H:i:s', strtotime($first . ' 00:00:00 UTC') - $off * 60);
        $to    = gmdate('Y-m-d H:i:s', strtotime($date . ' 00:00:00 UTC') + 86400 - $off * 60);
        $out   = [];
        for ($i = 0; $i < self::PAST_DAYS; $i++) {
            $k = gmdate('Y-m-d', strtotime($first . ' UTC') + $i * 86400);
            $out[$k] = ['date' => $k, 'patients' => 0, 'prescriptions' => 0, 'collected' => 0.0];
        }
        $seen = [];
        foreach ($this->db->getRows("SELECT patient_id, DATE(DATE_ADD(visit_date, INTERVAL :o MINUTE)) AS k FROM prescriptions WHERE doctor_id = :d AND status = 'final' AND deleted_at IS NULL AND visit_date >= :f AND visit_date < :t", [':o' => $off, ':d' => $d, ':f' => $from, ':t' => $to]) as $r) {
            if (!isset($out[$r['k']])) continue;
            $out[$r['k']]['prescriptions']++;
            $seen[$r['k']][(int) $r['patient_id']] = true;
        }
        foreach ($seen as $k => $set) $out[$k]['patients'] = count($set);
        foreach ($this->db->getRows("SELECT DATE(DATE_ADD(bp.paid_at, INTERVAL :o MINUTE)) AS k, SUM(bp.amount) AS amt FROM bill_payments bp JOIN bills b ON b.id = bp.bill_id WHERE bp.doctor_id = :d AND b.status <> 'cancelled' AND bp.paid_at >= :f AND bp.paid_at < :t GROUP BY k", [':o' => $off, ':d' => $d, ':f' => $from, ':t' => $to]) as $r) {
            if (isset($out[$r['k']])) $out[$r['k']]['collected'] = round((float) $r['amt'], 2);
        }
        return array_values(array_reverse($out));   // newest first
    }
}
