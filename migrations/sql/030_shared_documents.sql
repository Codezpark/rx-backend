-- PDFs a clinic has sent to a patient as a link (prescription, lab report, test request, receipt).
-- One row per document: sending it again replaces the file and keeps the link, unless the old link
-- was switched off. The patient confirms the last 4 digits of their mobile number to open it.
CREATE TABLE IF NOT EXISTS shared_documents (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  doctor_id       BIGINT UNSIGNED NOT NULL,
  shared_by       BIGINT UNSIGNED NULL,
  patient_id      BIGINT UNSIGNED NOT NULL,
  kind            VARCHAR(20) NOT NULL,
  ref_id          BIGINT UNSIGNED NOT NULL,
  token           CHAR(22) NOT NULL,
  file_path       VARCHAR(255) NOT NULL,
  file_name       VARCHAR(160) NOT NULL,
  phone_last4     CHAR(4) NOT NULL,
  source_hash     CHAR(40) NULL,
  expires_at      DATETIME NOT NULL,
  revoked_at      DATETIME NULL,
  failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME NULL,
  views           INT UNSIGNED NOT NULL DEFAULT 0,
  last_viewed_at  DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shared_token (token),
  UNIQUE KEY uq_shared_source (doctor_id, kind, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
