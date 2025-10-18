-- Complete Database Schema for Golden Dice Bot
-- Import this file to create all tables from scratch

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- =============================================
-- 1. Users Table
-- =============================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT NOT NULL PRIMARY KEY,
  `username` VARCHAR(255) DEFAULT NULL,
  `first_name` VARCHAR(255) DEFAULT NULL,
  `last_name` VARCHAR(255) DEFAULT NULL,
  `coins` INT NOT NULL DEFAULT 1000,
  `wallet_address` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_username` (`username`),
  INDEX `idx_coins` (`coins`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 2. Admin Users Table
-- =============================================
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 3. Settings Table
-- =============================================
CREATE TABLE IF NOT EXISTS `settings` (
  `key` VARCHAR(255) NOT NULL PRIMARY KEY,
  `value` TEXT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO `settings` (`key`, `value`) VALUES
('openai_model', 'gpt-4o-mini'),
('start_coins', '1000'),
('withdraw_min_balance', '1001'),
('deposit_wallet_address', ''),
('sleep_ms_between_rolls', '4500'),
('quiet_hours_start', '23:00'),
('quiet_hours_end', '00:00'),
('score_match_3', '10'),
('score_match_5', '15'),
('score_all_unordered', '30'),
('score_exact_ordered', '10000'),
('game_start_cost', '0'),
('roll_cost', '0'),
('log_requests', 'false'),
('timezone', 'UTC'),
('quiet_hours_active', '0')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- =============================================
-- 4. Golden Numbers Table
-- =============================================
CREATE TABLE IF NOT EXISTS `golden_numbers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `number` VARCHAR(7) NOT NULL,
  `source` VARCHAR(50) DEFAULT 'openai',
  `announced` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 5. Game Sessions Table
-- =============================================
CREATE TABLE IF NOT EXISTS `game_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `golden_id` INT NOT NULL,
  `rolls_count` INT NOT NULL DEFAULT 0,
  `throws_remaining` INT NOT NULL DEFAULT 7,
  `result_digits` VARCHAR(7) DEFAULT '',
  `finished` TINYINT(1) NOT NULL DEFAULT 0,
  `paused` TINYINT(1) NOT NULL DEFAULT 0,
  `paused_at` TIMESTAMP NULL DEFAULT NULL,
  `score_awarded` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_golden_id` (`golden_id`),
  INDEX `idx_finished` (`finished`),
  INDEX `idx_user_finished` (`user_id`, `finished`),
  INDEX `idx_paused` (`paused`),
  CONSTRAINT `fk_game_sessions_golden` 
    FOREIGN KEY (`golden_id`) 
    REFERENCES `golden_numbers`(`id`) 
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 6. Rolls Table
-- =============================================
CREATE TABLE IF NOT EXISTS `rolls` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT NOT NULL,
  `user_id` BIGINT NOT NULL,
  `result` INT NOT NULL,
  `step_index` INT NOT NULL,
  `cost` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_session_id` (`session_id`),
  INDEX `idx_user_id` (`user_id`),
  CONSTRAINT `fk_rolls_session` 
    FOREIGN KEY (`session_id`) 
    REFERENCES `game_sessions`(`id`) 
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 7. Transactions Table (NEW)
-- =============================================
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `type` ENUM('deposit', 'withdraw', 'win', 'loss', 'bonus', 'refund') NOT NULL,
  `amount` INT NOT NULL DEFAULT 0,
  `golden_id` INT NULL,
  `session_id` INT NULL,
  `description` VARCHAR(255) NULL,
  `status` ENUM('pending', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_type` (`type`),
  INDEX `idx_golden_id` (`golden_id`),
  INDEX `idx_session_id` (`session_id`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_status` (`status`),
  CONSTRAINT `fk_transactions_golden` 
    FOREIGN KEY (`golden_id`) 
    REFERENCES `golden_numbers`(`id`) 
    ON DELETE CASCADE,
  CONSTRAINT `fk_transactions_session` 
    FOREIGN KEY (`session_id`) 
    REFERENCES `game_sessions`(`id`) 
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 8. Withdraw Requests Table (Legacy - kept for backup)
-- =============================================
CREATE TABLE IF NOT EXISTS `withdraw_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `amount` INT NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected', 'success', 'failed') DEFAULT 'pending',
  `api_response` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Migrate existing withdraw_requests to transactions
-- =============================================
INSERT INTO `transactions` (`user_id`, `type`, `amount`, `status`, `created_at`)
SELECT 
  `user_id`, 
  'withdraw' as `type`, 
  `amount`, 
  CASE 
    WHEN `status` = 'approved' THEN 'completed'
    WHEN `status` = 'success' THEN 'completed'
    WHEN `status` = 'rejected' THEN 'failed'
    WHEN `status` = 'failed' THEN 'failed'
    ELSE 'pending'
  END as `status`,
  `created_at`
FROM `withdraw_requests`
WHERE NOT EXISTS (
  SELECT 1 FROM `transactions` t 
  WHERE t.user_id = `withdraw_requests`.user_id 
  AND t.type = 'withdraw' 
  AND t.created_at = `withdraw_requests`.created_at
);

COMMIT;

-- =============================================
-- Verification Queries (Run these to check)
-- =============================================

-- Show all tables
-- SHOW TABLES;

-- Verify transactions table structure
-- DESCRIBE transactions;

-- Verify foreign keys
-- SELECT 
--   TABLE_NAME,
--   COLUMN_NAME,
--   CONSTRAINT_NAME,
--   REFERENCED_TABLE_NAME,
--   REFERENCED_COLUMN_NAME
-- FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
-- WHERE TABLE_SCHEMA = DATABASE()
-- AND TABLE_NAME IN ('transactions', 'game_sessions', 'rolls');

-- Check if data migrated
-- SELECT COUNT(*) as total_transactions FROM transactions;
-- SELECT type, status, COUNT(*) as count FROM transactions GROUP BY type, status;
