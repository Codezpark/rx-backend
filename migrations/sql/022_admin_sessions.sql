-- Admin panel: force-logout for admin accounts (reset password / switch off). Idempotent.
ALTER TABLE `admins` ADD COLUMN IF NOT EXISTS `sessions_valid_from` datetime DEFAULT NULL COMMENT 'Tokens issued before this instant are rejected';
