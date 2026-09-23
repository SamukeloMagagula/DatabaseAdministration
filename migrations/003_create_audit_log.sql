CREATE TABLE IF NOT EXISTS {{app_schema}}.audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_user_id INT UNSIGNED NULL,
    username VARCHAR(64) NOT NULL,
    action_type VARCHAR(32) NOT NULL,
    target_db VARCHAR(64) NULL,
    target_table VARCHAR(64) NULL,
    detail TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
