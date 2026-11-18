-- Globalisation, step 1: each clinic chooses its own language and currency (and country, which sets sensible
-- defaults). Money columns get 3 decimals so OMR / KWD / BHD amounts are exact; the application rounds to the
-- currency's own number of decimals (2 for most, 3 for those three). Existing rupee amounts are unchanged.
-- Idempotent - safe to re-run.

ALTER TABLE `clinics`
  ADD COLUMN IF NOT EXISTS `language` varchar(8) NOT NULL DEFAULT 'en' COMMENT 'App language for this clinic (doctor and reception)',
  ADD COLUMN IF NOT EXISTS `currency` char(3) NOT NULL DEFAULT 'INR' COMMENT 'ISO 4217 currency of the amounts on bills and fees',
  ADD COLUMN IF NOT EXISTS `country` char(2) NOT NULL DEFAULT 'IN' COMMENT 'ISO 3166 country of the clinic';

ALTER TABLE `bills`          MODIFY `subtotal` decimal(12,3) NOT NULL DEFAULT 0.000, MODIFY `discount` decimal(12,3) NOT NULL DEFAULT 0.000,
                             MODIFY `total` decimal(12,3) NOT NULL DEFAULT 0.000, MODIFY `paid` decimal(12,3) NOT NULL DEFAULT 0.000;
ALTER TABLE `bill_items`     MODIFY `amount` decimal(12,3) NOT NULL;
ALTER TABLE `bill_payments`  MODIFY `amount` decimal(12,3) NOT NULL;
ALTER TABLE `clinics`        MODIFY `consultation_fee` decimal(12,3) DEFAULT NULL, MODIFY `fee_new` decimal(12,3) DEFAULT NULL,
                             MODIFY `fee_old` decimal(12,3) DEFAULT NULL, MODIFY `fee_emergency` decimal(12,3) DEFAULT NULL;
ALTER TABLE `lab_orders`     MODIFY `estimated_cost` decimal(12,3) DEFAULT NULL, MODIFY `actual_cost` decimal(12,3) DEFAULT NULL;
