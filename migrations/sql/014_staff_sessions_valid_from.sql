-- Receptionist session invalidation for remote force-logout (force_logout feature).
-- When a doctor disables a receptionist, resets their password, or removes them,
-- sessions_valid_from is bumped to "now"; access tokens issued before this instant
-- are rejected in BaseController::requireClinic(), so a still-unexpired access token
-- can no longer be used. Pairs with Auth::revokeAllForStaff() (kills refresh tokens)
-- and a silent FCM "force_logout" push (logs the device out instantly). Idempotent.

ALTER TABLE `clinic_staff`
  ADD COLUMN IF NOT EXISTS `sessions_valid_from` datetime NULL DEFAULT NULL
  COMMENT 'Access tokens issued (iat) before this instant are rejected (force-logout).'
  AFTER `is_active`;
