CREATE DATABASE IF NOT EXISTS HeraProduction CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS HeraTesting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS vas_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON HeraProduction.* TO 'vas_user'@'%';
GRANT ALL PRIVILEGES ON HeraTesting.* TO 'vas_user'@'%';
GRANT ALL PRIVILEGES ON vas_portal.* TO 'vas_user'@'%';
FLUSH PRIVILEGES;

USE vas_portal;

CREATE TABLE IF NOT EXISTS portal_users (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','operator','viewer') NOT NULL DEFAULT 'viewer',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO portal_users (full_name, username, password_hash, role, status)
VALUES ('System Administrator', 'admin', '$2y$12$36THbWOafxnZWHeHkqOj8OTvvx8VoKfXq4IAbTOSlP9iVgnIdjH92', 'admin', 'active')
ON DUPLICATE KEY UPDATE username = username;

CREATE TABLE IF NOT EXISTS portal_audit_trail (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  username VARCHAR(80) NULL,
  action VARCHAR(80) NOT NULL,
  schema_name VARCHAR(80) NULL,
  target_table VARCHAR(120) NULL,
  target_key VARCHAR(500) NULL,
  ip_address VARCHAR(80) NULL,
  user_agent VARCHAR(255) NULL,
  details MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_action_created (action, created_at),
  INDEX idx_schema_target (schema_name, target_table, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saved_queries (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  schema_name VARCHAR(80) NOT NULL,
  sql_text MEDIUMTEXT NOT NULL,
  created_by VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_projects (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_name VARCHAR(180) NOT NULL,
  owner_name VARCHAR(150) NULL,
  status ENUM('active','testing','paused','completed') NOT NULL DEFAULT 'active',
  environment ENUM('Both','HeraTesting','HeraProduction') NOT NULL DEFAULT 'Both',
  short_code VARCHAR(80) NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_project_status (status),
  INDEX idx_project_short_code (short_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_short_codes (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  short_code VARCHAR(80) NOT NULL,
  service_name VARCHAR(180) NOT NULL,
  provider VARCHAR(150) NULL,
  status ENUM('active','testing','disabled','retired') NOT NULL DEFAULT 'active',
  environment ENUM('Both','HeraTesting','HeraProduction') NOT NULL DEFAULT 'Both',
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_short_code_service (short_code, service_name),
  INDEX idx_shortcode_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
