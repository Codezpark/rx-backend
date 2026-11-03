-- Step 10 (Module 1): medicine form prefix on prescription lines + a pediatric
-- weight-based dose reference. Safe to re-run.

-- 1.1 — store the chosen form/prefix (TAB, CAP, SYR, INJ…) on each prescription line.
ALTER TABLE `prescription_medicines`
  ADD COLUMN IF NOT EXISTS `form_prefix` varchar(12) DEFAULT NULL COMMENT 'TAB, CAP, SYR, LIQ, SUS, INJ, CRM, DROP, DRESS…' AFTER `category_id`;

-- 1.3 — curated pediatric mg/kg/day reference (advisory only; verify against a formulary).
CREATE TABLE IF NOT EXISTS `pediatric_dose_reference` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `generic_name` varchar(120) NOT NULL,
  `mg_per_kg_day_min` decimal(6,2) NOT NULL,
  `mg_per_kg_day_max` decimal(6,2) NOT NULL,
  `default_divisions` tinyint(3) unsigned NOT NULL DEFAULT 3 COMMENT 'doses per day',
  `max_mg_per_day` int(10) unsigned DEFAULT NULL,
  `common_syrup_mg_per_5ml` decimal(7,2) DEFAULT NULL,
  `notes` varchar(200) DEFAULT NULL,
  `source` varchar(120) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ped_generic` (`generic_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pediatric_dose_reference`
  (`generic_name`, `mg_per_kg_day_min`, `mg_per_kg_day_max`, `default_divisions`, `max_mg_per_day`, `common_syrup_mg_per_5ml`, `notes`, `source`)
VALUES
  ('Paracetamol', 40, 60, 4, 2000, 250, '10–15 mg/kg/dose q4–6h', 'Advisory — verify with a pediatric formulary'),
  ('Ibuprofen', 20, 30, 3, 1200, 100, '5–10 mg/kg/dose q6–8h, with food', 'Advisory — verify with a pediatric formulary'),
  ('Amoxicillin', 25, 45, 3, 1500, 250, 'High-dose (otitis) up to 80–90 mg/kg/day', 'Advisory — verify with a pediatric formulary'),
  ('Amoxicillin+Clavulanic Acid', 25, 45, 2, 1500, 228.5, 'Dose by the amoxicillin component', 'Advisory — verify with a pediatric formulary'),
  ('Cefixime', 8, 8, 2, 400, 100, 'Zifi dry syrup 50/100 mg per 5 ml', 'Advisory — verify with a pediatric formulary'),
  ('Azithromycin', 10, 10, 1, 500, 200, 'Once daily, 3–5 days', 'Advisory — verify with a pediatric formulary'),
  ('Cefpodoxime', 10, 10, 2, 400, 50, 'Twice daily', 'Advisory — verify with a pediatric formulary'),
  ('Cefuroxime', 20, 30, 2, 1000, 125, 'Twice daily, with food', 'Advisory — verify with a pediatric formulary'),
  ('Cephalexin', 25, 50, 3, 2000, 125, 'Three to four divided doses', 'Advisory — verify with a pediatric formulary'),
  ('Metronidazole', 20, 30, 3, 1000, 200, 'Three divided doses', 'Advisory — verify with a pediatric formulary')
ON DUPLICATE KEY UPDATE
  mg_per_kg_day_min = VALUES(mg_per_kg_day_min),
  mg_per_kg_day_max = VALUES(mg_per_kg_day_max),
  default_divisions = VALUES(default_divisions),
  max_mg_per_day = VALUES(max_mg_per_day),
  common_syrup_mg_per_5ml = VALUES(common_syrup_mg_per_5ml),
  notes = VALUES(notes),
  source = VALUES(source),
  is_active = 1;
