-- Trial-ending reminders: one row per (doctor, stage) so each reminder is sent once. Idempotent.
CREATE TABLE IF NOT EXISTS `plan_reminders` (
  `doctor_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(10) NOT NULL COMMENT 'd14 | d3 | ended',
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `pushed` int NOT NULL DEFAULT 0 COMMENT 'devices the push reached',
  PRIMARY KEY (`doctor_id`, `kind`),
  CONSTRAINT `fk_reminder_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
