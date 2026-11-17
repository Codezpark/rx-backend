<?php
/** Pediatric dose maths + drug matching, no database: php tests/php_pediatric_calc.php */
declare(strict_types=1);
require __DIR__ . '/../helpers/PediatricDoseHelper.php';

$fails = 0;
function check(string $name, bool $ok, $extra = ''): void { global $fails; echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok ? '' : '  -> ' . json_encode($extra)) . "\n"; if (!$ok) $fails++; }
function near(float $a, float $b, float $eps = 0.011): bool { return abs($a - $b) <= $eps; }

// Build the same shapes the controller builds, from the seed source.
$seed = json_decode(file_get_contents(__DIR__ . '/../migrations/data/pediatric_drugs.json'), true);
$drugs = []; $forms = [];
foreach ($seed as $i => $d) {
    $id = $i + 1;
    $drugs[$d['slug']] = $d + ['id' => $id, 'dose_min' => $d['dose_min'], 'dose_typ' => $d['dose_typ'], 'dose_max' => $d['dose_max'], 'verified' => $d['verified']];
    $drugs[$d['slug']]['aliases'] = array_merge([$d['name']], $d['aliases'], $d['brands']);
    $drugs[$d['slug']]['verified'] = $d['verified'];
    foreach ($d['formulations'] as $j => $f) $forms[$d['slug']][] = $f + ['id' => $id * 100 + $j];
}
$calc = fn (string $slug, float $w, ?int $age = null, array $want = []) => PediatricDoseHelper::calculate($drugs[$slug], $forms[$slug], $w, $age, $want);
$chosen = fn (array $r) => $r['formulations'][$r['chosen']];

echo "The formulas\n";
$r = $calc('amoxicillin', 13.5, null, ['strength' => 250, 'per_ml' => 5]);
check('amoxicillin 13.5 kg: daily 540, dose 180', near($r['daily'], 540) && near($r['single'], 180), $r);
check('180 mg of 250 mg/5 ml = 3.6 ml (the worked example)', $chosen($r)['strength'] == 250 && near($chosen($r)['ml_exact'], 3.6) && $chosen($r)['ml_per_dose'] == 3.6, $chosen($r));
check('3 doses a day, range 20-90 mg/kg/day', $r['doses_per_day'] === 3 && near($r['daily_min'], 270) && near($r['daily_max'], 1215), $r);

$r = $calc('paracetamol', 10, null, ['strength' => 120, 'per_ml' => 5]);
check('paracetamol is per DOSE: 10 kg x 15 = 150 mg, x4 = 600/day', near($r['single'], 150) && near($r['daily'], 600) && $r['doses_per_day'] === 4, $r);
check('150 mg of 120 mg/5 ml = 6.25 ml', near($chosen($r)['ml_exact'], 6.25), $chosen($r));
$r = $calc('paracetamol', 80);
check('adult-sized child: single dose capped at 1000 mg', near($r['single'], 1000) && $r['capped_single'] === true, $r);
check('a max-doses reminder is shown', (bool) array_filter($r['alerts'], fn ($a) => str_contains($a['text'], '5 doses')), $r['alerts']);

$r = $calc('ibuprofen', 12, null, ['strength' => 100, 'per_ml' => 5]);
check('ibuprofen 12 kg: 120 mg, 6 ml, 3 times a day', near($r['single'], 120) && near($chosen($r)['ml_exact'], 6) && $r['doses_per_day'] === 3, $r);
check('ibuprofen 60 kg capped at 400 per dose', near($calc('ibuprofen', 60)['single'], 400), $calc('ibuprofen', 60));

$r = $calc('cefixime', 10, null, ['strength' => 100, 'per_ml' => 5]);
check('cefixime 10 kg: 80 mg/day, 40 mg dose, 2 ml', near($r['daily'], 80) && near($r['single'], 40) && near($chosen($r)['ml_exact'], 2), $r);
$r = $calc('azithromycin', 20, null, ['strength' => 200, 'per_ml' => 5]);
check('azithromycin 20 kg once daily: 200 mg = 5 ml', near($r['single'], 200) && $r['doses_per_day'] === 1 && near($chosen($r)['ml_exact'], 5), $r);
$r = $calc('cefalexin', 12, null, ['strength' => 250, 'per_ml' => 5]);
check('cefalexin 12 kg: 600/day, 150 mg, 3 ml', near($r['daily'], 600) && near($r['single'], 150) && near($chosen($r)['ml_exact'], 3), $r);

