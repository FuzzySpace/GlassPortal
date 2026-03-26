-- ============================================================
--  Glasshouse NOC Provisioning Portal — Database Schema
--  All-in-one management: hosting, MSP, hardware tracking
--
--  FRESH INSTALL:
--    1. Open MySQL / phpMyAdmin / HeidiSQL / MySQL Workbench
--    2. Run this file as root (or any user with CREATE privilege)
--    That's it — database, user, tables, and seed data are all here.
--
--  XAMPP / WAMP default root has no password.
--  Command line: mysql -u root < schema.sql
-- ============================================================

-- Create database
CREATE DATABASE IF NOT EXISTS provisioning_portal
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE provisioning_portal;

-- Create a restricted application user (safe for production)
-- If you prefer to use root locally, skip this and update config.php.
CREATE USER IF NOT EXISTS 'portal_user'@'localhost'    IDENTIFIED BY 'PortalStrongPass!ChangeMe';
CREATE USER IF NOT EXISTS 'portal_user'@'127.0.0.1'   IDENTIFIED BY 'PortalStrongPass!ChangeMe';
GRANT SELECT, INSERT, UPDATE, DELETE
    ON provisioning_portal.* TO 'portal_user'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE
    ON provisioning_portal.* TO 'portal_user'@'127.0.0.1';
FLUSH PRIVILEGES;

-- ============================================================
--  Infrastructure: Data Centers
-- ============================================================

CREATE TABLE IF NOT EXISTS datacenters (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(100) NOT NULL,
    code              VARCHAR(20)  NULL,
    location          VARCHAR(200) NULL,
    address           TEXT NULL,
    city              VARCHAR(100) NULL,
    state             VARCHAR(100) NULL,
    country           VARCHAR(100) NULL,
    contact_name      VARCHAR(100) NULL,
    contact_phone     VARCHAR(50)  NULL,
    contact_email     VARCHAR(150) NULL,
    power_capacity_kw SMALLINT UNSIGNED NULL,
    total_sqft        INT UNSIGNED NULL,
    status            VARCHAR(20) DEFAULT 'active',
    notes             TEXT NULL,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
--  Infrastructure: Racks
-- ============================================================

CREATE TABLE IF NOT EXISTS racks (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    datacenter_id   INT NOT NULL,
    name            VARCHAR(100) NOT NULL,
    row_label       VARCHAR(50)  NULL,
    position        VARCHAR(50)  NULL,
    total_units     TINYINT UNSIGNED DEFAULT 42,
    rack_height_mm  SMALLINT UNSIGNED NULL,
    power_amps      TINYINT UNSIGNED NULL,
    status          VARCHAR(20) DEFAULT 'active',
    notes           TEXT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (datacenter_id) REFERENCES datacenters(id) ON DELETE CASCADE
);

-- ============================================================
--  Customer / Tenant Registry
-- ============================================================

CREATE TABLE IF NOT EXISTS customers (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150) NOT NULL,
    company_type   VARCHAR(50)  NULL,
    contact_name   VARCHAR(100) NULL,
    contact_email  VARCHAR(150) NULL,
    contact_phone  VARCHAR(50)  NULL,
    address        TEXT NULL,
    city           VARCHAR(100) NULL,
    state          VARCHAR(100) NULL,
    country        VARCHAR(100) NULL,
    account_number VARCHAR(50)  NULL,
    account_status VARCHAR(20) DEFAULT 'active',
    service_level  VARCHAR(50)  NULL,
    mrr_cents      INT UNSIGNED NULL,
    contract_start DATE NULL,
    contract_end   DATE NULL,
    notes          TEXT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
--  Server / Node Inventory
-- ============================================================

CREATE TABLE IF NOT EXISTS nodes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    site            VARCHAR(50)  NULL,
    provider        VARCHAR(50)  NULL,
    mgmt_ip         VARCHAR(45)  NULL,
    status          VARCHAR(20) DEFAULT 'unknown',
    -- Physical placement
    datacenter_id   INT NULL,
    rack_id         INT NULL,
    rack_unit_start INT NULL,
    rack_unit_size  INT DEFAULT 1,
    -- Asset details
    asset_tag       VARCHAR(100) NULL,
    serial_number   VARCHAR(100) NULL,
    make            VARCHAR(100) NULL,
    model           VARCHAR(100) NULL,
    cpu_model       VARCHAR(150) NULL,
    cpu_cores       TINYINT UNSIGNED NULL,
    ram_gb          SMALLINT UNSIGNED NULL,
    storage_gb      INT UNSIGNED NULL,
    os_type         VARCHAR(50)  NULL,
    os_version      VARCHAR(100) NULL,
    role            VARCHAR(50)  NULL,
    -- Ownership
    customer_id     INT NULL,
    -- Health
    last_seen_at    DATETIME NULL,
    notes           TEXT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (datacenter_id) REFERENCES datacenters(id) ON DELETE SET NULL,
    FOREIGN KEY (rack_id)       REFERENCES racks(id)       ON DELETE SET NULL,
    FOREIGN KEY (customer_id)   REFERENCES customers(id)   ON DELETE SET NULL
);

