<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';

/**
 * CatalogController — lookup data for the prescription editor.
 *
 * GET  /catalog/medicines?q=   → medicines()      search, or "my frequently used" when q is blank
 * POST /catalog/medicines      → addMedicine()    doctor-private medicine
 * GET  /catalog/lab-tests?q=&codes= → labTests()   search / frequently ordered / by short codes
 * GET  /catalog/symptoms       → symptoms()
 * GET  /catalog/advice         → advice()
 * GET  /catalog/diagnoses      → diagnoses()      the doctor's own recent diagnoses
 *
 * Each medicine carries `last_used`: how *this* doctor last prescribed it
 * (schedule, duration, instructions) so the editor can pre-fill it.
 */
class CatalogController extends BaseController
{
    private const UNITS = ['piece', 'ml', 'mg', 'gm', 'kg', 'IU', 'mcg', 'drop', 'puff', 'sachet', 'patch', 'suppository', 'unit', 'other'];

    // "Syrup / Suspension" — the single oral-liquid category. Syrup vs suspension
    // is told apart by the form word in the medicine name (no structured column).
    private const LIQUID_CAT = 3;

    /**
     * Narrow the shared oral-liquid category (3) to the chosen sub-form by the
     * form word in the medicine's name: "Suspension" → names containing "susp";
     * "Syrup" → everything else in the category (syrups, dry syrups, and brands
     * whose name states no form). The literals are constant, so no params.
     */
    private static function liquidFormSql(string $form): string
    {
        return match ($form) {
            'SUS'        => " AND m.name LIKE '%susp%'",
            'SYR', 'LIQ' => " AND m.name NOT LIKE '%susp%'",
            default      => '',
        };
    }

    // ── GET /catalog/medicines ───────────────────────────────────────────────
    public function medicines(): void
    {
        $doctor = $this->requireDoctor();
        $d      = (int) $doctor['id'];
        $q      = trim((string) $this->request->get('q', ''));
        $cat    = (int) $this->request->get('category', 0);   // optional form filter (medicine_categories.id)
        $form   = strtoupper(trim((string) $this->request->get('form', '')));  // oral-liquid sub-form: SYR / SUS
        $limit  = $this->getLimit(20, 40);

        if ($q === '') {
            // With a form chosen but no text, browse that form; otherwise the doctor's frequent list.
            $items = $cat > 0 ? $this->browseCategory($d, $cat, 20, $form) : $this->frequentlyUsed($d, 12);
            Response::success(['items' => $items, 'kind' => $cat > 0 ? 'browse' : 'frequent'], 'Medicines fetched');
        }

        $esc    = addcslashes($q, '%_\\');
        $like   = '%' . $esc . '%';
        $prefix = $esc . '%';
        // Search brand name, generic/salt and company; plus "generic + strength" (e.g. "Cefixime 200").
        $where  = '(m.name LIKE :q1 OR m.generic_name LIKE :q2 OR m.manufacturer LIKE :q3)';
        $params = [':d' => $d, ':q1' => $like, ':q2' => $like, ':q3' => $like, ':p' => $prefix];
        $terms  = preg_split('/\s+/', $q);
        if (count($terms) >= 2 && preg_match('/\d/', (string) end($terms))) {
            $head = addcslashes(implode(' ', array_slice($terms, 0, -1)), '%_\\');
            $tail = addcslashes((string) end($terms), '%_\\');
            $where .= ' OR (m.generic_name LIKE :g AND m.strength LIKE :s)';
            $params[':g'] = '%' . $head . '%';
            $params[':s'] = '%' . $tail . '%';
        }
        // Narrow the search to the chosen form (Module 1: form-first entry).
        $catSql = '';
        if ($cat > 0) {
            $catSql = ' AND m.category_id = :cat';
            $params[':cat'] = $cat;
            if ($cat === self::LIQUID_CAT) $catSql .= self::liquidFormSql($form);
        }

        $rows = $this->db->getRows(
            'SELECT m.id, m.name, m.generic_name, m.manufacturer, m.strength, m.default_unit, m.category_id, c.name AS category_name
             FROM medicines m JOIN medicine_categories c ON c.id = m.category_id
             WHERE m.is_active = 1 AND (m.doctor_id IS NULL OR m.doctor_id = :d)
               AND (' . $where . ')' . $catSql . '
             ORDER BY (m.doctor_id IS NOT NULL) DESC, (m.name LIKE :p) DESC, m.name
             LIMIT ' . (int) $limit,
            $params
        );

        $last  = $this->lastUsed($d, array_column($rows, 'name'));
        $items = array_map(fn ($m) => [
            'medicine_id'   => (int) $m['id'],
            'name'          => $m['name'],
            'generic_name'  => $m['generic_name'],
            'manufacturer'  => $m['manufacturer'],
            'strength'      => $m['strength'],
            'unit'          => $m['default_unit'],
            'category_id'   => (int) $m['category_id'],
            'category_name' => $m['category_name'],
            'last_used'     => $last[mb_strtolower($m['name'])] ?? null,
        ], $rows);

        Response::success(['items' => $items, 'kind' => 'search'], 'Medicines fetched');
    }

