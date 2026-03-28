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

-- NOTE: We do NOT modify nodes.id type here. The live server has a
-- 'deployments' table with fk_deployments_node referencing nodes.id.
-- Changing the PK type requires dropping all dependent FKs first,
-- which is a separate deliberate operation. The ADD COLUMN fixes
-- below are sufficient to restore portal functionality.

ALTER TABLE nodes
    ADD COLUMN IF NOT EXISTS customer_id INT UNSIGNED NULL;

ALTER TABLE nodes
    ADD COLUMN IF NOT EXISTS rack_unit_start SMALLINT UNSIGNED NULL;

ALTER TABLE nodes
    ADD COLUMN IF NOT EXISTS rack_unit_size  TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER rack_unit_start;

ALTER TABLE nodes
    ADD COLUMN IF NOT EXISTS site            VARCHAR(50)  NULL;

ALTER TABLE nodes
    ADD COLUMN IF NOT EXISTS last_seen_at    DATETIME     NULL;

ALTER TABLE nodes
    ADD COLUMN IF NOT EXISTS provider        VARCHAR(50)  NULL;

ALTER TABLE nodes
    ADD COLUMN IF NOT EXISTS mgmt_ip         VARCHAR(45)  NULL;

ALTER TABLE nodes
    ADD COLUMN IF NOT EXISTS notes           TEXT         NULL;

ALTER TABLE nodes
    ADD COLUMN IF NOT EXISTS updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- ============================================================
--  users: add last_login_at if missing
--  (login_handler.php updates this column on every login)
-- ============================================================

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL;

-- ============================================================
--  automation_runs: add meta column if missing
--  (scripts.php line 98 uses r.meta->>'$.script.script_id')
-- ============================================================

ALTER TABLE automation_runs
    ADD COLUMN IF NOT EXISTS meta LONGTEXT NULL;

-- ============================================================
--  racks: add columns expected by hardware.php, rack.php
--  datacenter_id: virtual alias for site_id if it exists,
--  otherwise a real nullable column (populated by DC CRUD)
-- ============================================================

DROP PROCEDURE IF EXISTS sp_fix_racks_dc_id;

DELIMITER //
CREATE PROCEDURE sp_fix_racks_dc_id()
BEGIN
    DECLARE v INT DEFAULT 0;
    SELECT COUNT(*) INTO v FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'racks' AND COLUMN_NAME = 'site_id';
    IF v > 0 THEN
        SET @s = 'ALTER TABLE racks ADD COLUMN IF NOT EXISTS datacenter_id BIGINT UNSIGNED GENERATED ALWAYS AS (site_id) VIRTUAL';
    ELSE
        SET @s = 'ALTER TABLE racks ADD COLUMN IF NOT EXISTS datacenter_id BIGINT UNSIGNED NULL';
    END IF;
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END //
DELIMITER ;

CALL sp_fix_racks_dc_id();
DROP PROCEDURE IF EXISTS sp_fix_racks_dc_id;

ALTER TABLE racks ADD COLUMN IF NOT EXISTS total_units    TINYINT UNSIGNED NOT NULL DEFAULT 42;
ALTER TABLE racks ADD COLUMN IF NOT EXISTS row_label      VARCHAR(50)  NULL;
ALTER TABLE racks ADD COLUMN IF NOT EXISTS position       VARCHAR(50)  NULL;
ALTER TABLE racks ADD COLUMN IF NOT EXISTS rack_height_mm SMALLINT UNSIGNED NULL;
ALTER TABLE racks ADD COLUMN IF NOT EXISTS power_amps     TINYINT UNSIGNED NULL;
ALTER TABLE racks ADD COLUMN IF NOT EXISTS status         VARCHAR(20)  NOT NULL DEFAULT 'active';
ALTER TABLE racks ADD COLUMN IF NOT EXISTS notes          TEXT         NULL;
ALTER TABLE racks ADD COLUMN IF NOT EXISTS updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- ============================================================
--  automations: add schedule_cron
-- ============================================================

ALTER TABLE automations
    ADD COLUMN IF NOT EXISTS schedule_cron VARCHAR(100) NULL AFTER trigger_type;

-- ============================================================
--  automation_runs: add worker/locking + error + initiated cols
-- ============================================================

ALTER TABLE automation_runs
    ADD COLUMN IF NOT EXISTS locked_by            VARCHAR(100) NULL;

ALTER TABLE automation_runs
    ADD COLUMN IF NOT EXISTS locked_at            DATETIME NULL AFTER locked_by;

ALTER TABLE automation_runs
    ADD COLUMN IF NOT EXISTS initiated_via        VARCHAR(30) NULL;

ALTER TABLE automation_runs
    ADD COLUMN IF NOT EXISTS initiated_by_user_id INT UNSIGNED NULL AFTER initiated_via;

ALTER TABLE automation_runs
    ADD COLUMN IF NOT EXISTS error_code           VARCHAR(50) NULL AFTER initiated_by_user_id;