-- ============================================================
--  Auth & Audit
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(30) DEFAULT 'operator',   -- owner|admin|operator|security
    is_active     TINYINT DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS auth_logins (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NULL,
    email_attempted VARCHAR(150) NULL,
    ip              VARCHAR(45) NULL,
    user_agent      TEXT NULL,
    success         TINYINT DEFAULT 0,
    fail_reason     VARCHAR(100) NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auth_logins_ip      (ip),
    INDEX idx_auth_logins_user_id (user_id)
);

CREATE TABLE IF NOT EXISTS auth_remember_tokens (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    user_id            INT NOT NULL,
    selector           VARCHAR(32) NOT NULL UNIQUE,
    validator_hash     VARCHAR(255) NOT NULL,
    expires_at         DATETIME NOT NULL,
    ip_created         VARCHAR(45) NULL,
    user_agent_created TEXT NULL,
    revoked_at         DATETIME NULL,
    last_used_at       DATETIME NULL,
    created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_remember_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS access_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NULL,
    email       VARCHAR(150) NULL,
    action      VARCHAR(50) NOT NULL,
    ip_address  VARCHAR(45) NULL,
    user_agent  TEXT NULL,
    success     TINYINT DEFAULT 1,
    reason      VARCHAR(255) NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    action        VARCHAR(100) NOT NULL,
    target_type   VARCHAR(50)  NULL,
    target_id     INT NULL,
    meta          JSON NULL,
    ip            VARCHAR(45)  NULL,
    user_agent    TEXT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
--  Automation Engine
-- ============================================================

CREATE TABLE IF NOT EXISTS automations (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    description   TEXT,
    enabled       TINYINT DEFAULT 1,
    trigger_type  VARCHAR(20)  NULL,
    schedule_cron VARCHAR(100) NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS automation_runs (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    automation_id       INT,
    status              VARCHAR(20) DEFAULT 'queued',   -- queued|running|success|failed|canceled
    meta                JSON,
    locked_by           VARCHAR(100) NULL,
    locked_at           DATETIME NULL,
    started_at          DATETIME NULL,
    finished_at         DATETIME NULL,
    duration_ms         INT UNSIGNED NULL,
    initiated_via       VARCHAR(30)  NULL,              -- web|api|schedule|worker
    initiated_by_user_id INT NULL,
    error_code          VARCHAR(50)  NULL,
    error_message       TEXT NULL,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (automation_id)          REFERENCES automations(id) ON DELETE SET NULL,
    FOREIGN KEY (initiated_by_user_id)   REFERENCES users(id)       ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS automation_run_logs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    run_id     INT NOT NULL,
    level      VARCHAR(20),
    message    TEXT,
    context    JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (run_id) REFERENCES automation_runs(id) ON DELETE CASCADE
);

-- ============================================================
--  Ansible Script Library
-- ============================================================

CREATE TABLE IF NOT EXISTS ansible_scripts (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    name               VARCHAR(150) NOT NULL,
    description        TEXT NULL,
    script_type        VARCHAR(30) DEFAULT 'playbook',  -- playbook|adhoc|role
    category           VARCHAR(50)  NULL,               -- provisioning|hardening|patching|monitoring|custom
    command            TEXT NOT NULL,
    extra_vars         JSON NULL,
    tags               VARCHAR(255) NULL,
    timeout_seconds    SMALLINT UNSIGNED DEFAULT 3600,
    requires_sudo      TINYINT DEFAULT 0,
    is_active          TINYINT DEFAULT 1,
    created_by_user_id INT NULL,
    created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
--  Provisioning Tasks & CIS Hardening Tracking
-- ============================================================

CREATE TABLE IF NOT EXISTS provisioning_tasks (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    category    VARCHAR(50) NOT NULL,   -- hardware|os|network|security|cis|monitoring
    step_order  TINYINT UNSIGNED NOT NULL,
    name        VARCHAR(150) NOT NULL,
    description TEXT NULL,
    is_required TINYINT DEFAULT 1,
    script_id   INT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (script_id) REFERENCES ansible_scripts(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS server_provisioning (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    node_id      INT NOT NULL,
    started_by   INT NULL,
    status       VARCHAR(20) DEFAULT 'in_progress',   -- in_progress|complete|abandoned
    notes        TEXT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    FOREIGN KEY (node_id)    REFERENCES nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (started_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS provisioning_step_log (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    provisioning_id      INT NOT NULL,
    task_id              INT NULL,
    task_name            VARCHAR(150) NOT NULL,
    category             VARCHAR(50)  NOT NULL,
    status               VARCHAR(20) DEFAULT 'pending',   -- pending|pass|fail|skip|na
    automation_run_id    INT NULL,
    notes                TEXT NULL,
    completed_by_user_id INT NULL,
    completed_at         DATETIME NULL,
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (provisioning_id)      REFERENCES server_provisioning(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id)              REFERENCES provisioning_tasks(id)  ON DELETE SET NULL,
    FOREIGN KEY (completed_by_user_id) REFERENCES users(id)               ON DELETE SET NULL,
    FOREIGN KEY (automation_run_id)    REFERENCES automation_runs(id)     ON DELETE SET NULL
);

-- ============================================================
--  Default provisioning tasks (CIS + standard build steps)
--  Uses INSERT IGNORE so re-running this file is safe.
-- ============================================================

INSERT IGNORE INTO provisioning_tasks (id, category, step_order, name, description, is_required) VALUES
-- Hardware verification
(1,  'hardware', 1,  'Verify physical installation',       'Confirm server is correctly racked, cabled (power + network), and POST completes without errors.', 1),
(2,  'hardware', 2,  'BIOS/UEFI baseline config',          'Set boot order, enable VT-x/AMD-V, disable unused ports (USB/COM), set BIOS password, sync time.', 1),
(3,  'hardware', 3,  'IPMI / iDRAC / iLO setup',           'Configure OOB management IP, credentials, alert destinations, and firmware update.', 1),
(4,  'hardware', 4,  'Asset tag & serial recorded',         'Scan/enter asset tag and serial number into the portal.', 1),
-- OS provisioning
(5,  'os',       10, 'OS installation',                    'Install base OS from approved image (Ubuntu LTS / RHEL / Debian). Verify integrity hash.', 1),
(6,  'os',       11, 'Hostname & timezone',                 'Set FQDN hostname, configure correct timezone and NTP sources.', 1),
(7,  'os',       12, 'Network interfaces configured',      'Set static IPs, bonding/teaming, VLAN tagging. Verify routing table and DNS resolution.', 1),
(8,  'os',       13, 'SSH keypair provisioned',             'Deploy ops team SSH keys. Disable password auth. Test access.', 1),
(9,  'os',       14, 'Package updates applied',             'Run full system update (apt upgrade / dnf update). Reboot if kernel updated.', 1),
-- Network / Connectivity
(10, 'network',  20, 'Firewall rules applied',              'Configure host-based firewall (ufw/firewalld). Allow only required ports. Deny all else.', 1),
(11, 'network',  21, 'Monitoring agent installed',          'Deploy Prometheus node_exporter or equivalent. Verify scrape endpoint reachable.', 1),
(12, 'network',  22, 'Connectivity test to all services',   'Confirm reach to: DNS, NTP, SMTP relay, monitoring, logging, backup endpoints.', 1),
-- CIS Level 1 Hardening
(13, 'cis',      30, 'CIS 1.1 — Filesystem config',        'Disable cramfs, freevxfs, jffs2, hfs, hfsplus, squashfs, udf. Set /tmp nodev,nosuid,noexec.', 1),
(14, 'cis',      31, 'CIS 1.2 — Software updates',         'Verify package manager GPG keys. Enable automatic security updates.', 1),
(15, 'cis',      32, 'CIS 2.1 — Remove inetd services',    'Disable/remove chargen, daytime, echo, discard, time, tftp, xinetd.', 1),
(16, 'cis',      33, 'CIS 2.2 — Special purpose services', 'Disable X Windows, Avahi, CUPS, DHCP, LDAP, NFS, DNS, FTP, HTTP, IMAP/POP3 unless required.', 1),
(17, 'cis',      34, 'CIS 3.1 — Network parameters',       'Disable IP forwarding, redirects, source routing. Enable reverse path filtering, SYN cookies.', 1),
(18, 'cis',      35, 'CIS 3.2 — IPv6 params',              'Disable IPv6 router/redirect acceptance. Disable IPv6 if not in use.', 1),
(19, 'cis',      36, 'CIS 4.1 — Logging configured',       'Configure rsyslog/journald. Ensure remote syslog forwarded. Log rotation set.', 1),
(20, 'cis',      37, 'CIS 4.2 — Auditd rules',             'Install auditd. Configure rules: logins, sudo, file changes, privileged commands, cron.', 1),
(21, 'cis',      38, 'CIS 5.1 — Cron access control',      'Restrict cron/at to authorised users. Verify /etc/cron.allow and /etc/at.allow.', 1),
(22, 'cis',      39, 'CIS 5.2 — SSH server config',        'Protocol 2 only, MaxAuthTries ≤4, PermitRootLogin no, PermitEmptyPasswords no, X11Forwarding no, AllowUsers/Groups set.', 1),
(23, 'cis',      40, 'CIS 5.3 — PAM config',               'Configure pam_pwquality (minlen=14, dcredit=-1, ucredit=-1). Lock after 5 failures. Password history 5.', 1),
(24, 'cis',      41, 'CIS 5.4 — User accounts',            'Set password expiry (90 days), min age (7 days), warn (7 days). Remove unused users. Lock system accounts.', 1),
(25, 'cis',      42, 'CIS 5.5 — Root access',              'Disable direct root SSH. Use sudo. Restrict su via pam_wheel. Audit root PATH.', 1),
(26, 'cis',      43, 'CIS 6.1 — File permissions',         'Verify permissions on: passwd, shadow, group, gshadow, grub.cfg, cron dirs, SSH host keys.', 1),
(27, 'cis',      44, 'CIS 6.2 — User/group integrity',     'No duplicate UIDs/GIDs. No .netrc/.rhosts. Home dir permissions 750. All users have valid shells.', 1),
-- CIS Level 2 extras
(28, 'cis',      50, 'CIS L2 — SELinux / AppArmor',        'Enable and enforce mandatory access control (SELinux enforcing or AppArmor complain→enforce).', 0),
(29, 'cis',      51, 'CIS L2 — Bootloader password',       'Set GRUB2 superuser password. Verify /boot/grub2/grub.cfg permissions 400.', 0),
(30, 'cis',      52, 'CIS L2 — AIDE / IDS baseline',       'Install AIDE. Initialise database. Schedule daily check. Alert on changes.', 0),
-- Security final checks
(31, 'security', 60, 'Vulnerability scan',                  'Run OpenVAS/Nessus scan post-build. Review and remediate any critical/high findings before go-live.', 1),
(32, 'security', 61, 'Secrets / credential audit',          'Verify no plaintext passwords in /etc, cron, or home dirs. Confirm SSH keys are per-user, not shared.', 1),
(33, 'security', 62, 'Backup agent configured',             'Deploy and test backup agent. Confirm first backup completes and restore is tested.', 0),
-- Final handover
(34, 'monitoring', 70, 'Dashboard alert rules set',         'Create node alerts in monitoring platform: CPU, RAM, disk, ping, service health.', 1),
(35, 'monitoring', 71, 'Customer notification sent',        'Notify customer of successful provisioning, provide IP/credentials, onboarding guide.', 0),
(36, 'monitoring', 72, 'Provisioning sign-off',             'Final operator sign-off. Mark server as production-ready in portal.', 1);
