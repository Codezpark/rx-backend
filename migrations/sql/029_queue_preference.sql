-- Clinic preference: tick "add to today's queue" by default on the new-patient form.
ALTER TABLE clinics ADD COLUMN IF NOT EXISTS queue_new_patients TINYINT(1) NOT NULL DEFAULT 0;
