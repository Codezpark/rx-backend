-- The "short_code is unique" assumption in 010 never held (004 had no unique key), so ten codes
-- were seeded twice (CRP, ESR, B12 ...) and panels ordered the test twice. Merge, then enforce.
UPDATE lab_order_tests t
  JOIN lab_tests_catalog d ON d.id = t.test_id
  JOIN (SELECT short_code, MIN(id) keep_id FROM lab_tests_catalog GROUP BY short_code) k ON k.short_code = d.short_code
   SET t.test_id = k.keep_id
 WHERE d.id <> k.keep_id;
DELETE d FROM lab_tests_catalog d
  JOIN (SELECT short_code, MIN(id) keep_id FROM lab_tests_catalog GROUP BY short_code) k ON k.short_code = d.short_code
 WHERE d.id <> k.keep_id;
ALTER TABLE lab_tests_catalog ADD UNIQUE KEY uq_lab_short_code (short_code);
