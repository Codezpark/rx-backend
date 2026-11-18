-- Globalisation, step 8 (country layer): a clinic can print its tax / VAT registration number on receipts
-- (TRN in the UAE, VAT number in Saudi Arabia / Oman / Bahrain / Egypt, TIN in Nigeria / Ghana / Sri Lanka, GSTIN in India ...),
-- and a patient can carry a national ID number where one is customary (Emirates ID, Iqama, Qatar ID, Civil ID, NIN, Ghana Card ...).
-- Both are optional free text; the app decides which label to show for the clinic's country. Idempotent - safe to re-run.

ALTER TABLE `clinics`  ADD COLUMN IF NOT EXISTS `tax_number`  varchar(40) DEFAULT NULL COMMENT 'Tax / VAT registration number printed on receipts';
ALTER TABLE `patients` ADD COLUMN IF NOT EXISTS `national_id` varchar(40) DEFAULT NULL COMMENT 'National ID number (optional, per country)';
