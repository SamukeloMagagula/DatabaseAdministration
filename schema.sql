-- This app's own tables: accounts, login throttling, and the audit trail.
--
--   mariadb dbwebui_app < schema.sql
--
-- Safe to run more than once — every statement is IF NOT EXISTS. Run it
-- against the schema named by DB_APP_SCHEMA (settings.php), not against any
-- database this app is meant to administer: connect() (config.php) opens
-- with no default database specifically so this app's own tables stay out of
-- reach of the SQL console and data grid, and applying this file to the
-- wrong schema would defeat that.

CREATE TABLE IF NOT EXISTS app_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(16) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Failed (and successful) sign-ins, for rate limiting. ratelimit.php prunes
-- nothing itself; old rows are harmless and cheap enough to leave.
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(191) NOT NULL,
    succeeded TINYINT(1) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_created (identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
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
