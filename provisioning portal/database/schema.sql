DROP TABLE IF EXISTS automation_run_logs;
DROP TABLE IF EXISTS automation_runs;
DROP TABLE IF EXISTS ansible_scripts;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS auth_logins;
DROP TABLE IF EXISTS auth_remember_tokens;
DROP TABLE IF EXISTS automations;
DROP TABLE IF EXISTS nodes;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'operator',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE nodes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(64) NOT NULL,
  site VARCHAR(64) NOT NULL,
  mgmt_ip VARCHAR(45) DEFAULT NULL,
  provider VARCHAR(64) DEFAULT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'unknown',
  cpu_model VARCHAR(128) DEFAULT NULL,
  cpu_cores INT DEFAULT NULL,
  ram_gb INT DEFAULT NULL,
  storage_gb INT DEFAULT NULL,
  tags JSON DEFAULT NULL,
  last_seen_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY name (name),
  KEY idx_site_status (site, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE automations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(128) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  trigger_type VARCHAR(24) NOT NULL DEFAULT 'manual',
  schedule_cron VARCHAR(64) DEFAULT NULL,
  webhook_path VARCHAR(128) DEFAULT NULL,
  owner_user_id BIGINT UNSIGNED DEFAULT NULL,
  config JSON DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_enabled (enabled),
  KEY idx_trigger (trigger_type),
  KEY idx_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ansible_scripts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(128) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  script_type VARCHAR(24) NOT NULL DEFAULT 'playbook',
  command TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_active (is_active),
  KEY idx_created_by (created_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE automation_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  automation_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'queued',
  started_at DATETIME DEFAULT NULL,
  finished_at DATETIME DEFAULT NULL,
  duration_ms BIGINT DEFAULT NULL,
  initiated_by_user_id BIGINT UNSIGNED DEFAULT NULL,
  initiated_via VARCHAR(24) NOT NULL DEFAULT 'manual',
  error_code VARCHAR(64) DEFAULT NULL,
  error_message VARCHAR(255) DEFAULT NULL,
  meta JSON DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_by VARCHAR(64) DEFAULT NULL,
  locked_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_auto_time (automation_id, created_at),
  KEY idx_status_time (status, created_at),
  KEY idx_runs_status_created (status, created_at),
  KEY idx_runs_locked (locked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE automation_run_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  level VARCHAR(16) NOT NULL DEFAULT 'info',
  message VARCHAR(255) NOT NULL,
  context JSON DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_run_time (run_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED DEFAULT NULL,
  action VARCHAR(64) NOT NULL,
  target_type VARCHAR(64) DEFAULT NULL,
  target_id VARCHAR(64) DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  meta JSON DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_actor_time (actor_user_id, created_at),
  KEY idx_action_time (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_logins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED DEFAULT NULL,
  email_attempted VARCHAR(190) NOT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  success TINYINT(1) NOT NULL,
  fail_reason VARCHAR(64) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_email_time (email_attempted, created_at),
  KEY idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_remember_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  selector CHAR(24) NOT NULL,
  validator_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME DEFAULT NULL,
  revoked_at DATETIME DEFAULT NULL,
  ip_created VARCHAR(45) DEFAULT NULL,
  user_agent_created VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY selector (selector),
  KEY idx_user (user_id),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO automations (name, description, enabled, trigger_type)
VALUES
('Run Ansible', 'Executes selected Ansible script against selected targets', 1, 'manual');

INSERT INTO nodes (name, site, mgmt_ip, provider, status, cpu_model, cpu_cores, ram_gb, storage_gb)
VALUES
('test-node-01', 'lab', '10.0.0.10', 'local', 'active', 'AMD EPYC 7402', 24, 128, 1920);

INSERT INTO ansible_scripts (name, description, script_type, command, is_active)
VALUES
('Health Check', 'Runs baseline health checks', 'playbook', 'ansible-playbook playbooks/health.yml', 1);