-- Transactions Table: Track all financial movements
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    type ENUM('deposit', 'withdraw', 'win', 'loss', 'bonus', 'refund') NOT NULL,
    amount INT NOT NULL DEFAULT 0,
    golden_id INT NULL,
    session_id INT NULL,
    description VARCHAR(255) NULL,
    status ENUM('pending', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_type (type),
    INDEX idx_golden_id (golden_id),
    INDEX idx_session_id (session_id),
    INDEX idx_created_at (created_at),
    
    -- Foreign key constraints with cascade delete
    CONSTRAINT fk_transactions_golden 
        FOREIGN KEY (golden_id) 
        REFERENCES golden_numbers(id) 
        ON DELETE CASCADE,
    
    CONSTRAINT fk_transactions_session 
        FOREIGN KEY (session_id) 
        REFERENCES game_sessions(id) 
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update game_sessions table to support pause/resume
ALTER TABLE game_sessions 
    ADD COLUMN IF NOT EXISTS throws_remaining INT NOT NULL DEFAULT 7 AFTER rolls_count,
    ADD COLUMN IF NOT EXISTS paused TINYINT(1) NOT NULL DEFAULT 0 AFTER finished,
    ADD COLUMN IF NOT EXISTS paused_at TIMESTAMP NULL AFTER paused;

-- Add indexes for better performance
ALTER TABLE game_sessions 
    ADD INDEX IF NOT EXISTS idx_user_finished (user_id, finished),
    ADD INDEX IF NOT EXISTS idx_paused (paused);

-- Migrate existing withdraw_requests to transactions
INSERT INTO transactions (user_id, type, amount, status, created_at)
SELECT 
    user_id, 
    'withdraw' as type, 
    amount, 
    CASE 
        WHEN status = 'approved' THEN 'completed'
        WHEN status = 'rejected' THEN 'failed'
        ELSE 'pending'
    END as status,
    created_at
FROM withdraw_requests
ON DUPLICATE KEY UPDATE id=id;

-- Note: Don't drop withdraw_requests yet, keep for backup
-- DROP TABLE IF EXISTS withdraw_requests;
