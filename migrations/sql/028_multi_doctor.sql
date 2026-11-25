-- Several doctors in one clinic.
-- The clinic owner's doctor id stays the tenant key (`doctor_id` on every record), so no existing data
-- is rewritten. Extra doctors point at the owner; each visit, prescription, order and bill records the
-- doctor who treated it (`consultant_id`). Safe to run again.
ALTER TABLE doctors
  ADD COLUMN IF NOT EXISTS clinic_owner_id BIGINT UNSIGNED NULL AFTER id,
  ADD COLUMN IF NOT EXISTS sessions_valid_from DATETIME NULL;
ALTER TABLE doctors ADD INDEX IF NOT EXISTS idx_doctors_owner (clinic_owner_id);

ALTER TABLE appointments     ADD COLUMN IF NOT EXISTS consultant_id BIGINT UNSIGNED NULL AFTER doctor_id;
ALTER TABLE prescriptions    ADD COLUMN IF NOT EXISTS consultant_id BIGINT UNSIGNED NULL AFTER doctor_id;
ALTER TABLE lab_orders       ADD COLUMN IF NOT EXISTS consultant_id BIGINT UNSIGNED NULL AFTER doctor_id;
ALTER TABLE radiology_orders ADD COLUMN IF NOT EXISTS consultant_id BIGINT UNSIGNED NULL AFTER doctor_id;
ALTER TABLE bills            ADD COLUMN IF NOT EXISTS consultant_id BIGINT UNSIGNED NULL AFTER doctor_id;

UPDATE appointments     SET consultant_id = doctor_id WHERE consultant_id IS NULL;
UPDATE prescriptions    SET consultant_id = doctor_id WHERE consultant_id IS NULL;
UPDATE lab_orders       SET consultant_id = doctor_id WHERE consultant_id IS NULL;
UPDATE radiology_orders SET consultant_id = doctor_id WHERE consultant_id IS NULL;
UPDATE bills            SET consultant_id = doctor_id WHERE consultant_id IS NULL;

ALTER TABLE appointments     ADD INDEX IF NOT EXISTS idx_appt_consultant (doctor_id, consultant_id, scheduled_at);
ALTER TABLE prescriptions    ADD INDEX IF NOT EXISTS idx_rx_consultant (doctor_id, consultant_id, visit_date);
ALTER TABLE lab_orders       ADD INDEX IF NOT EXISTS idx_lab_consultant (doctor_id, consultant_id);
ALTER TABLE radiology_orders ADD INDEX IF NOT EXISTS idx_rad_consultant (doctor_id, consultant_id);
ALTER TABLE bills            ADD INDEX IF NOT EXISTS idx_bill_consultant (doctor_id, consultant_id, bill_date);