ALTER TABLE automation_runs
    ADD COLUMN IF NOT EXISTS error_message        TEXT NULL AFTER error_code;

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
--  customers: create standalone table if it does not exist
--  (must exist before the FK below is added)
-- ============================================================

CREATE TABLE IF NOT EXISTS customers (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name            VARCHAR(150)  NOT NULL,
    account_status  VARCHAR(20)   NOT NULL DEFAULT 'active',
    service_level   VARCHAR(50)   NULL,
    company_type    VARCHAR(50)   NULL,
    contact_name    VARCHAR(100)  NULL,
    contact_email   VARCHAR(150)  NULL,
    contact_phone   VARCHAR(50)   NULL,
    address         TEXT          NULL,
    city            VARCHAR(100)  NULL,
    state           VARCHAR(100)  NULL,
    country         VARCHAR(100)  NULL,
    account_number  VARCHAR(50)   NULL,
    mrr_cents       INT UNSIGNED  NULL,
    contract_start  DATE          NULL,
    contract_end    DATE          NULL,
    notes           TEXT          NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  FK constraints are intentionally omitted from this migration.
--  The application enforces referential integrity at the query
--  level. Adding FK constraints to an existing live database
--  with accumulated data is fragile (type mismatches, orphaned
--  rows, naming conflicts). If you want FK enforcement, add them
--  manually after verifying data consistency.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  Phase 2: datacenters — replace VIEW with a real TABLE
--
--  Earlier migrations created a VIEW over the live 'sites' table
--  as a read-only bridge. Now that the portal has CRUD for data
--  centers we need a writable TABLE. This procedure:
--    1. If 'datacenters' is currently a VIEW  → drops it
--    2. If 'datacenters' is a BASE TABLE      → leaves it alone
--    3. If 'datacenters' does not exist       → creates it
-- ============================================================

DROP PROCEDURE IF EXISTS sp_phase2_datacenters;

DELIMITER //
CREATE PROCEDURE sp_phase2_datacenters()
BEGIN
    DECLARE v_type VARCHAR(20) DEFAULT NULL;
    SELECT TABLE_TYPE INTO v_type
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'datacenters';

    IF v_type = 'VIEW' THEN
        DROP VIEW datacenters;
        SET v_type = NULL;   -- fall through to CREATE TABLE
    END IF;

    IF v_type IS NULL THEN
        CREATE TABLE datacenters (
            id                INT UNSIGNED     NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name              VARCHAR(100)     NOT NULL,
            code              VARCHAR(20)          NULL,
            location          VARCHAR(200)         NULL,
            address           TEXT                 NULL,
            city              VARCHAR(100)         NULL,
            state             VARCHAR(100)         NULL,
            country           VARCHAR(100)         NULL,
            contact_name      VARCHAR(100)         NULL,
            contact_phone     VARCHAR(50)          NULL,
            contact_email     VARCHAR(150)         NULL,
            power_capacity_kw SMALLINT UNSIGNED    NULL,
            total_sqft        INT UNSIGNED         NULL,
            status            VARCHAR(20)      NOT NULL DEFAULT 'active',
            notes             TEXT                 NULL,
            created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    END IF;
END //
DELIMITER ;

CALL sp_phase2_datacenters();
DROP PROCEDURE IF EXISTS sp_phase2_datacenters;

-- ============================================================
--  Phase 2: racks.datacenter_id + nodes.datacenter_id
--
--  These may be VIRTUAL columns (GENERATED ALWAYS AS site_id)
--  from the earlier bridge migration. Replace with real nullable
--  INT columns so INSERT/UPDATE operations work correctly.
-- ============================================================

DROP PROCEDURE IF EXISTS sp_fix_dc_id_col;

DELIMITER //
CREATE PROCEDURE sp_fix_dc_id_col(IN p_tbl VARCHAR(64))
BEGIN
    DECLARE v_extra VARCHAR(200) DEFAULT NULL;
    SELECT EXTRA INTO v_extra
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = p_tbl
      AND COLUMN_NAME  = 'datacenter_id';

    IF v_extra IS NULL THEN
        -- column absent — add as real nullable INT
        SET @s = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN datacenter_id INT UNSIGNED NULL');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    ELSEIF v_extra LIKE '%VIRTUAL%' OR v_extra LIKE '%STORED%' THEN
        -- generated column — drop and re-add as real column
        SET @d = CONCAT('ALTER TABLE `', p_tbl, '` DROP COLUMN datacenter_id');
        SET @a = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN datacenter_id INT UNSIGNED NULL');
        PREPARE st FROM @d; EXECUTE st; DEALLOCATE PREPARE st;
        PREPARE st FROM @a; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
    -- already a real column — leave it alone
END //
DELIMITER ;

CALL sp_fix_dc_id_col('racks');
CALL sp_fix_dc_id_col('nodes');
DROP PROCEDURE IF EXISTS sp_fix_dc_id_col;

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
