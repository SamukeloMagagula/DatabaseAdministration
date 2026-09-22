CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(191) NOT NULL,
    succeeded TINYINT(1) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_created (identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
