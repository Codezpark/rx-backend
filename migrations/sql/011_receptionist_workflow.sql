-- Step 10 (Module 4): receptionist intake, fee tiers, queue "not available" + call announcement.
-- Idempotent — safe to re-run (IF NOT EXISTS guards; the enum MODIFY is a no-op if already applied).

-- 4.1 Vitals captured by the receptionist at intake, handed to the doctor's Rx editor.
CREATE TABLE IF NOT EXISTS `intake_vitals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `doctor_id` bigint(20) unsigned NOT NULL,
  `appointment_id` bigint(20) unsigned DEFAULT NULL,
  `patient_id` bigint(20) unsigned NOT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `bp_systolic` smallint(5) unsigned DEFAULT NULL,
  `bp_diastolic` smallint(5) unsigned DEFAULT NULL,
  `pulse_bpm` smallint(5) unsigned DEFAULT NULL,
  `temperature_f` decimal(4,1) DEFAULT NULL,
  `spo2_pct` tinyint(3) unsigned DEFAULT NULL,
  `rbs_fasting_mg_dl` smallint(5) unsigned DEFAULT NULL,
  `rbs_pp_mg_dl` smallint(5) unsigned DEFAULT NULL,
  `recorded_by_staff_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_iv_appt` (`appointment_id`),
  KEY `idx_iv_patient` (`doctor_id`,`patient_id`),
  CONSTRAINT `fk_iv_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`),
  CONSTRAINT `fk_iv_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  CONSTRAINT `fk_iv_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.2 Fee tiers on the clinic (consultation_fee stays as the fallback for all tiers).
ALTER TABLE `clinics`
  ADD COLUMN IF NOT EXISTS `fee_new` decimal(10,2) DEFAULT NULL COMMENT 'New-case consultation fee' AFTER `consultation_fee`,
  ADD COLUMN IF NOT EXISTS `fee_old` decimal(10,2) DEFAULT NULL COMMENT 'Follow-up / old-case fee' AFTER `fee_new`,
  ADD COLUMN IF NOT EXISTS `fee_emergency` decimal(10,2) DEFAULT NULL COMMENT 'Emergency consultation fee' AFTER `fee_old`,
  ADD COLUMN IF NOT EXISTS `old_case_window_days` smallint(5) unsigned NOT NULL DEFAULT 30 COMMENT 'Return within N days counts as an old case' AFTER `fee_emergency`,
  ADD COLUMN IF NOT EXISTS `free_next_day` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'A return the very next day is free' AFTER `old_case_window_days`;

-- 4.4 Queue: "Not available" status, the "call next patient" timestamp, and the case tag.
ALTER TABLE `appointments`
  MODIFY COLUMN `status` enum('scheduled','confirmed','arrived','not_available','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled';
ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `called_at` datetime DEFAULT NULL COMMENT 'When the doctor last called this patient in (drives the TTS announcement)' AFTER `cancel_reason`,
  ADD COLUMN IF NOT EXISTS `case_type` enum('new','old','emergency') DEFAULT NULL COMMENT 'Visit case tag (receptionist-set or derived)' AFTER `called_at`;
