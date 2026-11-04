-- Device push tokens (FCM) for the "call patient in" announcement (Module 4.4).
-- On call-in, the backend pushes a notification to the clinic's devices so the
-- receptionist is alerted instantly even with the app backgrounded. Idempotent.

CREATE TABLE IF NOT EXISTS `device_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `owner_type` enum('doctor','staff') NOT NULL,
  `owner_id` int unsigned NOT NULL COMMENT 'doctors.id or clinic_staff.id',
  `doctor_id` int unsigned NOT NULL COMMENT 'clinic owner, for addressing a clinic''s devices',
  `token` varchar(512) NOT NULL COMMENT 'FCM registration token',
  `platform` varchar(16) NOT NULL DEFAULT 'android',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`(191)),
  KEY `idx_doctor` (`doctor_id`),
  KEY `idx_owner` (`owner_type`, `owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
