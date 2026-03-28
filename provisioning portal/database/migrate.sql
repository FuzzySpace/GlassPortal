-- ============================================================
--  Glasshouse NOC Portal - Live Schema Migration
--
--  Run this against an EXISTING database to bring it up to
--  date with the current schema without dropping any data.
--
--  Safe to run multiple times - all statements use
--  IF NOT EXISTS where supported.
--
--  mysql -u root -p provisioning_portal < migrate.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

USE provisioning_portal;

-- ============================================================
--  nodes: add customer_id, rack placement columns
-- ============================================================

ALTER TABLE nodes
    MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE nodes
    ADD COLUMN IF NOT EXISTS customer_id INT UNSIGNED NULL AFTER role;

ALTER TABLE nodes
    ADD COLUMN IF NOT EXISTS rack_unit_start SMALLINT UNSIGNED NULL AFTER rack_id;

ALTER TABLE nodes
    ADD COLUMN IF NOT EXISTS rack_unit_size TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER rack_unit_start;

-- ============================================================
--  automations: add schedule_cron
-- ============================================================

ALTER TABLE automations
    ADD COLUMN IF NOT EXISTS schedule_cron VARCHAR(100) NULL AFTER trigger_type;

-- ============================================================
--  automation_runs: add worker/locking + error + initiated cols
-- ============================================================

ALTER TABLE automation_runs
    ADD COLUMN IF NOT EXISTS locked_by            VARCHAR(100) NULL AFTER meta;

ALTER TABLE automation_runs
    ADD COLUMN IF NOT EXISTS locked_at            DATETIME NULL AFTER locked_by;

ALTER TABLE automation_runs
    ADD COLUMN IF NOT EXISTS initiated_via        VARCHAR(30) NULL AFTER duration_ms;

ALTER TABLE automation_runs
    ADD COLUMN IF NOT EXISTS initiated_by_user_id INT UNSIGNED NULL AFTER initiated_via;

ALTER TABLE automation_runs
    ADD COLUMN IF NOT EXISTS error_code           VARCHAR(50) NULL AFTER initiated_by_user_id;

ALTER TABLE automation_runs
    ADD COLUMN IF NOT EXISTS error_message        TEXT NULL AFTER error_code;

-- ============================================================
--  automation_run_logs: ensure run_id is NOT NULL
-- ============================================================

ALTER TABLE automation_run_logs
    MODIFY COLUMN run_id INT UNSIGNED NOT NULL;

-- ============================================================
--  ansible_scripts: add missing columns if table existed early
-- ============================================================

ALTER TABLE ansible_scripts
    ADD COLUMN IF NOT EXISTS script_type        VARCHAR(30)       NOT NULL DEFAULT 'playbook' AFTER description;

ALTER TABLE ansible_scripts
    ADD COLUMN IF NOT EXISTS category           VARCHAR(50)       NULL AFTER script_type;

ALTER TABLE ansible_scripts
    ADD COLUMN IF NOT EXISTS extra_vars         LONGTEXT          NULL AFTER command;

ALTER TABLE ansible_scripts
    ADD COLUMN IF NOT EXISTS tags               VARCHAR(255)      NULL AFTER extra_vars;

ALTER TABLE ansible_scripts
    ADD COLUMN IF NOT EXISTS timeout_seconds    SMALLINT UNSIGNED NOT NULL DEFAULT 3600 AFTER tags;

ALTER TABLE ansible_scripts
    ADD COLUMN IF NOT EXISTS requires_sudo      TINYINT UNSIGNED  NOT NULL DEFAULT 0 AFTER timeout_seconds;

ALTER TABLE ansible_scripts
    ADD COLUMN IF NOT EXISTS created_by_user_id INT UNSIGNED      NULL AFTER is_active;

ALTER TABLE ansible_scripts
    ADD COLUMN IF NOT EXISTS updated_at         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- ============================================================
--  audit_logs: replace JSON with LONGTEXT if needed
-- ============================================================

ALTER TABLE audit_logs
    MODIFY COLUMN meta LONGTEXT NULL;

-- ============================================================
--  automation_runs: replace JSON with LONGTEXT if needed
-- ============================================================

ALTER TABLE automation_runs
    MODIFY COLUMN meta LONGTEXT NULL;

-- ============================================================
--  automation_run_logs: replace JSON with LONGTEXT if needed
-- ============================================================

ALTER TABLE automation_run_logs
    MODIFY COLUMN context LONGTEXT NULL;

-- ============================================================
--  ansible_scripts: replace JSON with LONGTEXT if needed
-- ============================================================

ALTER TABLE ansible_scripts
    MODIFY COLUMN extra_vars LONGTEXT NULL;

-- ============================================================
--  Add missing FK constraints (ignore errors if they exist)
-- ============================================================

-- nodes.customer_id -> customers
ALTER TABLE nodes
    ADD CONSTRAINT fk_nodes_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL;

-- automation_runs.initiated_by_user_id -> users
ALTER TABLE automation_runs
    ADD CONSTRAINT fk_runs_initiator
        FOREIGN KEY (initiated_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

-- ansible_scripts.created_by_user_id -> users
ALTER TABLE ansible_scripts
    ADD CONSTRAINT fk_scripts_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  Verify: show all tables and row counts
-- ============================================================

SELECT
    TABLE_NAME,
    TABLE_ROWS,
    ENGINE,
    TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'provisioning_portal'
ORDER BY TABLE_NAME;
