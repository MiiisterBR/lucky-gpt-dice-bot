SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS withdraw_requests;
DROP TABLE IF EXISTS rolls;
DROP TABLE IF EXISTS game_sessions;
DROP TABLE IF EXISTS guesses;
DROP TABLE IF EXISTS golden_numbers;
DROP TABLE IF EXISTS admin_users;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS settings;

SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE settings (
  `key` VARCHAR(100) PRIMARY KEY,
  `value` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED PRIMARY KEY,
  username VARCHAR(255),
  first_name VARCHAR(255),
  last_name VARCHAR(255),
  coins INT NOT NULL DEFAULT 1000,
  wallet_address VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wallet_address (wallet_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE golden_numbers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  generated_at DATETIME NOT NULL,
  number CHAR(7) NOT NULL,
  valid_date DATE NOT NULL,
  source VARCHAR(50) DEFAULT 'openai',
  announced TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE game_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  golden_id BIGINT UNSIGNED NOT NULL,
  result_digits CHAR(7) DEFAULT NULL,
  rolls_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  finished TINYINT(1) NOT NULL DEFAULT 0,
  score_awarded INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sessions_golden FOREIGN KEY (golden_id) REFERENCES golden_numbers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rolls (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  result TINYINT UNSIGNED NOT NULL,
  step_index TINYINT UNSIGNED NOT NULL,
  cost INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rolls_session FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_rolls_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE withdraw_requests (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  amount INT NOT NULL,
  status ENUM('pending','success','failed') DEFAULT 'pending',
  api_response TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_withdraw_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`, `value`) VALUES
('openai_model', 'gpt-5'),
('start_coins', '1000'),
('withdraw_min_balance', '1001'),
('deposit_wallet_address', ''),
('sleep_ms_between_rolls', '3000'),
('quiet_hours_start', '23:00'),
('quiet_hours_end', '00:00'),
('score_match_3', '10'),
('score_match_5', '15'),
('score_all_unordered', '30'),
('score_exact_ordered', '10000'),
('dice_cost', '0'),
('log_requests', 'false'),
('timezone', 'UTC')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
