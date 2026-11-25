-- Columnar results (test, value, unit, reference range, flag) read from an attached lab report.
ALTER TABLE lab_orders ADD COLUMN IF NOT EXISTS ocr_results MEDIUMTEXT NULL AFTER ocr_rows;