echo "Combination products: dose by the first ingredient, not the label total\n";
$r = $calc('amoxicillin-clavulanate', 10, null, ['strength' => 228.5, 'per_ml' => 5]);
check('228.5 mg/5 ml label selects the 200 mg amoxicillin formulation', $chosen($r)['strength'] == 200 && (float) $chosen($r)['strength_total'] == 228.5, $chosen($r));
check('225 mg amoxicillin per dose = 5.6 ml (not 4.9 ml)', near($r['single'], 225) && near($chosen($r)['ml_exact'], 5.63) && $chosen($r)['ml_per_dose'] == 5.6, $chosen($r));
check('a note says which component was used', (bool) array_filter($r['alerts'], fn ($a) => str_contains($a['text'], 'dosing component')), $r['alerts']);
$r = $calc('co-trimoxazole', 15, null, ['strength' => 240, 'per_ml' => 5]);
check('co-trimoxazole by trimethoprim: 15 kg x 8 = 120/day, 60 mg dose, 7.5 ml of 40 mg/5 ml', near($r['single'], 60) && near($chosen($r)['ml_exact'], 7.5), $chosen($r));

echo "Capping the day\n";
$r = $calc('azithromycin', 70);
check('azithromycin 70 kg: daily max 500', near($r['daily'], 500) && $r['capped_daily'] === true, $r);
$r = $calc('amoxicillin', 90);
check('amoxicillin 90 kg: daily capped at 3000, dose re-derived (1000)', near($r['daily'], 3000) && near($r['single'], 1000), $r);

echo "Fixed (banded) doses\n";
$r = $calc('cetirizine', 12);
check('cetirizine needs the age', $r['calculated'] === false && $r['needs_age'] === true, $r);
$r = $calc('cetirizine', 12, 30);
check('cetirizine at 30 months: 2.5 mg twice a day', near($r['single'], 2.5) && $r['doses_per_day'] === 2, $r);
$r = $calc('oseltamivir', 20);
check('oseltamivir 20 kg: 45 mg twice a day (weight band)', near($r['single'], 45) && $r['doses_per_day'] === 2 && !($r['needs_age'] ?? false), $r);
$r = $calc('oseltamivir', 10);
check('oseltamivir 10 kg: 30 mg', near($r['single'], 30), $r);
$r = $calc('oseltamivir', 70);
check('oseltamivir 70 kg: 75 mg', near($r['single'], 75), $r);
$r = $calc('artemether-lumefantrine', 18);
check('artemether-lumefantrine 18 kg: 40 mg = 2 tablets', near($r['single'], 40) && $chosen($r)['units_per_dose'] == 2, $chosen($r));
$r = $calc('zinc-sulfate', 8, 4);
check('zinc under 6 months: 10 mg', near($r['single'], 10), $r);
$r = $calc('zinc-sulfate', 10, 12);
check('zinc at 12 months: 20 mg', near($r['single'], 20), $r);
$r = $calc('albendazole', 12, 18);
check('albendazole at 18 months: 200 mg = 5 ml of 200 mg/5 ml', near($r['single'], 200) && near($r['formulations'][0]['ml_exact'], 5), $r);
$r = $calc('lactulose', 10);
check('lactulose is dosed in ml: 10 kg x 1.5 = 15 ml/day, 7.5 ml', near($r['daily'], 15) && near($r['single'], 7.5) && near($r['formulations'][0]['ml_exact'], 7.5), $r);

echo "Warnings\n";
$r = $calc('ibuprofen', 4, 1);
check('ibuprofen at 1 month and 4 kg: age and weight warnings', count(array_filter($r['alerts'], fn ($a) => $a['level'] === 'warning')) === 2, $r['alerts']);
$r = $calc('cefixime', 6, 3);
check('cefixime at 3 months: below the minimum age', (bool) array_filter($r['alerts'], fn ($a) => str_contains($a['text'], 'minimum age')), $r['alerts']);

echo "Tablets\n";
$r = $calc('cefixime', 10, null, ['form' => 'TAB']);
check('a tablet form picks a tablet formulation', !$chosen($r)['is_liquid'], $chosen($r));
$r = $calc('paracetamol', 24, null, ['strength' => 500]);
check('24 kg paracetamol: 360 mg = about 3/4 of a 500 mg tablet', $chosen($r)['units_label'] === '¾' && $chosen($r)['strength'] == 500, $chosen($r));
check('fraction labels', PediatricDoseHelper::fraction(1.5) === '1 ½' && PediatricDoseHelper::fraction(0.25) === '¼' && PediatricDoseHelper::fraction(2.0) === '2', [PediatricDoseHelper::fraction(1.5)]);

