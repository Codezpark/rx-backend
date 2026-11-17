-- Medicolegal audit trail: remember when a prescribed dose came from the pediatric calculator, and what it
-- suggested (weight, age, rule, result) so the record shows how the dose was arrived at. Idempotent.

ALTER TABLE `prescription_medicines`
  ADD COLUMN IF NOT EXISTS `dose_auto_calculated` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = the dose was generated / applied by the pediatric calculator' AFTER `instructions`,
  ADD COLUMN IF NOT EXISTS `dose_calc_note` varchar(600) DEFAULT NULL COMMENT 'What the calculator suggested, and whether the doctor changed it' AFTER `dose_auto_calculated`;
