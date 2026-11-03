-- Remove throw-away test doctors (and all their data), keeping the demo doctors 19, 23, 27.
-- Timestamps are stored in UTC by the app, so @cut is a UTC datetime. Adjust it to the
-- start of your test run. The default below targets the Phase-A verification run on
-- 2026-11-02 (doctor ids ~203–222, created 21:23–21:26 UTC).
--
-- Run:  /Applications/XAMPP/xamppfiles/bin/mysql -uroot php_rest_api < tests/cleanup_test_doctors.sql
--
-- Review first:  SELECT id, name, created_at FROM doctors WHERE created_at >= '2026-11-02 21:20:00';

SET @cut := '2026-11-02 21:20:00';

SELECT COUNT(*) AS targets_before, MIN(id) AS min_id, MAX(id) AS max_id
FROM doctors WHERE created_at >= @cut AND id NOT IN (19, 23, 27);

SET FOREIGN_KEY_CHECKS = 0;
CREATE TEMPORARY TABLE _td (id BIGINT UNSIGNED PRIMARY KEY);
INSERT INTO _td SELECT id FROM doctors WHERE created_at >= @cut AND id NOT IN (19, 23, 27);

DELETE FROM prescription_medicines WHERE prescription_id IN (SELECT id FROM prescriptions WHERE doctor_id IN (SELECT id FROM _td));
DELETE FROM prescription_symptoms  WHERE prescription_id IN (SELECT id FROM prescriptions WHERE doctor_id IN (SELECT id FROM _td));
DELETE FROM prescription_advice    WHERE prescription_id IN (SELECT id FROM prescriptions WHERE doctor_id IN (SELECT id FROM _td));
DELETE FROM lab_order_tests WHERE lab_order_id IN (SELECT id FROM lab_orders WHERE doctor_id IN (SELECT id FROM _td));
DELETE FROM bill_items WHERE bill_id IN (SELECT id FROM bills WHERE doctor_id IN (SELECT id FROM _td));
DELETE FROM clinic_contacts WHERE clinic_id IN (SELECT id FROM clinics WHERE doctor_id IN (SELECT id FROM _td));
DELETE FROM clinic_timings  WHERE clinic_id IN (SELECT id FROM clinics WHERE doctor_id IN (SELECT id FROM _td));
DELETE FROM staff_tokens WHERE staff_id IN (SELECT id FROM clinic_staff WHERE doctor_id IN (SELECT id FROM _td));
DELETE FROM patient_chronic_conditions WHERE patient_id IN (SELECT id FROM patients WHERE doctor_id IN (SELECT id FROM _td));

DELETE FROM bill_payments     WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM bills             WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM lab_orders        WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM radiology_orders  WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM appointments      WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM prescriptions     WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM clinic_staff      WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM clinic_holidays   WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM clinics           WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM followup_actions  WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM auth_tokens       WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM wa_notifications  WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM verification      WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM rate_limits       WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM medicines         WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM symptoms          WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM advice_templates  WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM chronic_conditions WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM lab_partners      WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM patients          WHERE doctor_id IN (SELECT id FROM _td);
DELETE FROM doctors           WHERE id IN (SELECT id FROM _td);

DROP TEMPORARY TABLE _td;
SET FOREIGN_KEY_CHECKS = 1;

SELECT COUNT(*) AS targets_after FROM doctors WHERE created_at >= @cut AND id NOT IN (19, 23, 27);
SELECT COUNT(*) AS keepers_intact FROM doctors WHERE id IN (19, 23, 27);
