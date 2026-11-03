-- Step 9 (Module 5.1): split blood sugar into fasting (FBS) + post-prandial (PPBS).
-- The legacy single `rbs_mg_dl` column is kept for historical records. Safe to re-run.
ALTER TABLE `prescriptions`
  ADD COLUMN IF NOT EXISTS `rbs_fasting_mg_dl` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'Fasting Blood Sugar (FBS) mg/dL' AFTER `rbs_mg_dl`,
  ADD COLUMN IF NOT EXISTS `rbs_pp_mg_dl` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'Post-prandial Blood Sugar (PPBS) mg/dL' AFTER `rbs_fasting_mg_dl`;