    // ── POST /catalog/medicines ──────────────────────────────────────────────
    public function addMedicine(): void
    {
        $doctor = $this->requireDoctor();
        $this->validate([
            'name'         => 'required|string|min:2|max:200',
            'generic_name' => 'nullable|string|max:200',
            'manufacturer' => 'nullable|string|max:200',
            'strength'     => 'nullable|string|max:100',
            'category_id'  => 'required|integer',
            'default_unit' => 'nullable|in:' . implode(',', self::UNITS),
        ]);

        $name = self::clean((string) $this->request->get('name'));
        $cat  = $this->db->getRow('SELECT id, name, default_unit FROM medicine_categories WHERE id = :i AND is_active = 1', [':i' => (int) $this->request->get('category_id')]);
        if (!$cat) {
            Response::validationError(['category_id' => ['Choose a valid medicine type.']]);
        }

        $exists = $this->db->getRow(
            'SELECT id FROM medicines WHERE LOWER(name) = LOWER(:n) AND (doctor_id IS NULL OR doctor_id = :d) AND is_active = 1 LIMIT 1',
            [':n' => $name, ':d' => $doctor['id']]
        );
        if ($exists) {
            Response::error('A medicine with this name already exists.', 409);
        }

        $opt = fn (string $k) => ($v = self::clean((string) ($this->request->get($k) ?? ''))) === '' ? null : $v;
        $id  = $this->db->insert('medicines', [
            'category_id'  => $cat['id'],
            'name'         => $name,
            'generic_name' => $opt('generic_name'),
            'manufacturer' => $opt('manufacturer'),
            'strength'     => $opt('strength'),
            'default_unit' => $this->request->get('default_unit') ?: $cat['default_unit'],
            'is_system'    => 0,
            'doctor_id'    => $doctor['id'],
            'is_active'    => 1,
        ]);

        Response::created(['medicine' => [
            'medicine_id' => $id, 'name' => $name, 'generic_name' => $opt('generic_name'), 'manufacturer' => $opt('manufacturer'), 'strength' => $opt('strength'),
            'unit' => $this->request->get('default_unit') ?: $cat['default_unit'], 'category_id' => (int) $cat['id'],
            'category_name' => $cat['name'], 'last_used' => null,
        ]], 'Medicine added');
    }

