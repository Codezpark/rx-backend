-- Step 11 (Module 2): radiology study catalogue for autocomplete + a few more lab tests. Safe to re-run.

CREATE TABLE IF NOT EXISTS `radiology_catalog` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `modality` enum('x-ray','ultrasound','ct_scan','mri','ecg','echo','other') NOT NULL DEFAULT 'other',
  `body_part` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rad_name` (`name`),
  KEY `idx_rad_modality` (`modality`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `radiology_catalog` (`name`, `modality`, `body_part`) VALUES
  ('X-Ray Chest PA', 'x-ray', 'Chest'),
  ('X-Ray Chest AP', 'x-ray', 'Chest'),
  ('X-Ray Abdomen (Erect)', 'x-ray', 'Abdomen'),
  ('X-Ray KUB', 'x-ray', 'KUB'),
  ('X-Ray Spine (LS)', 'x-ray', 'Spine (LS)'),
  ('X-Ray Spine (Cervical)', 'x-ray', 'Spine (Cervical)'),
  ('X-Ray Knee', 'x-ray', 'Knee'),
  ('X-Ray Hand / Wrist', 'x-ray', 'Hand / Wrist'),
  ('X-Ray Foot / Ankle', 'x-ray', 'Foot / Ankle'),
  ('X-Ray Pelvis', 'x-ray', 'Pelvis'),
  ('X-Ray Skull / Sinuses', 'x-ray', 'Skull / Sinuses'),
  ('Mammography', 'x-ray', 'Breast'),
  ('HSG (Hysterosalpingography)', 'x-ray', 'Pelvis'),
  ('USG Abdomen', 'ultrasound', 'Abdomen'),
  ('USG Whole Abdomen', 'ultrasound', 'Abdomen'),
  ('USG Pelvis', 'ultrasound', 'Pelvis'),
  ('USG KUB', 'ultrasound', 'KUB'),
  ('USG Obstetric', 'ultrasound', 'Obstetric'),
  ('USG Thyroid / Neck', 'ultrasound', 'Thyroid / Neck'),
  ('USG Breast', 'ultrasound', 'Breast'),
  ('USG Scrotum', 'ultrasound', 'Scrotum'),
  ('CT Head (Plain)', 'ct_scan', 'Head'),
  ('CT Chest', 'ct_scan', 'Chest'),
  ('CT Abdomen', 'ct_scan', 'Abdomen'),
  ('CT KUB', 'ct_scan', 'KUB'),
  ('CT Spine', 'ct_scan', 'Spine'),
  ('MRI Brain', 'mri', 'Brain'),
  ('MRI Spine (LS)', 'mri', 'Spine (LS)'),
  ('MRI Knee', 'mri', 'Knee'),
  ('MRI Shoulder', 'mri', 'Shoulder'),
  ('ECG', 'ecg', NULL),
  ('2D Echo', 'echo', NULL);

-- A few more everyday lab tests (short_code is unique, so IGNORE skips existing rows).
INSERT IGNORE INTO `lab_tests_catalog` (`name`, `short_code`, `category`, `normal_range`, `unit`, `is_active`) VALUES
  ('Vitamin B12', 'B12', 'Biochemistry', '200–900', 'pg/mL', 1),
  ('Serum Ferritin', 'FERR', 'Biochemistry', '30–400', 'ng/mL', 1),
  ('Erythrocyte Sedimentation Rate', 'ESR', 'Haematology', '0–20', 'mm/hr', 1),
  ('C-Reactive Protein', 'CRP', 'Serology', '<6', 'mg/L', 1),
  ('Serum Uric Acid', 'URIC', 'Biochemistry', '3.5–7.2', 'mg/dL', 1),
  ('Serum Calcium', 'CA', 'Biochemistry', '8.5–10.5', 'mg/dL', 1),
  ('Serum Magnesium', 'MG', 'Biochemistry', '1.7–2.2', 'mg/dL', 1),
  ('Serum Amylase', 'AMYL', 'Biochemistry', '25–125', 'U/L', 1),
  ('Serum Lipase', 'LIPA', 'Biochemistry', '10–140', 'U/L', 1),
  ('Prothrombin Time / INR', 'PTINR', 'Haematology', 'INR 0.8–1.2', '', 1),
  ('Vitamin D (25-OH)', 'VITD', 'Biochemistry', '30–100', 'ng/mL', 1),
  ('HbA1c', 'HBA1C', 'Biochemistry', '<5.7', '%', 1),
  ('Blood Urea Nitrogen', 'BUN', 'Biochemistry', '7–20', 'mg/dL', 1);
