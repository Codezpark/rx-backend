-- Pediatric dosing reference, v2: one row per drug (dose basis, caps, age / weight limits, safety text),
-- its formulations (liquid strengths per ml, tablet strengths) and the names it is known by.
-- Replaces the sparse `pediatric_dose_reference` table, which is left in place but no longer read.
-- Idempotent - safe to re-run. The data itself is 017_seed_pediatric_drugs.sql.

CREATE TABLE IF NOT EXISTS `pediatric_drugs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(60) NOT NULL,
  `name` varchar(120) NOT NULL,
  `components` varchar(200) NOT NULL COMMENT 'canonical active ingredient(s), "+"-joined, sorted',
  `category` varchar(80) DEFAULT NULL,
  `route` varchar(20) NOT NULL DEFAULT 'Oral',
  `indication` varchar(200) DEFAULT NULL,
  `all_indications` text DEFAULT NULL COMMENT 'JSON array',
  `dose_unit` varchar(8) NOT NULL DEFAULT 'mg' COMMENT 'mg, mcg, IU or ml',
  `basis` enum('per_kg_day','per_kg_dose','band') NOT NULL,
  `dose_min` decimal(9,3) DEFAULT NULL COMMENT 'per kg per day, or per kg per dose, by basis',
  `dose_typ` decimal(9,3) DEFAULT NULL,
  `dose_max` decimal(9,3) DEFAULT NULL,
  `doses_per_day` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `frequency_hours` tinyint(3) unsigned DEFAULT NULL,
  `max_doses_per_day` tinyint(3) unsigned DEFAULT NULL,
  `max_single` decimal(10,3) DEFAULT NULL COMMENT 'cap on one dose, in dose_unit',
  `max_daily` decimal(10,3) DEFAULT NULL COMMENT 'cap on the day, in dose_unit',
  `min_age_months` smallint(5) unsigned NOT NULL DEFAULT 0,
  `max_age_months` smallint(5) unsigned NOT NULL DEFAULT 216,
  `min_weight_kg` decimal(5,2) NOT NULL DEFAULT 0,
  `bands` text DEFAULT NULL COMMENT 'JSON: fixed doses by age (months) or weight (kg) when basis = band',
  `duration` varchar(120) DEFAULT NULL,
  `renal_note` varchar(255) DEFAULT NULL,
  `hepatic_note` varchar(255) DEFAULT NULL,
  `neonatal_note` varchar(255) DEFAULT NULL,
  `food_note` varchar(160) DEFAULT NULL,
  `side_effects` text DEFAULT NULL,
  `contraindications` text DEFAULT NULL,
  `warnings` text DEFAULT NULL,
  `interactions` text DEFAULT NULL,
  `brands` text DEFAULT NULL,
  `reference` varchar(160) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = confirmed by the clinic; 0 = standard ranges awaiting review',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pdrug_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pediatric_drug_formulations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `drug_id` int(10) unsigned NOT NULL,
  `form_type` enum('syrup','drops','tablet','capsule','dispersible','chewable','mouth-dissolving','sachet','suppository') NOT NULL,
  `strength` decimal(12,3) NOT NULL COMMENT 'of the DOSING component, per per_volume_ml (liquids) or per unit (solids)',
  `per_volume_ml` decimal(6,2) DEFAULT NULL COMMENT 'liquids only: 5 for "x mg/5 ml", 1 for "x mg/ml"',
  `strength_total` decimal(12,3) DEFAULT NULL COMMENT 'label strength when it includes other components (e.g. 228.5 for 200 + 28.5)',
  `label` varchar(120) NOT NULL,
  `note` varchar(160) DEFAULT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_pform_drug` (`drug_id`),
  CONSTRAINT `fk_pform_drug` FOREIGN KEY (`drug_id`) REFERENCES `pediatric_drugs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pediatric_drug_aliases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `drug_id` int(10) unsigned NOT NULL,
  `alias` varchar(120) NOT NULL COMMENT 'lower case: another spelling of the generic, or a brand',
  PRIMARY KEY (`id`),
  KEY `idx_palias_drug` (`drug_id`),
  KEY `idx_palias_alias` (`alias`),
  CONSTRAINT `fk_palias_drug` FOREIGN KEY (`drug_id`) REFERENCES `pediatric_drugs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
