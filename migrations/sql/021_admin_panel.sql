-- Admin panel (back office): its own admin accounts, plus "last active" tracking on doctors.
-- Idempotent - safe to re-run.

CREATE TABLE IF NOT EXISTS `admins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(191) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `doctors` ADD COLUMN IF NOT EXISTS `last_login_at` datetime DEFAULT NULL COMMENT 'Last time the doctor signed in or the session was refreshed';
ALTER TABLE `doctors` ADD COLUMN IF NOT EXISTS `admin_note` varchar(500) DEFAULT NULL COMMENT 'Back-office note, never shown in the app';

ALTER TABLE `doctors` ADD COLUMN IF NOT EXISTS `plan_started_at` datetime DEFAULT NULL COMMENT 'When the current plan / trial began';
UPDATE `doctors` SET `plan_started_at` = `created_at` WHERE `plan_started_at` IS NULL;
