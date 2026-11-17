<?php

declare(strict_types=1);

/**
 * Pediatric dose maths and drug matching. Pure functions (no database, no request) so the numbers can be
 * checked on their own: see tests/php_pediatric_calc.php.
 *
 * The formulas, in the dose unit of the drug (mg unless stated):
 *   per kg per DAY : daily  = weight x dose;       single = daily / doses per day
 *   per kg per DOSE: single = weight x dose;       daily  = single x doses per day
 *   band           : single = the fixed dose for the child's age (months) or weight (kg)
 *   caps           : a single dose above max_single is cut to it; then a daily total above max_daily is cut
 *                    to it and the single dose is re-derived (daily / doses per day)
 *   liquid         : ml per dose = single / strength x volume the strength is quoted for (5 ml, or 1 ml for drops)
 *   tablet/capsule : units per dose = single / strength per unit
 */
final class PediatricDoseHelper
{
    private const WORDS_DROPPED = [
        'hydrochloride', 'hcl', 'sulfate', 'sulphate', 'sodium', 'potassium', 'dihydrate', 'trihydrate', 'monohydrate', 'hydrobromide',
        'maleate', 'tartrate', 'succinate', 'phosphate', 'acetate', 'besylate', 'citrate', 'palmitate', 'stearate', 'ethylsuccinate',
        'estolate', 'axetil', 'proxetil', 'medoxomil', 'benzoate', 'macrocrystals', 'microsize', 'dry', 'syrup', 'suspension', 'drops',
        'drop', 'tablet', 'tablets', 'capsule', 'capsules', 'injection', 'oral', 'solution', 'elixir', 'forte', 'dt', 'md', 'xr', 'sr',
        'cr', 'er', 'elemental', 'base', 'pediatric', 'paediatric', 'ds', 'es', 'duo', 'mg', 'mcg', 'gm', 'ml', 'iu',
    ];

    /** Other spellings of the same ingredient, folded to the one the reference uses. */
    private const SYNONYMS = [
        'acetaminophen' => 'paracetamol', 'amoxycillin' => 'amoxicillin', 'clavulanate' => 'clavulanic acid', 'clavulanic' => 'clavulanic acid',
        'cephalexin' => 'cefalexin', 'sulphamethoxazole' => 'sulfamethoxazole', 'aciclovir' => 'acyclovir', 'chlorphenamine' => 'chlorpheniramine',
        'phenobarbital' => 'phenobarbitone', 'albuterol' => 'salbutamol', 'valproic acid' => 'valproate', 'divalproex' => 'valproate',
        'ferrous' => 'iron', 'folate' => 'folic acid',
    ];

