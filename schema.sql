-- ============================================================
-- LTM AI LABORATORY — Schema database
-- Database separato: ai_laboratory
-- ============================================================

CREATE DATABASE IF NOT EXISTS ai_laboratory
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE ai_laboratory;

-- Settings applicazione (API keys, config)
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Storico query Claude
CREATE TABLE IF NOT EXISTS queries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_name VARCHAR(100) NOT NULL,
    user_prompt TEXT NOT NULL,
    tables_selected VARCHAR(500) DEFAULT NULL,
    generated_sql TEXT DEFAULT NULL,
    interpretation TEXT DEFAULT NULL,
    model VARCHAR(50) DEFAULT NULL,
    input_tokens INT UNSIGNED DEFAULT 0,
    output_tokens INT UNSIGNED DEFAULT 0,
    cost_usd DECIMAL(10,6) DEFAULT 0,
    status ENUM('interpreted','executed','downloaded','failed','cancelled') DEFAULT 'interpreted',
    records_count INT UNSIGNED DEFAULT 0,
    file_path VARCHAR(500) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    executed_at DATETIME DEFAULT NULL,
    KEY idx_user (user_name),
    KEY idx_created (created_at),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
