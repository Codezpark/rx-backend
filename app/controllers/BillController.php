<?php

declare(strict_types=1);

/** Simple billing: one bill per visit, part-payments, receipts, and the day's collection. */
class BillController extends BaseController
{
    private const METHODS = ['cash', 'upi', 'card', 'other'];
    private const MAX     = 10000000;

    /** GET bills?from=&to=&status=unpaid|paid|all&patient_id=&page= (from/to are UTC) */
    public function index(): void
    {
        $d   = (int) $this->requireClinic()['id'];
        $sql = "SELECT b.*, pt.name AS pt_name, pt.mobile AS pt_mobile, pt.uhid AS pt_uhid FROM bills b JOIN patients pt ON pt.id = b.patient_id WHERE b.doctor_id = :d";
        $p   = [':d' => $d];
        if (($from = $this->utc('from')) !== null) { $sql .= ' AND b.bill_date >= :f'; $p[':f'] = $from; }
        if (($to = $this->utc('to')) !== null)     { $sql .= ' AND b.bill_date < :t';  $p[':t'] = $to; }
        $status = (string) $this->request->get('status', 'all');
        if ($status === 'unpaid')    $sql .= " AND b.status IN ('unpaid','partial')";
        elseif ($status === 'paid')  $sql .= " AND b.status = 'paid'";
        else                         $sql .= " AND b.status <> 'cancelled'";
        if ((int) $this->request->get('patient_id', 0) > 0) { $sql .= ' AND b.patient_id = :p'; $p[':p'] = (int) $this->request->get('patient_id'); }
        $page = $this->db->paginate($sql . ' ORDER BY b.bill_date DESC, b.id DESC', $p, (int) $this->request->get('page', 1), 50);
        Response::success(['items' => array_map(fn ($r) => $this->shape($r), $page['data']), 'pagination' => $page['pagination']], 'Bills fetched');
    }

    /** GET bills/summary?from=&to= — money collected (by payment date), billed and still owed */
    public function summary(): void
    {
        $d = (int) $this->requireClinic()['id'];
        $f = $this->utc('from') ?? gmdate('Y-m-d 00:00:00');
        $t = $this->utc('to') ?? gmdate('Y-m-d H:i:s', strtotime($f . ' UTC') + 86400);
        $by = $this->db->getRows('SELECT method, SUM(amount) AS amt, COUNT(*) AS n FROM bill_payments WHERE doctor_id = :d AND paid_at >= :f AND paid_at < :t GROUP BY method', [':d' => $d, ':f' => $f, ':t' => $t]);
        $bl = $this->db->getRow("SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS billed FROM bills WHERE doctor_id = :d AND status <> 'cancelled' AND bill_date >= :f AND bill_date < :t", [':d' => $d, ':f' => $f, ':t' => $t]);
        $due = $this->db->getRow("SELECT COUNT(*) AS n, COALESCE(SUM(total - paid),0) AS owed FROM bills WHERE doctor_id = :d AND status IN ('unpaid','partial')", [':d' => $d]);
        $methods = array_fill_keys(self::METHODS, 0.0);
        foreach ($by as $r) $methods[$r['method']] = (float) $r['amt'];
        Response::success([
            'collected' => array_sum($methods), 'by_method' => $methods, 'bills' => (int) $bl['n'], 'billed' => (float) $bl['billed'],
            'outstanding' => (float) $due['owed'], 'outstanding_count' => (int) $due['n'],
        ], 'Summary fetched');
    }

    public function show(array $params): void
    {
        $d = (int) $this->requireClinic()['id'];
        Response::success(['bill' => $this->shape($this->own((int) $params['id'], $d), true)], 'Bill fetched');
    }

