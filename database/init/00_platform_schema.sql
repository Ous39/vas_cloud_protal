CREATE DATABASE IF NOT EXISTS HeraProduction CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS HeraTesting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS vas_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- HeraProduction: no CREATE/ALTER — the app never issues DDL against production at
-- runtime (see run_sql()'s production-read-only guard in app/lib/bootstrap.php).
GRANT SELECT, INSERT, UPDATE ON HeraProduction.* TO 'vas_user'@'%';
GRANT SELECT, INSERT, UPDATE, CREATE, ALTER, INDEX, REFERENCES, LOCK TABLES, EXECUTE, SHOW VIEW ON HeraTesting.* TO 'vas_user'@'%';
GRANT ALL PRIVILEGES ON vas_portal.* TO 'vas_user'@'%';
FLUSH PRIVILEGES;

USE vas_portal;

CREATE TABLE IF NOT EXISTS portal_users (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','manager','operator','viewer') NOT NULL DEFAULT 'viewer',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  default_schema_name ENUM('HeraTesting','HeraProduction') NOT NULL DEFAULT 'HeraTesting',
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_role_status (role,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO portal_users (full_name, username, password_hash, role, status, default_schema_name)
VALUES ('System Administrator', 'admin', '$2y$12$36THbWOafxnZWHeHkqOj8OTvvx8VoKfXq4IAbTOSlP9iVgnIdjH92', 'admin', 'active', 'HeraTesting')
ON DUPLICATE KEY UPDATE username = username;

CREATE TABLE IF NOT EXISTS role_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_name VARCHAR(50) NOT NULL,
  permission_key VARCHAR(100) NOT NULL,
  UNIQUE KEY uq_role_perm (role_name, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions(role_name, permission_key) VALUES
('admin','view_dashboard'),('admin','view_tables'),('admin','create_records'),('admin','edit_records'),('admin','duplicate_records'),('admin','copy_records'),('admin','run_sql'),('admin','manage_users'),('admin','manage_projects'),('admin','manage_shortcodes'),('admin','view_audit'),('admin','view_reports'),
('manager','view_dashboard'),('manager','view_tables'),('manager','create_records'),('manager','edit_records'),('manager','duplicate_records'),('manager','copy_records'),('manager','run_sql'),('manager','manage_projects'),('manager','manage_shortcodes'),('manager','view_audit'),('manager','view_reports'),
('operator','view_dashboard'),('operator','view_tables'),('operator','create_records'),('operator','edit_records'),('operator','duplicate_records'),('operator','copy_records'),('operator','manage_projects'),('operator','manage_shortcodes'),('operator','view_reports'),
('viewer','view_dashboard'),('viewer','view_tables'),('viewer','view_reports');

CREATE TABLE IF NOT EXISTS portal_audit_trail (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id VARCHAR(64) NULL,
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
  INDEX idx_schema_target (schema_name, target_table, created_at),
  INDEX idx_user_created (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saved_queries (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  schema_name ENUM('HeraTesting','HeraProduction') NOT NULL DEFAULT 'HeraTesting',
  sql_text MEDIUMTEXT NOT NULL,
  category VARCHAR(80) NULL,
  created_by VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_saved_schema (schema_name, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_projects (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_name VARCHAR(180) NOT NULL,
  short_code VARCHAR(80) NULL,
  status ENUM('Planning','Development','Testing','Launched','Completed','Suspended') NOT NULL DEFAULT 'Planning',
  start_date DATE NULL,
  launch_date DATE NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_project_status (status),
  INDEX idx_project_short_code (short_code),
  INDEX idx_project_dates (start_date, launch_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_short_codes (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  channel_type ENUM('USSD','IVR') NOT NULL DEFAULT 'USSD',
  short_code VARCHAR(80) NOT NULL,
  service_name VARCHAR(180) NOT NULL,
  provider VARCHAR(150) NULL,
  status ENUM('Active','Inactive','Pending','Suspended') NOT NULL DEFAULT 'Pending',
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_channel_shortcode_service (channel_type, short_code, service_name),
  INDEX idx_channel_type (channel_type),
  INDEX idx_shortcode_status (status),
  INDEX idx_shortcode (short_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_project_channels (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  channel_id INT NOT NULL,
  short_code VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_project_channel (project_id, channel_id),
  INDEX idx_project_channel_shortcode (short_code),
  CONSTRAINT fk_project_channel_project FOREIGN KEY (project_id) REFERENCES portal_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_channel_channel FOREIGN KEY (channel_id) REFERENCES portal_short_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operation_confirmations (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  token VARCHAR(80) NOT NULL UNIQUE,
  username VARCHAR(80) NOT NULL,
  action VARCHAR(80) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  status ENUM('pending','confirmed','cancelled','expired') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at DATETIME NULL,
  INDEX idx_token_status (token,status),
  INDEX idx_user_created (username,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO portal_projects(project_name, short_code, status, start_date, launch_date, description) VALUES
('VAS Core Operations', '*123#', 'Launched', '2026-01-01', '2026-02-01', 'Core VAS self-service operations and reporting'),
('Friends and Family Number', '*141#', 'Testing', '2026-03-01', NULL, 'Friend number and resource sharing management');

INSERT IGNORE INTO portal_short_codes(channel_type, short_code, service_name, provider, status, description) VALUES
('USSD', '*123#', 'VAS Main Menu', 'Comium', 'Active', 'Main VAS self-service menu'),
('USSD', '*141#', 'Friend Number Service', 'Comium', 'Pending', 'Unique friend number and resource sharing service'),
('IVR', '141', 'Friend Number IVR Support', 'Comium', 'Pending', 'IVR support flow for friend number service');

INSERT IGNORE INTO portal_project_channels(project_id, channel_id, short_code)
SELECT p.id, c.id, c.short_code
FROM portal_projects p
JOIN portal_short_codes c ON c.short_code = p.short_code;