    /** "Amoxycillin + Clavulanic acid 228.5mg/5ml dry syrup" => "amoxicillin+clavulanic acid". Combinations are order-free. */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/\([^)]*\)/u', ' ', $s) ?? $s;
        $s = preg_replace('/\d+(?:\.\d+)?\s*(?:mg|mcg|µg|gm|g|ml|iu|%)?(?:\s*\/\s*\d*\s*(?:ml|mg|g))?/u', ' ', $s) ?? $s;
        $parts = preg_split('/\s*(?:[+,\/&]|\band\b|\bwith\b)\s*/u', $s) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $words = preg_split('/[^a-z\-]+/u', trim($p), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $words = array_values(array_filter($words, fn ($w) => !in_array($w, self::WORDS_DROPPED, true)));
            $p = implode(' ', $words);
            $p = self::SYNONYMS[$p] ?? $p;
            if ($p !== '') $out[$p] = true;
        }
        $names = array_keys($out);
        sort($names);
        return implode('+', $names);
    }

    /**
     * The reference drug a prescribed medicine corresponds to, or null.
     * A combination only matches a combination entry with the same ingredients: "Paracetamol + Phenylephrine" is
     * never treated as plain paracetamol. The brand name is only consulted when the medicine has no generic name.
     *
     * @param array<int,array<string,mixed>> $drugs each with 'slug', 'name', 'components' and 'aliases' (list of strings)
     */
    public static function match(array $drugs, string $generic, string $name = ''): ?array
    {
        $g = self::normalize($generic);
        if ($g !== '') {
            foreach ($drugs as $d) {
                $keys = array_merge([$d['name'], $d['components']], $d['aliases']);
                foreach ($keys as $k) {
                    if (self::normalize((string) $k) === $g) return $d;
                }
            }
            return null;
        }
        $words = preg_split('/[^a-z0-9\-]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($drugs as $d) {
            foreach ($d['aliases'] as $a) {
                if (in_array(mb_strtolower((string) $a), $words, true)) return $d;
            }
        }
        return null;
    }

    /**
     * Exact age on a given day from the date of birth: whole months, and decimal years (1 dp).
     * Returns null when the date is not valid or lies after [$on].
     *
     * @return array{months:int, years:float, days:int}|null
     */
    public static function exactAge(string $dob, string $on): ?array
    {
        try {
            $b = new DateTimeImmutable($dob, new DateTimeZone('UTC'));
            $t = new DateTimeImmutable($on, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
        if ($b > $t) return null;
        $d = $b->diff($t);
        return ['months' => $d->y * 12 + $d->m, 'years' => round((int) $d->days / 365.25, 1), 'days' => (int) $d->days];
    }

    private static function num(mixed $v): ?float
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }

    /** Round without trailing noise: 3.6000000001 => 3.6 */
    private static function r(float $v, int $dp = 1): float
    {
        return round($v, $dp) + 0.0;
    }

    /** "1 1/2", "1/4"... a tablet count as people say it. */
    public static function fraction(float $n): string
    {
        $whole = (int) floor($n + 1e-9);
        $frac = $n - $whole;
        $f = match (true) {
            $frac < 0.125 => '', $frac < 0.375 => '¼', $frac < 0.625 => '½', $frac < 0.875 => '¾', default => '',
        };
        if ($frac >= 0.875) { $whole++; }
        if ($whole === 0 && $f === '') return '0';
        return trim(($whole > 0 ? (string) $whole : '') . ($f !== '' ? ($whole > 0 ? ' ' : '') . $f : ''));
    }

    public static function frequencyLabel(int $n, ?int $hours): string
    {
        $w = match ($n) { 1 => 'once a day (OD)', 2 => 'twice a day (BD)', 3 => 'three times a day (TDS)', 4 => 'four times a day (QID)', default => "$n times a day" };
        return $hours ? "$w, every $hours hours" : $w;
    }

    /**
     * Pick the fixed dose for this child. Returns [band|null, needsAge].
     *
     * @param array<int,array<string,mixed>> $bands
     */
    private static function pickBand(array $bands, float $w, ?int $ageM): array
    {
        $needsAge = false;
        foreach ($bands as $b) {
            $usesAge = isset($b['min_age_m']) || isset($b['max_age_m']);
            $usesWt = isset($b['min_wt']) || isset($b['max_wt']);
            if ($usesAge && $ageM === null) { $needsAge = true; continue; }
            if ($usesAge && (($ageM < ($b['min_age_m'] ?? 0)) || ($ageM > ($b['max_age_m'] ?? 99999)))) continue;
            if ($usesWt && (($w < ($b['min_wt'] ?? 0)) || ($w > ($b['max_wt'] ?? 9999)))) continue;
            return [$b, false];
        }
        return [null, $needsAge];
    }

    /** Apply the single-dose cap, then the daily cap. @return array{0:float,1:float,2:bool,3:bool} single, daily, cappedSingle, cappedDaily */
    private static function cap(float $single, float $daily, int $n, ?float $maxSingle, ?float $maxDaily): array
    {
        $cs = $cd = false;
        if ($maxSingle !== null && $maxSingle > 0 && $single > $maxSingle) { $single = $maxSingle; $daily = $single * $n; $cs = true; }
        if ($maxDaily !== null && $maxDaily > 0 && $daily > $maxDaily) { $daily = $maxDaily; $single = $daily / $n; $cd = true; }
        return [$single, $daily, $cs, $cd];
    }

    /** What a single dose is in one formulation. */
    public static function forFormulation(array $f, float $single, string $unit): array
    {
        $strength = (float) $f['strength'];
        $per = self::num($f['per_volume_ml'] ?? null);
        $row = [
            'id' => (int) $f['id'], 'type' => $f['form_type'], 'label' => $f['label'], 'note' => $f['note'] ?? null,
            'strength' => self::r($strength, 3), 'per_volume_ml' => $per, 'strength_total' => self::num($f['strength_total'] ?? null),
            'is_liquid' => $per !== null,
        ];
        if ($per !== null) {
            // A dose already expressed in ml (e.g. lactulose) IS the volume.
            $ml = $unit === 'ml' ? $single : ($strength > 0 ? $single / $strength * $per : 0.0);
            $row += ['ml_exact' => self::r($ml, 2), 'ml_per_dose' => self::r($ml, 1), 'suitable' => $ml >= 0.1 && $ml <= 30.0];
        } else {
            $units = $strength > 0 ? $single / $strength : 0.0;
            $prac = round($units * 4) / 4;
            $actual = $prac * $strength;
            $dev = $single > 0 ? abs($actual - $single) / $single * 100 : 0.0;
            $row += [
                'units_exact' => self::r($units, 2), 'units_per_dose' => self::r($prac, 2), 'units_label' => self::fraction($prac),
                'actual_dose' => self::r($actual, 2), 'deviation_pct' => self::r($dev, 0),
                'suitable' => $units >= 0.25 && $units <= 6.0 && $prac > 0,
            ];
        }
        return $row;
    }

    /**
     * The recommendation for one child and one drug.
     *
     * @param array<string,mixed> $drug  a pediatric_drugs row (JSON columns already decoded)
     * @param array<int,array<string,mixed>> $forms its formulations
     * @param array{strength?:?float,per_ml?:?float,form?:?string} $want the strength / form of the medicine being prescribed, if known
     */
    public static function calculate(array $drug, array $forms, float $w, ?int $ageM, array $want = []): array
    {
        $unit = (string) ($drug['dose_unit'] ?: 'mg');
        $n = max(1, (int) $drug['doses_per_day']);
        $basis = (string) $drug['basis'];
        $maxSingle = self::num($drug['max_single'] ?? null);
        $maxDaily = self::num($drug['max_daily'] ?? null);
        $typ = self::num($drug['dose_typ'] ?? null);
        $dmin = self::num($drug['dose_min'] ?? null) ?? $typ;
        $dmax = self::num($drug['dose_max'] ?? null) ?? $typ;
        $steps = [];
        $alerts = [];
        $band = null;

        if ($basis === 'band') {
            [$band, $needsAge] = self::pickBand($drug['bands'] ?? [], $w, $ageM);
            if ($band === null) {
                return ['needs_age' => $needsAge, 'calculated' => false,
                    'message' => $needsAge ? 'This dose depends on the child’s age. Add the patient’s date of birth or age to calculate it.' : 'No fixed dose is listed for this weight or age. Use clinical judgement.'];
            }
            $n = max(1, (int) ($band['n'] ?? $n));
            $single = isset($band['per_kg_dose']) ? $w * (float) $band['per_kg_dose'] : (float) $band['dose'];
            $daily = $single * $n;
            $steps[] = 'Fixed dose for this ' . (isset($band['min_wt']) || isset($band['max_wt']) ? 'weight' : 'age') . ": " . self::r($single, 2) . " $unit";
            $steps[] = 'Daily total = ' . self::r($single, 2) . " × $n = " . self::r($daily, 2) . " $unit";
            $sMin = $sMax = $single;
            $dMin = $dMax = $daily;
        } else {
            if ($typ === null) return ['calculated' => false, 'message' => 'No dose is recorded for this drug.'];
            if ($basis === 'per_kg_day') {
                $daily = $w * $typ;
                $single = $daily / $n;
                $steps[] = 'Total daily dose = ' . self::r($w, 2) . ' kg × ' . self::r($typ, 3) . " $unit/kg/day = " . self::r($daily, 2) . " $unit";
                $steps[] = 'Single dose = ' . self::r($daily, 2) . " ÷ $n doses = " . self::r($single, 2) . " $unit";
                [$sMin, $dMin] = [($w * $dmin) / $n, $w * $dmin];
                [$sMax, $dMax] = [($w * $dmax) / $n, $w * $dmax];
            } else {
                $single = $w * $typ;
                $daily = $single * $n;
                $steps[] = 'Single dose = ' . self::r($w, 2) . ' kg × ' . self::r($typ, 3) . " $unit/kg/dose = " . self::r($single, 2) . " $unit";
                $steps[] = 'Daily total = ' . self::r($single, 2) . " × $n doses = " . self::r($daily, 2) . " $unit";
                [$sMin, $dMin] = [$w * $dmin, $w * $dmin * $n];
                [$sMax, $dMax] = [$w * $dmax, $w * $dmax * $n];
            }
        }

        [$single, $daily, $capS, $capD] = self::cap($single, $daily, $n, $maxSingle, $maxDaily);
        if ($capS) {
            $alerts[] = ['level' => 'caution', 'text' => 'Capped at the maximum single dose of ' . self::r($maxSingle, 2) . " $unit."];
            $steps[] = 'Capped at the single-dose maximum: ' . self::r($single, 2) . " $unit";
        }
        if ($capD) {
            $alerts[] = ['level' => 'caution', 'text' => 'Capped at the maximum daily dose of ' . self::r($maxDaily, 2) . " $unit."];
            $steps[] = 'Capped at the daily maximum: ' . self::r($daily, 2) . " $unit, i.e. " . self::r($single, 2) . " $unit per dose";
        }
        [$sMin, $dMin] = array_slice(self::cap($sMin, $dMin, $n, $maxSingle, $maxDaily), 0, 2);
        [$sMax, $dMax] = array_slice(self::cap($sMax, $dMax, $n, $maxSingle, $maxDaily), 0, 2);

        $minAge = (int) ($drug['min_age_months'] ?? 0);
        $maxAge = (int) ($drug['max_age_months'] ?? 216);
        $minWt = (float) ($drug['min_weight_kg'] ?? 0);
        if ($ageM !== null && $ageM < $minAge) $alerts[] = ['level' => 'warning', 'text' => "Below the usual minimum age for this drug ($minAge months). Check it is appropriate."];
        if ($ageM !== null && $ageM > $maxAge) $alerts[] = ['level' => 'warning', 'text' => 'Above the paediatric age range. Use the adult dose if the child is large.'];
        if ($minWt > 0 && $w < $minWt) $alerts[] = ['level' => 'warning', 'text' => 'Below the usual minimum weight for this drug (' . self::r($minWt, 1) . ' kg).'];
        // Age known only to the year (no date of birth): the real age could be up to 11 months older or younger,
        // which only matters when a limit or a band edge is that close.
        $edges = $minAge > 0 ? [$minAge] : [];
        foreach ($drug['bands'] ?? [] as $bd) {
            if (isset($bd['min_age_m']) && $bd['min_age_m'] > 0) $edges[] = (int) $bd['min_age_m'];
            if (isset($bd['max_age_m']) && $bd['max_age_m'] < 216) $edges[] = (int) $bd['max_age_m'] + 1;
        }
        $near = false;
        foreach ($edges as $e) if ($ageM !== null && abs($ageM - $e) <= 11) $near = true;
        if (($want['age_exact'] ?? true) === false && $near) {
            $alerts[] = ['level' => 'warning', 'text' => 'The age is only known to the year. Add the date of birth for an exact age: this drug’s dose or limits change close to this age.'];
        }
        $maxDoses = $drug['max_doses_per_day'] ?? null;
        if ($maxDoses !== null) $alerts[] = ['level' => 'info', 'text' => "Do not exceed $maxDoses doses in 24 hours."];

        $rows = [];
        foreach ($forms as $f) $rows[] = self::forFormulation($f, $single, $unit);
        $chosen = self::chooseFormulation($rows, $forms, $want);
        if ($chosen !== null) {
            $c = $rows[$chosen];
            if ($c['is_liquid']) {
                $per = (float) $c['per_volume_ml'];
                if ($unit !== 'ml') $steps[] = 'Volume per dose = (' . self::r($single, 2) . " ÷ {$c['strength']}) × " . self::r($per, 2) . ' ml = ' . $c['ml_exact'] . ' ml';
                if (($c['strength_total'] ?? null) !== null && (float) $c['strength_total'] !== (float) $c['strength']) {
                    $alerts[] = ['level' => 'info', 'text' => "Calculated on the {$c['strength']} $unit dosing component, not the {$c['strength_total']} total on the label."];
                }
            } else {
                $steps[] = 'Units per dose = ' . self::r($single, 2) . " ÷ {$c['strength']} $unit = {$c['units_exact']} (about {$c['units_label']})";
                if ($c['deviation_pct'] > 15) $alerts[] = ['level' => 'caution', 'text' => 'Tablets cannot be split accurately to this dose. A liquid form may be better.'];
            }
        }

        return [
            'calculated' => true, 'needs_age' => false, 'unit' => $unit, 'doses_per_day' => $n,
            'frequency_label' => self::frequencyLabel($n, isset($drug['frequency_hours']) ? (int) $drug['frequency_hours'] : null),
            'single' => self::r($single, 2), 'daily' => self::r($daily, 2), 'capped_single' => $capS, 'capped_daily' => $capD,
            'single_min' => self::r($sMin, 2), 'single_max' => self::r($sMax, 2), 'daily_min' => self::r($dMin, 2), 'daily_max' => self::r($dMax, 2),
            'band' => $band, 'steps' => $steps, 'alerts' => $alerts, 'formulations' => $rows, 'chosen' => $chosen,
        ];
    }

    /** Index of the formulation to show first, or null. Prefers one that matches the label strength being prescribed. */
    private static function chooseFormulation(array $rows, array $forms, array $want): ?int
    {
        if (!$rows) return null;
        $s = $want['strength'] ?? null;
        if ($s !== null && $s > 0) {
            $per = $want['per_ml'] ?? null;
            foreach ($forms as $i => $f) {
                $fper = self::num($f['per_volume_ml'] ?? null);
                foreach ([(float) $f['strength'], self::num($f['strength_total'] ?? null)] as $label) {
                    if ($label === null) continue;
                    // Compare like with like: mg per 5 ml against mg per 5 ml, or mg per tablet against mg per tablet.
                    if (($fper !== null) !== ($per !== null)) continue;
                    $have = $fper !== null ? $label / $fper * $per : $label;
                    if (abs($have - $s) <= max(0.02 * $s, 0.01)) return $i;
                }
            }
        }
        $liquid = in_array(strtoupper((string) ($want['form'] ?? '')), ['SYR', 'LIQ', 'SUS', 'DROP'], true);
        $solid = in_array(strtoupper((string) ($want['form'] ?? '')), ['TAB', 'CAP'], true);
        foreach ([true, false] as $needSuitable) {
            foreach ($rows as $i => $r) {
                if ($liquid && !$r['is_liquid']) continue;
                if ($solid && $r['is_liquid']) continue;
                if ($needSuitable && !$r['suitable']) continue;
                return $i;
            }
        }
        foreach ($rows as $i => $r) if ($r['suitable']) return $i;
        return 0;
    }
}