echo "Exact age from the date of birth\n";
$a = PediatricDoseHelper::exactAge('2023-01-15', '2026-11-17');
check('2023-01-15 on 2026-11-17 = 46 months, 3.8 years', $a['months'] === 46 && $a['years'] === 3.8, $a);
check('one day short of 24 months is 23 months', PediatricDoseHelper::exactAge('2024-12-18', '2026-11-17')['months'] === 22 && PediatricDoseHelper::exactAge('2024-11-18', '2026-11-17')['months'] === 23, [PediatricDoseHelper::exactAge('2024-11-18', '2026-11-17')]);
check('exactly 24 months', PediatricDoseHelper::exactAge('2024-11-17', '2026-11-17')['months'] === 24);
check('newborn: 0 months', PediatricDoseHelper::exactAge('2026-11-10', '2026-11-17')['months'] === 0);
check('a date of birth in the future is refused', PediatricDoseHelper::exactAge('2027-01-01', '2026-11-17') === null);
check('garbage is refused', PediatricDoseHelper::exactAge('not a date', '2026-11-17') === null);
check('a 5-year-old: decimal years', PediatricDoseHelper::exactAge('2021-05-17', '2026-11-17')['years'] === 5.5, PediatricDoseHelper::exactAge('2021-05-17', '2026-11-17'));
$r = $calc('cetirizine', 12, 23);
check('cetirizine at 23 months is the once-a-day band', near($r['single'], 2.5) && $r['doses_per_day'] === 1, $r);
$r = $calc('cetirizine', 12, 24);
check('cetirizine at 24 months is the twice-a-day band', near($r['single'], 2.5) && $r['doses_per_day'] === 2, $r);
$r = $calc('cetirizine', 12, 30, ['age_exact' => false]);
check('an age known only to the year gets a warning on an age-banded drug', (bool) array_filter($r['alerts'], fn ($a) => $a['level'] === 'warning' && str_contains($a['text'], 'date of birth')), $r['alerts']);
$r = $calc('cetirizine', 12, 30, ['age_exact' => true]);
check('an exact age does not', !array_filter($r['alerts'], fn ($a) => str_contains($a['text'], 'date of birth')), $r['alerts']);
$r = $calc('paracetamol', 12, 30, ['age_exact' => false]);
check('a drug with no age limits is not nagged', !array_filter($r['alerts'], fn ($a) => str_contains($a['text'], 'date of birth')), $r['alerts']);
$r = $calc('cefixime', 12, 96, ['age_exact' => false]);
check('over 6 years the approximation does not matter', !array_filter($r['alerts'], fn ($a) => str_contains($a['text'], 'date of birth')), $r['alerts']);

echo "Matching the prescribed medicine\n";
$list = array_values($drugs);
$m = fn (string $g, string $n = '') => ($x = PediatricDoseHelper::match($list, $g, $n)) ? $x['slug'] : null;
check('Paracetamol', $m('Paracetamol') === 'paracetamol');
check('Acetaminophen', $m('Acetaminophen') === 'paracetamol');
check('Paracetamol / Acetaminophen', $m('Paracetamol / Acetaminophen') === 'paracetamol');
check('Amoxycillin (old spelling)', $m('Amoxycillin') === 'amoxicillin');
check('Amoxicillin+Clavulanate', $m('Amoxicillin+Clavulanate') === 'amoxicillin-clavulanate');
check('Amoxycillin + Clavulanic acid, any order', $m('Clavulanic Acid + Amoxycillin') === 'amoxicillin-clavulanate');
check('Cephalexin = cefalexin', $m('Cephalexin') === 'cefalexin');
check('Sodium Valproate', $m('Sodium Valproate') === 'sodium-valproate');
check('Ferrous Sulphate', $m('Ferrous Sulphate') === 'iron');
check('Zinc Sulfate', $m('Zinc Sulfate') === 'zinc-sulfate');
check('Trimethoprim + Sulphamethoxazole', $m('Trimethoprim + Sulphamethoxazole') === 'co-trimoxazole');
check('Cefixime 200 mg (strength in the name)', $m('Cefixime 200mg') === 'cefixime');
check('Montelukast (and Levocetirizine) is not just montelukast', $m('Montelukast+Levocetirizine') === null);
check('a paracetamol COMBINATION is not paracetamol', $m('Paracetamol+Phenylephrine+Chlorpheniramine') === null);
check('Ibuprofen+Paracetamol is not ibuprofen', $m('Ibuprofen+Paracetamol') === null);
check('unknown drug', $m('Nonexistol') === null);
check('brand name when there is no generic', $m('', 'Calpol 250') === 'paracetamol' && $m('', 'Zifi 100') === 'cefixime');
check('a brand is ignored when a generic is given', $m('Nonexistol', 'Calpol') === null);

echo "\n" . ($fails ? "$fails FAILED" : 'ALL PASSED') . "\n";
exit($fails ? 1 : 0);