    /** POST bills {patient_id, prescription_id?, appointment_id?, items:[{description,amount}], discount?, paid?, method?, notes?} */
    public function create(): void
    {
        $d   = (int) $this->requireClinic()['id'];
        $err = [];
        $pid = (int) $this->request->input('patient_id', 0);
        $pt  = $pid > 0 ? $this->db->getRow('SELECT id FROM patients WHERE id = :i AND doctor_id = :d AND deleted_at IS NULL', [':i' => $pid, ':d' => $d]) : null;
        if (!$pt) Response::validationError(['patient_id' => ['Choose a patient.']]);

        $rxId = $this->request->input('prescription_id');
        if ($rxId !== null && $rxId !== '') {
            if (!$this->db->getRow('SELECT id FROM prescriptions WHERE id = :i AND doctor_id = :d AND patient_id = :p AND deleted_at IS NULL', [':i' => (int) $rxId, ':d' => $d, ':p' => $pid])) $err['prescription_id'] = ['Prescription not found for this patient.'];
        } else $rxId = null;
        $apId = $this->request->input('appointment_id');
        if ($apId !== null && $apId !== '') {
            if (!$this->db->getRow('SELECT id FROM appointments WHERE id = :i AND doctor_id = :d AND patient_id = :p', [':i' => (int) $apId, ':d' => $d, ':p' => $pid])) $err['appointment_id'] = ['Appointment not found for this patient.'];
        } else $apId = null;

        [$items, $sub] = $this->items($err);
        $discount = $this->money($this->request->input('discount', 0), 'discount', $err);
        if ($discount > $sub) $err['discount'] = ['Discount cannot be more than the total.'];
        $total = round($sub - $discount, 2);
        $paid  = $this->money($this->request->input('paid', 0), 'paid', $err);
        if ($paid > $total) $err['paid'] = ['Paid amount cannot be more than the bill.'];
        $method = (string) $this->request->input('method', 'cash');
        if (!in_array($method, self::METHODS, true)) $err['method'] = ['Choose cash, UPI, card or other.'];
        $notes = self::clean((string) $this->request->input('notes', ''));
        if (mb_strlen($notes) > 300) $err['notes'] = ['Keep notes under 300 characters.'];
        if ($err) Response::validationError($err);

        if ($rxId !== null && $this->db->getRow("SELECT id FROM bills WHERE doctor_id = :d AND prescription_id = :r AND status <> 'cancelled' LIMIT 1", [':d' => $d, ':r' => (int) $rxId])) {
            if (!filter_var($this->request->input('force', false), FILTER_VALIDATE_BOOLEAN)) Response::error('This prescription is already billed.', 409);
        }

        $this->db->beginTransaction();
        try {
            $n = (int) $this->db->getRow('SELECT COALESCE(MAX(CAST(SUBSTRING(bill_no, 3) AS UNSIGNED)), 0) AS n FROM bills WHERE doctor_id = :d FOR UPDATE', [':d' => $d])['n'] + 1;
            $id = $this->db->insert('bills', [
                'doctor_id' => $d, 'patient_id' => $pid, 'prescription_id' => $rxId === null ? null : (int) $rxId, 'appointment_id' => $apId === null ? null : (int) $apId,
                'bill_no' => sprintf('R-%04d', $n), 'bill_date' => gmdate('Y-m-d H:i:s'), 'subtotal' => $sub, 'discount' => $discount, 'total' => $total,
                'paid' => $paid, 'status' => self::statusFor($total, $paid), 'notes' => $notes === '' ? null : $notes,
            ]);
            foreach ($items as [$desc, $amt]) $this->db->insert('bill_items', ['bill_id' => $id, 'description' => $desc, 'amount' => $amt]);
            if ($paid > 0) $this->db->insert('bill_payments', ['bill_id' => $id, 'doctor_id' => $d, 'amount' => $paid, 'method' => $method, 'paid_at' => gmdate('Y-m-d H:i:s')]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        Response::created(['bill' => $this->shape($this->own($id, $d), true)], 'Bill created');
    }

    /**
     * POST bills/{id}/payment
     *   single:  {amount, method}
     *   split:   {payments: [{amount, method}, ...]}   (up to 3 rows — e.g. Cash+Card, Cash+UPI)
     * The sum may be less than the balance (a partial collection); never more.
     */
    public function pay(array $params): void
    {
        $d = (int) $this->requireClinic()['id'];
        $b = $this->own((int) $params['id'], $d);
        if ($b['status'] === 'cancelled') Response::error('This bill is cancelled.', 409);
        $err  = [];
        $owed = round((float) $b['total'] - (float) $b['paid'], 2);
        $rows = $this->paymentRows($err, $owed);
        if ($err) Response::validationError($err);

        $this->db->beginTransaction();
        try {
            $add = 0.0;
            foreach ($rows as [$amt, $method]) {
                $this->db->insert('bill_payments', ['bill_id' => $b['id'], 'doctor_id' => $d, 'amount' => $amt, 'method' => $method, 'paid_at' => gmdate('Y-m-d H:i:s')]);
                $add += $amt;
            }
            $paid = round((float) $b['paid'] + $add, 2);
            $this->db->update('bills', ['paid' => $paid, 'status' => self::statusFor((float) $b['total'], $paid)], 'id = :i', [':i' => $b['id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        Response::success(['bill' => $this->shape($this->own((int) $b['id'], $d), true)], 'Payment recorded');
    }

    /**
     * Normalise the pay() body into a list of [amount, method] rows.
     * @return list<array{0:float,1:string}>
     */
    private function paymentRows(array &$err, float $owed): array
    {
        $raw  = $this->request->input('payments');
        $rows = [];
        if (is_array($raw) && $raw) {
            if (count($raw) > 3) { $err['payments'] = ['Split a payment at most three ways.']; return []; }
            foreach (array_values($raw) as $i => $p) {
                $e   = [];
                $amt = $this->money(is_array($p) ? ($p['amount'] ?? null) : null, 'amount', $e);
                $met = is_array($p) ? (string) ($p['method'] ?? 'cash') : 'cash';
                if ($e || $amt <= 0) { $err["payments.$i"] = ['Enter the amount received.']; continue; }
                if (!in_array($met, self::METHODS, true)) { $err["payments.$i"] = ['Choose cash, UPI, card or other.']; continue; }
                $rows[] = [$amt, $met];
            }
        } else {
            $amt = $this->money($this->request->input('amount', 0), 'amount', $err);
            $met = (string) $this->request->input('method', 'cash');
            if (!in_array($met, self::METHODS, true)) $err['method'] = ['Choose cash, UPI, card or other.'];
            if ($amt <= 0) $err['amount'] = $err['amount'] ?? ['Enter the amount received.'];
            if (!$err) $rows[] = [$amt, $met];
        }
        if (!$err && $rows) {
            $sum = array_sum(array_map(fn ($r) => $r[0], $rows));
            if ($sum > $owed + 0.001) $err['amount'] = ['That is more than the ' . number_format($owed, 2) . ' still due.'];
        }
        return $rows;
    }

    /**
     * Create (or skip) the consultation bill for a finalised visit (Module 4.3).
     * Returns the new bill id, or null when the fee is 0 / already billed.
     * Runs in its own transaction, so call it outside one.
     */
    public static function consultationBill(int $doctorId, int $patientId, ?int $rxId, ?int $apId, float $fee, string $label = 'Consultation'): ?int
    {
        $fee = round($fee, 2);
        if ($fee <= 0 || $fee > self::MAX) return null;
        $db = Database::getInstance();
        if ($rxId !== null && $db->getRow("SELECT id FROM bills WHERE doctor_id = :d AND prescription_id = :r AND status <> 'cancelled' LIMIT 1", [':d' => $doctorId, ':r' => $rxId])) {
            return null; // the doctor already raised a bill for this prescription
        }
        $db->beginTransaction();
        try {
            $n  = (int) $db->getRow('SELECT COALESCE(MAX(CAST(SUBSTRING(bill_no, 3) AS UNSIGNED)), 0) AS n FROM bills WHERE doctor_id = :d FOR UPDATE', [':d' => $doctorId])['n'] + 1;
            $id = $db->insert('bills', [
                'doctor_id' => $doctorId, 'patient_id' => $patientId, 'prescription_id' => $rxId, 'appointment_id' => $apId,
                'bill_no' => sprintf('R-%04d', $n), 'bill_date' => gmdate('Y-m-d H:i:s'),
                'subtotal' => $fee, 'discount' => 0, 'total' => $fee, 'paid' => 0, 'status' => 'unpaid',
            ]);
            $db->insert('bill_items', ['bill_id' => $id, 'description' => $label, 'amount' => $fee]);
            $db->commit();
            return (int) $id;
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /** POST bills/{id}/cancel — its payments stop counting as collection (the doctor refunds by hand) */
    public function cancel(array $params): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $b = $this->own((int) $params['id'], $d);
        if ($b['status'] === 'cancelled') Response::error('This bill is already cancelled.', 409);
        $this->db->update('bills', ['status' => 'cancelled'], 'id = :i', [':i' => $b['id']]);
        // Refunded money is no longer collection.
        $this->db->delete('bill_payments', 'bill_id = :i', [':i' => $b['id']]);
        Response::success(['bill' => $this->shape($this->own((int) $b['id'], $d), true)], 'Bill cancelled');
    }

    private static function statusFor(float $total, float $paid): string
    {
        return $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
    }

    private function own(int $id, int $d): array
    {
        $b = $this->db->getRow('SELECT b.*, pt.name AS pt_name, pt.mobile AS pt_mobile, pt.uhid AS pt_uhid FROM bills b JOIN patients pt ON pt.id = b.patient_id WHERE b.id = :i AND b.doctor_id = :d', [':i' => $id, ':d' => $d]);
        if (!$b) Response::error('Not found', 404);
        return $b;
    }

    private function utc(string $key): ?string
    {
        $v = (string) $this->request->get($key, '');
        $t = $v === '' ? false : strtotime($v);
        return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
    }

    private function money(mixed $v, string $field, array &$err): float
    {
        if ($v === null || $v === '') return 0.0;
        if (!is_numeric($v) || (float) $v < 0 || (float) $v > self::MAX) { $err[$field] = ['Enter an amount between 0 and 1,00,00,000.']; return 0.0; }
        return round((float) $v, 2);
    }

    /** @return array{0: list<array{0:string,1:float}>, 1: float} */
    private function items(array &$err): array
    {
        $raw = $this->request->input('items');
        if (!is_array($raw) || !$raw) { $err['items'] = ['Add at least one item.']; return [[], 0.0]; }
        if (count($raw) > 20) { $err['items'] = ['Too many items (max 20).']; return [[], 0.0]; }
        $out = []; $sum = 0.0;
        foreach (array_values($raw) as $i => $it) {
            $desc = is_array($it) ? self::clean((string) ($it['description'] ?? '')) : '';
            if ($desc === '' || mb_strlen($desc) > 200) { $err["items.$i"] = ['Each item needs a description (max 200 characters).']; continue; }
            $e = [];
            $amt = $this->money($it['amount'] ?? null, 'amount', $e);
            if ($e || !isset($it['amount']) || $it['amount'] === '') { $err["items.$i"] = ['Each item needs an amount.']; continue; }
            $out[] = [$desc, $amt]; $sum += $amt;
        }
        return [$out, round($sum, 2)];
    }

    private function shape(array $b, bool $detail = false): array
    {
        $out = [
            'id' => (int) $b['id'], 'bill_no' => $b['bill_no'], 'bill_date' => $b['bill_date'], 'status' => $b['status'],
            'subtotal' => (float) $b['subtotal'], 'discount' => (float) $b['discount'], 'total' => (float) $b['total'], 'paid' => (float) $b['paid'],
            'due' => $b['status'] === 'cancelled' ? 0.0 : round((float) $b['total'] - (float) $b['paid'], 2),
            'prescription_id' => $b['prescription_id'] === null ? null : (int) $b['prescription_id'],
            'appointment_id' => $b['appointment_id'] === null ? null : (int) $b['appointment_id'],
            'notes' => $b['notes'],
            'patient' => ['id' => (int) $b['patient_id'], 'name' => $b['pt_name'], 'mobile' => $b['pt_mobile'], 'uhid' => $b['pt_uhid']],
        ];
        if ($detail) {
            $out['items'] = array_map(fn ($i) => ['description' => $i['description'], 'amount' => (float) $i['amount']],
                $this->db->getRows('SELECT description, amount FROM bill_items WHERE bill_id = :b ORDER BY id', [':b' => $b['id']]));
            $out['payments'] = array_map(fn ($p) => ['amount' => (float) $p['amount'], 'method' => $p['method'], 'paid_at' => $p['paid_at']],
                $this->db->getRows('SELECT amount, method, paid_at FROM bill_payments WHERE bill_id = :b ORDER BY id', [':b' => $b['id']]));
        }
        return $out;
    }
}
