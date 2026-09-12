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
  target_table VARCHAR(120) NULL,
  target_key VARCHAR(500) NULL,
  ip_address VARCHAR(80) NULL,
  user_agent VARCHAR(255) NULL,
  details TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_action_created (action, created_at),
  INDEX idx_target (target_table, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