    // ── GET /catalog/lab-tests ───────────────────────────────────────────────
    public function labTests(): void
    {
        $d     = (int) $this->requireDoctor()['id'];
        $q     = trim((string) $this->request->get('q', ''));
        $codes = array_values(array_filter(array_map('trim', explode(',', (string) $this->request->get('codes', '')))));
        $cols  = 'id, name, short_code, category, normal_range, unit';

        if ($codes) {                                   // panel shortcuts resolve their tests by code
            $in   = implode(',', array_fill(0, count($codes), '?'));
            $rows = $this->db->getRows("SELECT $cols FROM lab_tests_catalog WHERE is_active = 1 AND short_code IN ($in)", $codes);
            Response::success(['items' => array_map([self::class, 'labTest'], $rows), 'kind' => 'codes'], 'Tests fetched');
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $rows = $this->db->getRows(
                "SELECT $cols FROM lab_tests_catalog WHERE is_active = 1 AND (name LIKE :a OR short_code LIKE :b OR category LIKE :c)
                 ORDER BY (short_code = :exact) DESC, (name LIKE :p) DESC, name LIMIT 40",
                [':a' => $like, ':b' => $like, ':c' => $like, ':exact' => $q, ':p' => addcslashes($q, '%_\\') . '%']
            );
            Response::success(['items' => array_map([self::class, 'labTest'], $rows), 'kind' => 'search'], 'Tests fetched');
        }

        // Blank: what this doctor orders most, padded with the everyday basics.
        $top  = $this->db->getRows(
            "SELECT t.test_id, COUNT(*) c FROM lab_order_tests t JOIN lab_orders o ON o.id = t.lab_order_id
             WHERE o.doctor_id = :d AND t.test_id IS NOT NULL" . self::byDoctor('o.consultant_id', $this->actorId) . " GROUP BY t.test_id ORDER BY c DESC LIMIT 12", [':d' => $d]);
        $ids  = array_map('intval', array_column($top, 'test_id'));
        $basic = ['CBC', 'FBS', 'PPBS', 'HBA1C', 'LIPID', 'LFT', 'CR', 'TSH', 'URINE_RE', 'MALARIA', 'DENGUE_NS1', 'CRP'];
        $in   = implode(',', array_fill(0, count($basic), '?'));
        $b    = $this->db->getRows("SELECT $cols FROM lab_tests_catalog WHERE is_active = 1 AND short_code IN ($in)", $basic);
        $byId = [];
        if ($ids) {
            $in2 = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->getRows("SELECT $cols FROM lab_tests_catalog WHERE id IN ($in2)", $ids) as $r) $byId[(int) $r['id']] = $r;
        }
        $items = [];
        foreach ($ids as $id) if (isset($byId[$id])) $items[(int) $id] = $byId[$id];
        foreach ($b as $r) $items[(int) $r['id']] ??= $r;
        Response::success(['items' => array_map([self::class, 'labTest'], array_values($items)), 'kind' => 'frequent'], 'Tests fetched');
    }

    private static function labTest(array $r): array
    {
        return ['id' => (int) $r['id'], 'name' => $r['name'], 'short_code' => $r['short_code'], 'category' => $r['category'], 'normal_range' => $r['normal_range'], 'unit' => $r['unit']];
    }

    // ── GET /catalog/radiology ───────────────────────────────────────────────
    public function radiology(): void
    {
        $this->requireDoctor();
        $q = trim((string) $this->request->get('q', ''));
        if ($q === '') {
            $rows = $this->db->getRows('SELECT id, name, modality, body_part FROM radiology_catalog WHERE is_active = 1 ORDER BY name LIMIT 40');
        } else {
            $esc  = addcslashes($q, '%_\\');
            $like = '%' . $esc . '%';
            $rows = $this->db->getRows(
                'SELECT id, name, modality, body_part FROM radiology_catalog
                 WHERE is_active = 1 AND (name LIKE :a OR body_part LIKE :b OR modality LIKE :c)
                 ORDER BY (name LIKE :p) DESC, name LIMIT 40',
                [':a' => $like, ':b' => $like, ':c' => $like, ':p' => $esc . '%']
            );
        }
        Response::success(['items' => array_map(fn ($r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'modality' => $r['modality'], 'body_part' => $r['body_part'],
        ], $rows)], 'Radiology studies fetched');
    }

