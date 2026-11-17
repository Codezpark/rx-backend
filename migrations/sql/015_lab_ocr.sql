-- Keep what the phone read from a lab report next to the original file: the raw text and the
-- columnar rows (a JSON list of rows, each a list of cells). Idempotent — safe to re-run.

ALTER TABLE `lab_orders`
  ADD COLUMN IF NOT EXISTS `ocr_text` mediumtext DEFAULT NULL COMMENT 'Raw OCR text of the attached report' AFTER `result_file_url`,
  ADD COLUMN IF NOT EXISTS `ocr_rows` mediumtext DEFAULT NULL COMMENT 'JSON: rows of cells recovered from the report layout' AFTER `ocr_text`,
  ADD COLUMN IF NOT EXISTS `ocr_at` datetime DEFAULT NULL COMMENT 'When the OCR data was stored (UTC)' AFTER `ocr_rows`;
