-- Clinic logo for the prescription letterhead (shown top-left; a default mark is used when none).
-- Idempotent — safe to re-run.

ALTER TABLE `clinics`
  ADD COLUMN IF NOT EXISTS `logo_url` varchar(255) DEFAULT NULL COMMENT 'Relative path to the clinic logo under storage/, shown on the Rx letterhead' AFTER `timings`;
