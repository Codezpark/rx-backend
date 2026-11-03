<?php

declare(strict_types=1);

/**
 * FeeHelper — works out the consultation fee for a visit from the clinic's
 * fee tiers (Module 4.2). A patient seen again within `old_case_window_days`
 * is an "old" (follow-up) case; a return the very next day is free when the
 * clinic opts in. `consultation_fee` is the fallback for any unset tier.
 *
 * Pure lookup + arithmetic over the doctor's own clinic + patient history —
 * no PHI leaves the clinic and every query is scoped by doctor_id.
 */
class FeeHelper
{
    public const CASE_TYPES = ['new', 'old', 'emergency'];

    public static function clinicFor(int $doctorId): ?array
    {
        return Database::getInstance()->getRow(
            'SELECT * FROM clinics WHERE doctor_id = :d AND deleted_at IS NULL ORDER BY is_primary DESC, id ASC LIMIT 1',
            [':d' => $doctorId]
        );
    }

    /** The fee for one tier, falling back to consultation_fee when the tier is unset. */
    public static function tierFee(?array $clinic, string $caseType, ?float $fallback): ?float
    {
        if (!$clinic) return $fallback;
        $col = ['new' => 'fee_new', 'old' => 'fee_old', 'emergency' => 'fee_emergency'][$caseType] ?? null;
        $v = ($col !== null && isset($clinic[$col]) && $clinic[$col] !== null) ? (float) $clinic[$col] : null;
        return $v ?? $fallback;
    }

    /**
     * Suggest a case type + fee for a patient. Looks at the most recent closed
     * visit (final/cancelled prescription) for this doctor.
     *
     * Pass $excludeRxId to ignore the visit being finalised, so it isn't counted
     * as its own "prior visit".
     *
     * Pass $caseType to honour a case type already chosen for this visit (e.g.
     * the receptionist's walk-in tag): the fee is taken straight off that tier
     * and history is not consulted. The manual tag always wins.
     *
     * @return array{case_type:string, fee:?float, free:bool, last_visit:?string, window_days:int}
     */
    public static function suggest(int $doctorId, int $patientId, ?int $excludeRxId = null, ?string $caseType = null): array
    {
        $db       = Database::getInstance();
        $clinic   = self::clinicFor($doctorId);
        $fallback = ($clinic && $clinic['consultation_fee'] !== null) ? (float) $clinic['consultation_fee'] : null;
        $window   = $clinic ? (int) ($clinic['old_case_window_days'] ?? 30) : 30;
        $freeNext = $clinic ? (bool) ($clinic['free_next_day'] ?? 0) : false;

        // A case type chosen up front wins: price straight off that tier.
        if ($caseType !== null && in_array($caseType, self::CASE_TYPES, true)) {
            $fee = self::tierFee($clinic, $caseType, $fallback);
            return ['case_type' => $caseType, 'fee' => $fee, 'free' => ($fee !== null && $fee <= 0.0), 'last_visit' => null, 'window_days' => $window];
        }

        $params = [':d' => $doctorId, ':p' => $patientId];
        $exclude = '';
        if ($excludeRxId !== null) { $exclude = ' AND id <> :ex'; $params[':ex'] = $excludeRxId; }
        $last = $db->getRow(
            "SELECT MAX(visit_date) AS d FROM prescriptions
              WHERE doctor_id = :d AND patient_id = :p AND status IN ('final','cancelled') AND deleted_at IS NULL" . $exclude,
            $params
        );
        $lastDate = $last['d'] ?? null;

        if ($lastDate === null) {
            return ['case_type' => 'new', 'fee' => self::tierFee($clinic, 'new', $fallback), 'free' => false, 'last_visit' => null, 'window_days' => $window];
        }

        $days = (int) floor((strtotime(gmdate('Y-m-d')) - strtotime(substr((string) $lastDate, 0, 10))) / 86400);
        if ($freeNext && $days >= 0 && $days <= 1) {
            return ['case_type' => 'old', 'fee' => 0.0, 'free' => true, 'last_visit' => $lastDate, 'window_days' => $window];
        }
        if ($days >= 0 && $days <= $window) {
            return ['case_type' => 'old', 'fee' => self::tierFee($clinic, 'old', $fallback), 'free' => false, 'last_visit' => $lastDate, 'window_days' => $window];
        }
        return ['case_type' => 'new', 'fee' => self::tierFee($clinic, 'new', $fallback), 'free' => false, 'last_visit' => $lastDate, 'window_days' => $window];
    }
}
