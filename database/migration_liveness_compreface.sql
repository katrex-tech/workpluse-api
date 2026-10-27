-- ============================================================================
-- WorkPulse: Liveness Detection & CompreFace Face Verification Schema Migration
-- ============================================================================

-- 1. Update `users` table with CompreFace and consent columns
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `compreface_image_id` VARCHAR(64) DEFAULT NULL AFTER `face_data`,
  ADD COLUMN IF NOT EXISTS `compreface_subject` VARCHAR(64) DEFAULT NULL AFTER `compreface_image_id`,
  ADD COLUMN IF NOT EXISTS `face_consent_given` TINYINT(1) NOT NULL DEFAULT 0 AFTER `compreface_subject`,
  ADD COLUMN IF NOT EXISTS `face_consent_at` DATETIME DEFAULT NULL AFTER `face_consent_given`;

-- 2. Create `liveness_challenges` table for server-issued, single-use cryptographically secure challenges
CREATE TABLE IF NOT EXISTS `liveness_challenges` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nonce` VARCHAR(64) NOT NULL UNIQUE,
  `user_id` INT NOT NULL,
  `purpose` ENUM('enrollment', 'attendance') NOT NULL,
  `actions` TEXT NOT NULL COMMENT 'JSON array of expected LivenessAction enum names in exact order',
  `created_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `consumed_at` DATETIME DEFAULT NULL,
  INDEX `idx_nonce` (`nonce`),
  INDEX `idx_user_purpose` (`user_id`, `purpose`, `expires_at`),
  INDEX `idx_expires` (`expires_at`),
  CONSTRAINT `fk_liveness_challenges_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create `face_verification_logs` table for audit trail of liveness and facial comparisons
CREATE TABLE IF NOT EXISTS `face_verification_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `session_id` VARCHAR(64) DEFAULT NULL,
  `challenge_nonce` VARCHAR(64) DEFAULT NULL,
  `purpose` ENUM('enrollment', 'attendance_in', 'attendance_out') NOT NULL,
  `liveness_passed` TINYINT(1) NOT NULL DEFAULT 0,
  `compreface_similarity` DECIMAL(5, 4) DEFAULT NULL,
  `matched` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('success', 'failed', 'rejected') NOT NULL,
  `failure_reason` VARCHAR(255) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  INDEX `idx_user_time` (`user_id`, `created_at`),
  INDEX `idx_session` (`session_id`),
  CONSTRAINT `fk_face_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