    // ── GET /catalog/symptoms | advice | diagnoses ───────────────────────────
    public function symptoms(): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $rows = $this->db->getRows(
            'SELECT id, name, category FROM symptoms WHERE is_active = 1 AND (doctor_id IS NULL OR doctor_id = :d) ORDER BY name',
            [':d' => $d]
        );
        // Most used by this doctor first, so their own top complaints sit at the front.
        $used = $this->db->getRows(
            'SELECT ps.symptom_id, COUNT(*) c FROM prescription_symptoms ps JOIN prescriptions p ON p.id = ps.prescription_id
             WHERE p.doctor_id = :d AND ps.symptom_id IS NOT NULL AND ps.deleted_at IS NULL' . self::byDoctor('p.consultant_id', $this->actorId) . ' GROUP BY ps.symptom_id',
            [':d' => $d]
        );
        $count = array_column($used, 'c', 'symptom_id');
        usort($rows, fn ($a, $b) => ((int) ($count[$b['id']] ?? 0) <=> (int) ($count[$a['id']] ?? 0)) ?: strcmp($a['name'], $b['name']));
        Response::success(['items' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'category' => $r['category']], $rows)], 'Symptoms fetched');
    }

    public function advice(): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $rows = $this->db->getRows(
            'SELECT id, category, text FROM advice_templates WHERE is_active = 1 AND (doctor_id IS NULL OR doctor_id = :d) ORDER BY category, id',
            [':d' => $d]
        );
        Response::success(['items' => array_map(fn ($r) => ['id' => (int) $r['id'], 'category' => $r['category'], 'text' => $r['text']], $rows)], 'Advice fetched');
    }

    public function diagnoses(): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $rows = $this->db->getRows(
            "SELECT diagnosis, COUNT(*) c FROM prescriptions
             WHERE doctor_id = :d AND deleted_at IS NULL AND status <> 'cancelled' AND diagnosis IS NOT NULL AND diagnosis <> ''" . self::byDoctor('consultant_id', $this->actorId) . "
             GROUP BY diagnosis ORDER BY c DESC, MAX(visit_date) DESC LIMIT 15",
            [':d' => $d]
        );
        Response::success(['items' => array_column($rows, 'diagnosis')], 'Diagnoses fetched');
    }

    // ── GET /catalog/pediatric-dose ──────────────────────────────────────────
    /**
     * Weight-based pediatric recommendation (advisory). No patient data is stored.
     * Query: weight (kg, required); one of generic (+ optional name) or drug (slug); optional age_months,
     * strength + per_ml (the label strength of the medicine being prescribed, e.g. 250 per 5 ml), form (SYR, TAB...).
     */
    public function pediatricDose(): void
    {
        $this->requireDoctor();
        $weight = $this->request->get('weight');
        if (!is_numeric($weight) || (float) $weight < 0.3 || (float) $weight > 150) {
            Response::validationError(['weight' => ['Enter the patient weight in kg (0.3–150).']]);
        }
        $w = (float) $weight;
        // Age: an exact one from the date of birth (preferred), else months, else whole years.
        $on  = (string) ($this->request->get('on') ?: gmdate('Y-m-d'));
        $dob = trim((string) $this->request->get('dob', ''));
        $ageM = null; $ageYears = null; $exact = false;
        if ($dob !== '') {
            $a = PediatricDoseHelper::exactAge($dob, $on);
            if (!$a) Response::validationError(['dob' => ['The date of birth is not valid, or is in the future.']]);
            [$ageM, $ageYears, $exact] = [$a['months'], $a['years'], true];
        } else {
            $age = $this->request->get('age_months');
            if ($age !== null && $age !== '') {
                if (!is_numeric($age) || (float) $age < 0 || (float) $age > 1560) {
                    Response::validationError(['age_months' => ['Age in months must be between 0 and 1560.']]);
                }
                $ageM = (int) floor((float) $age);
                $ageYears = round($ageM / 12, 1);
            }
        }

        $slug    = trim((string) $this->request->get('drug', ''));
        $generic = trim((string) $this->request->get('generic', ''));
        $name    = trim((string) $this->request->get('name', ''));
        if ($slug === '' && $generic === '' && $name === '') {
            Response::validationError(['generic' => ['Medicine generic name is required.']]);
        }

        $drugs = $this->pediatricDrugs();
        $drug = null;
        if ($slug !== '') {
            foreach ($drugs as $d) if ($d['slug'] === $slug) { $drug = $d; break; }
        } else {
            $drug = PediatricDoseHelper::match($drugs, $generic, $name);
        }
        if (!$drug) {
            Response::success(['found' => false], 'No pediatric reference for this medicine');
        }

        $forms = $this->db->getRows('SELECT * FROM pediatric_drug_formulations WHERE drug_id = :d ORDER BY sort_order, id', [':d' => $drug['id']]);
        $str = $this->request->get('strength');
        $per = $this->request->get('per_ml');
        $want = [
            'strength' => is_numeric($str) && (float) $str > 0 ? (float) $str : null,
            'per_ml'   => is_numeric($per) && (float) $per > 0 ? (float) $per : null,
            'form'     => (string) $this->request->get('form', ''),
            'age_exact' => $exact || $ageM === null,
        ];
        $calc = PediatricDoseHelper::calculate($drug, $forms, $w, $ageM, $want);

        Response::success([
            'found'     => true,
            'weight_kg' => $w,
            'age_months' => $ageM,
            'age_years' => $ageYears,
            'age_exact' => $exact,
            'drug'      => [
                'id' => (int) $drug['id'], 'slug' => $drug['slug'], 'name' => $drug['name'], 'category' => $drug['category'], 'route' => $drug['route'],
                'indication' => $drug['indication'], 'indications' => $drug['all_indications'], 'basis' => $drug['basis'], 'unit' => $drug['dose_unit'],
                'mg_per_kg_min' => $drug['dose_min'] === null ? null : (float) $drug['dose_min'], 'mg_per_kg_typ' => $drug['dose_typ'] === null ? null : (float) $drug['dose_typ'],
                'mg_per_kg_max' => $drug['dose_max'] === null ? null : (float) $drug['dose_max'],
                'duration' => $drug['duration'], 'notes' => $drug['notes'], 'reference' => $drug['reference'], 'verified' => (bool) $drug['verified'],
                'min_age_months' => (int) $drug['min_age_months'], 'min_weight_kg' => (float) $drug['min_weight_kg'], 'brands' => $drug['brands'],
                'renal_note' => $drug['renal_note'], 'hepatic_note' => $drug['hepatic_note'], 'neonatal_note' => $drug['neonatal_note'], 'food_note' => $drug['food_note'],
                'side_effects' => $drug['side_effects'], 'contraindications' => $drug['contraindications'], 'warnings' => $drug['warnings'], 'interactions' => $drug['interactions'],
            ],
        ] + $calc, 'Pediatric dose computed');
    }

    // ── GET /catalog/pediatric-drugs ─────────────────────────────────────────
    /** The reference list, for search / autocomplete: by drug name, brand, spelling or indication. */
    public function pediatricDrugList(): void
    {
        $this->requireDoctor();
        $q = mb_strtolower(trim((string) $this->request->get('q', '')));
        $out = [];
        foreach ($this->pediatricDrugs() as $d) {
            if ($q !== '') {
                $hay = mb_strtolower(implode(' | ', array_merge([$d['name'], $d['category'] ?? '', $d['indication'] ?? ''], $d['aliases'], $d['all_indications'])));
                if (mb_strpos($hay, $q) === false) continue;
            }
            $out[] = ['slug' => $d['slug'], 'name' => $d['name'], 'category' => $d['category'], 'indication' => $d['indication'], 'brands' => $d['brands'], 'verified' => (bool) $d['verified']];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        Response::success(['items' => array_slice($out, 0, 60), 'total' => count($out)], 'Pediatric reference fetched');
    }

    /** Every active reference drug with its JSON columns decoded and its aliases attached. */
    private function pediatricDrugs(): array
    {
        $rows = $this->db->getRows('SELECT * FROM pediatric_drugs WHERE is_active = 1 ORDER BY name');
        $aliases = [];
        foreach ($this->db->getRows('SELECT drug_id, alias FROM pediatric_drug_aliases') as $a) $aliases[(int) $a['drug_id']][] = $a['alias'];
        $json = fn ($v) => ($v === null || $v === '') ? [] : (json_decode((string) $v, true) ?: []);
        foreach ($rows as &$r) {
            foreach (['all_indications', 'side_effects', 'contraindications', 'warnings', 'interactions', 'brands', 'bands'] as $c) $r[$c] = $json($r[$c]);
            $r['aliases'] = $aliases[(int) $r['id']] ?? [];
        }
        unset($r);
        return $rows;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    /** The doctor's most prescribed medicines, each with how they last prescribed it. */
    /** Browse the catalogue for one form (medicine_categories.id) when no search text is typed yet. */
    private function browseCategory(int $doctorId, int $cat, int $limit, string $form = ''): array
    {
        $liquid = $cat === self::LIQUID_CAT ? self::liquidFormSql($form) : '';
        $rows = $this->db->getRows(
            'SELECT m.id, m.name, m.generic_name, m.manufacturer, m.strength, m.default_unit, m.category_id, c.name AS category_name
             FROM medicines m JOIN medicine_categories c ON c.id = m.category_id
             WHERE m.is_active = 1 AND m.category_id = :cat' . $liquid . ' AND (m.doctor_id IS NULL OR m.doctor_id = :d)
             ORDER BY (m.doctor_id IS NOT NULL) DESC, m.name LIMIT ' . (int) $limit,
            [':cat' => $cat, ':d' => $doctorId]
        );
        $last = $this->lastUsed($doctorId, array_column($rows, 'name'));
        return array_map(fn ($m) => [
            'medicine_id'   => (int) $m['id'],
            'name'          => $m['name'],
            'generic_name'  => $m['generic_name'],
            'manufacturer'  => $m['manufacturer'],
            'strength'      => $m['strength'],
            'unit'          => $m['default_unit'],
            'category_id'   => (int) $m['category_id'],
            'category_name' => $m['category_name'],
            'last_used'     => $last[mb_strtolower($m['name'])] ?? null,
        ], $rows);
    }

    private function frequentlyUsed(int $doctorId, int $limit): array
    {
        $top = $this->db->getRows(
            "SELECT LOWER(pm.medicine_name) AS k, COUNT(*) c, MAX(p.visit_date) last_at
             FROM prescription_medicines pm JOIN prescriptions p ON p.id = pm.prescription_id
             WHERE p.doctor_id = :d AND p.deleted_at IS NULL AND p.status = 'final' AND pm.deleted_at IS NULL" . self::byDoctor('p.consultant_id', $this->actorId) . "
             GROUP BY LOWER(pm.medicine_name) ORDER BY c DESC, last_at DESC LIMIT " . (int) $limit,
            [':d' => $doctorId]
        );
        if (!$top) return [];

        $last = $this->lastUsed($doctorId, array_column($top, 'k'));
        $out  = [];
        foreach ($top as $t) {
            $lu = $last[$t['k']] ?? null;
            if (!$lu) continue;
            $m = $lu['medicine_id'] ? $this->db->getRow(
                'SELECT m.*, c.name AS category_name FROM medicines m JOIN medicine_categories c ON c.id = m.category_id WHERE m.id = :i',
                [':i' => $lu['medicine_id']]
            ) : null;
            $out[] = [
                'medicine_id'   => $m ? (int) $m['id'] : null,
                'name'          => $lu['medicine_name'],
                'generic_name'  => $m['generic_name'] ?? null,
                'manufacturer'  => $m['manufacturer'] ?? null,
                'strength'      => $m['strength'] ?? null,
                'unit'          => $lu['qty_unit'] ?: ($m['default_unit'] ?? 'piece'),
                'category_id'   => $m ? (int) $m['category_id'] : null,
                'category_name' => $m['category_name'] ?? null,
                'times_used'    => (int) $t['c'],
                'last_used'     => $lu,
            ];
        }
        return $out;
    }

    /** Latest final-prescription settings per medicine name (lower-cased key). */
    private function lastUsed(int $doctorId, array $names): array
    {
        $names = array_values(array_unique(array_map('mb_strtolower', array_filter($names))));
        if (!$names) return [];
        $in   = implode(',', array_fill(0, count($names), '?'));
        $rows = $this->db->getRows(
            "SELECT pm.* FROM prescription_medicines pm
             JOIN prescriptions p ON p.id = pm.prescription_id
             WHERE p.doctor_id = ? AND p.deleted_at IS NULL AND p.status = 'final' AND pm.deleted_at IS NULL" . self::byDoctor('p.consultant_id', $this->actorId) . "
               AND LOWER(pm.medicine_name) IN ($in)
             ORDER BY p.visit_date DESC, pm.id DESC",
            array_merge([$doctorId], $names)
        );
        $out = [];
        foreach ($rows as $r) {
            $k = mb_strtolower($r['medicine_name']);
            if (isset($out[$k])) continue;
            $out[$k] = [
                'medicine_id'   => $r['medicine_id'] === null ? null : (int) $r['medicine_id'],
                'medicine_name' => $r['medicine_name'],
                'qty_unit'      => $r['qty_unit'],
                'dose_schedule' => json_decode((string) $r['dose_schedule'], true) ?: [],
                'freq_display'  => $r['freq_display'],
                'duration_days' => $r['duration_days'] === null ? null : (int) $r['duration_days'],
                'duration_note' => $r['duration_note'],
                'instructions'  => $r['instructions'],
            ];
        }
        return $out;
    }
}
