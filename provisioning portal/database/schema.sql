-- ============================================================
--  Glasshouse NOC Provisioning Portal — Database Schema
--  All-in-one management: hosting, MSP, hardware tracking
-- ============================================================

-- Core node / server inventory
CREATE TABLE nodes (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    site         VARCHAR(50),
    provider     VARCHAR(50),
    mgmt_ip      VARCHAR(45),
    status       VARCHAR(20) DEFAULT 'unknown',
    -- Hardware placement
    datacenter_id INT NULL,
    rack_id       INT NULL,
    rack_unit_start INT NULL,
    rack_unit_size  INT DEFAULT 1,
    -- Asset & system info
    asset_tag    VARCHAR(100) NULL,
    serial_number VARCHAR(100) NULL,
    make         VARCHAR(100) NULL,
    model        VARCHAR(100) NULL,
    cpu_model    VARCHAR(150) NULL,
    cpu_cores    TINYINT UNSIGNED NULL,
    ram_gb       SMALLINT UNSIGNED NULL,
    storage_gb   INT UNSIGNED NULL,
    os_type      VARCHAR(50) NULL,
    os_version   VARCHAR(100) NULL,
    role         VARCHAR(50) NULL,
    -- Ownership
    customer_id  INT NULL,
    -- Health
    last_seen_at DATETIME NULL,
    notes        TEXT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Automation catalog
CREATE TABLE automations (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    description  TEXT,
    enabled      TINYINT DEFAULT 1,
    trigger_type VARCHAR(20),
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Automation run instances
CREATE TABLE automation_runs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    automation_id INT,
    status        VARCHAR(20) DEFAULT 'queued',
    meta          JSON,
    locked_by     VARCHAR(100),
    locked_at     DATETIME,
    started_at    DATETIME,
    finished_at   DATETIME,
    duration_ms   INT UNSIGNED NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (automation_id) REFERENCES automations(id)
);

-- Per-run log lines
CREATE TABLE automation_run_logs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    run_id     INT,
    level      VARCHAR(20),
    message    TEXT,
    context    JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (run_id) REFERENCES automation_runs(id)
);

-- ============================================================
--  Hardware Infrastructure
-- ============================================================

-- Physical data centers
CREATE TABLE datacenters (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    code          VARCHAR(20) NULL,      -- short identifier, e.g. "DC1-SYD"
    location      VARCHAR(200) NULL,
    address       TEXT NULL,
    city          VARCHAR(100) NULL,
    state         VARCHAR(100) NULL,
    country       VARCHAR(100) NULL,
    contact_name  VARCHAR(100) NULL,
    contact_phone VARCHAR(50) NULL,
    contact_email VARCHAR(150) NULL,
    power_capacity_kw SMALLINT UNSIGNED NULL,
    total_sqft    INT UNSIGNED NULL,
    status        VARCHAR(20) DEFAULT 'active',
    notes         TEXT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Racks within data centers
CREATE TABLE racks (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    datacenter_id   INT NOT NULL,
    name            VARCHAR(100) NOT NULL,   -- e.g. "Rack A-01"
    row_label       VARCHAR(50) NULL,        -- aisle/row label
    position        VARCHAR(50) NULL,        -- position within row
    total_units     TINYINT UNSIGNED DEFAULT 42,
    rack_height_mm  SMALLINT UNSIGNED NULL,
    power_amps      TINYINT UNSIGNED NULL,
    status          VARCHAR(20) DEFAULT 'active',
    notes           TEXT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (datacenter_id) REFERENCES datacenters(id)
);

-- ============================================================
--  Customer / Tenant Registry
-- ============================================================

CREATE TABLE customers (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150) NOT NULL,
    company_type   VARCHAR(50) NULL,   -- hosting | msp | colocation | managed | cloud
    contact_name   VARCHAR(100) NULL,
    contact_email  VARCHAR(150) NULL,
    contact_phone  VARCHAR(50) NULL,
    address        TEXT NULL,
    city           VARCHAR(100) NULL,
    state          VARCHAR(100) NULL,
    country        VARCHAR(100) NULL,
    account_number VARCHAR(50) NULL,
    account_status VARCHAR(20) DEFAULT 'active',  -- active | suspended | churned
    service_level  VARCHAR(50) NULL,   -- basic | standard | enterprise | premium
    mrr_cents      INT UNSIGNED NULL,  -- monthly recurring revenue in cents
    contract_start DATE NULL,
    contract_end   DATE NULL,
    notes          TEXT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
--  Auth & Audit
-- ============================================================

CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(30) DEFAULT 'operator',
    is_active     TINYINT DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE access_logs (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NULL,
    email        VARCHAR(150) NULL,
    action       VARCHAR(50) NOT NULL,
    ip_address   VARCHAR(45) NULL,
    user_agent   TEXT NULL,
    success      TINYINT DEFAULT 1,
    reason       VARCHAR(255) NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE audit_logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    action        VARCHAR(100) NOT NULL,
    target_type   VARCHAR(50) NULL,
    target_id     INT NULL,
    meta          JSON NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
--  Ansible Script Library
-- ============================================================

CREATE TABLE ansible_scripts (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(150) NOT NULL,
    description         TEXT NULL,
    script_type         VARCHAR(30) DEFAULT 'playbook',  -- playbook | adhoc | role
    category            VARCHAR(50) NULL,    -- provisioning | hardening | patching | monitoring | custom
    command             TEXT NOT NULL,       -- playbook path or full ansible command
    extra_vars          JSON NULL,           -- default --extra-vars as JSON object
    tags                VARCHAR(255) NULL,   -- comma-separated ansible --tags
    timeout_seconds     SMALLINT UNSIGNED DEFAULT 3600,
    requires_sudo       TINYINT DEFAULT 0,
    is_active           TINYINT DEFAULT 1,
    created_by_user_id  INT NULL,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
--  Provisioning Tasks & CIS Hardening Tracking
-- ============================================================

-- Provisioning task definitions (templates)
CREATE TABLE provisioning_tasks (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    category     VARCHAR(50) NOT NULL,   -- hardware | os | network | security | cis | monitoring
    step_order   TINYINT UNSIGNED NOT NULL,
    name         VARCHAR(150) NOT NULL,
    description  TEXT NULL,
    is_required  TINYINT DEFAULT 1,
    script_id    INT NULL,   -- linked ansible_script to auto-run for this step
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (script_id) REFERENCES ansible_scripts(id) ON DELETE SET NULL
);

-- Per-server provisioning run records
CREATE TABLE server_provisioning (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    node_id         INT NOT NULL,
    started_by      INT NULL,   -- user id
    status          VARCHAR(20) DEFAULT 'in_progress',  -- in_progress | complete | abandoned
    notes           TEXT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME NULL,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE
);

-- Per-step completion tracking
CREATE TABLE provisioning_step_log (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    provisioning_id         INT NOT NULL,
    task_id                 INT NULL,
    task_name               VARCHAR(150) NOT NULL,
    category                VARCHAR(50) NOT NULL,
    status                  VARCHAR(20) DEFAULT 'pending',  -- pending | pass | fail | skip | na
    automation_run_id       INT NULL,   -- linked run if executed via ansible
    notes                   TEXT NULL,
    completed_by_user_id    INT NULL,
    completed_at            DATETIME NULL,
    created_at              DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (provisioning_id) REFERENCES server_provisioning(id) ON DELETE CASCADE,
    FOREIGN KEY (automation_run_id) REFERENCES automation_runs(id) ON DELETE SET NULL
);

-- ============================================================
--  Default provisioning tasks (CIS + standard build steps)
-- ============================================================

INSERT INTO provisioning_tasks (category, step_order, name, description, is_required) VALUES
-- Hardware verification
('hardware', 1,  'Verify physical installation',      'Confirm server is correctly racked, cabled (power + network), and POST completes without errors.', 1),
('hardware', 2,  'BIOS/UEFI baseline config',         'Set boot order, enable VT-x/AMD-V, disable unused ports (USB/COM), set BIOS password, sync time.', 1),
('hardware', 3,  'IPMI / iDRAC / iLO setup',          'Configure OOB management IP, credentials, alert destinations, and firmware update.', 1),
('hardware', 4,  'Asset tag & serial recorded',        'Scan/enter asset tag and serial number into the portal.', 1),
-- OS provisioning
('os',       10, 'OS installation',                   'Install base OS from approved image (Ubuntu LTS / RHEL / Debian). Verify integrity hash.', 1),
('os',       11, 'Hostname & timezone',                'Set FQDN hostname, configure correct timezone and NTP sources.', 1),
('os',       12, 'Network interfaces configured',     'Set static IPs, bonding/teaming, VLAN tagging. Verify routing table and DNS resolution.', 1),
('os',       13, 'SSH keypair provisioned',            'Deploy ops team SSH keys. Disable password auth. Test access.', 1),
('os',       14, 'Package updates applied',            'Run full system update (apt upgrade / dnf update). Reboot if kernel updated.', 1),
-- Network / Connectivity
('network',  20, 'Firewall rules applied',             'Configure host-based firewall (ufw/firewalld). Allow only required ports. Deny all else.', 1),
('network',  21, 'Monitoring agent installed',         'Deploy Prometheus node_exporter or equivalent. Verify scrape endpoint reachable.', 1),
('network',  22, 'Connectivity test to all services',  'Confirm reach to: DNS, NTP, SMTP relay, monitoring, logging, backup endpoints.', 1),
-- CIS Level 1 Hardening
('cis',      30, 'CIS 1.1 — Filesystem config',       'Disable cramfs, freevxfs, jffs2, hfs, hfsplus, squashfs, udf. Set /tmp nodev,nosuid,noexec.', 1),
('cis',      31, 'CIS 1.2 — Software updates',        'Verify package manager GPG keys. Enable automatic security updates.', 1),
('cis',      32, 'CIS 2.1 — Remove inetd services',   'Disable/remove chargen, daytime, echo, discard, time, tftp, xinetd.', 1),
('cis',      33, 'CIS 2.2 — Special purpose services','Disable X Windows, Avahi, CUPS, DHCP, LDAP, NFS, DNS, FTP, HTTP, IMAP/POP3 unless required.', 1),
('cis',      34, 'CIS 3.1 — Network parameters',      'Disable IP forwarding, redirects, source routing. Enable reverse path filtering, SYN cookies.', 1),
('cis',      35, 'CIS 3.2 — IPv6 params',             'Disable IPv6 router/redirect acceptance. Disable IPv6 if not in use.', 1),
('cis',      36, 'CIS 4.1 — Logging configured',      'Configure rsyslog/journald. Ensure remote syslog forwarded. Log rotation set.', 1),
('cis',      37, 'CIS 4.2 — Auditd rules',            'Install auditd. Configure rules: logins, sudo, file changes, privileged commands, cron.', 1),
('cis',      38, 'CIS 5.1 — Cron access control',     'Restrict cron/at to authorised users. Verify /etc/cron.allow and /etc/at.allow.', 1),
('cis',      39, 'CIS 5.2 — SSH server config',       'Protocol 2 only, MaxAuthTries ≤4, PermitRootLogin no, PermitEmptyPasswords no, X11Forwarding no, AllowUsers/Groups set.', 1),
('cis',      40, 'CIS 5.3 — PAM config',              'Configure pam_pwquality (minlen=14, dcredit=-1, ucredit=-1). Lock after 5 failures. Password history 5.', 1),
('cis',      41, 'CIS 5.4 — User accounts',           'Set password expiry (90 days), min age (7 days), warn (7 days). Remove unused users. Lock system accounts.', 1),
('cis',      42, 'CIS 5.5 — Root access',             'Disable direct root SSH. Use sudo. Restrict su via pam_wheel. Audit root PATH.', 1),
('cis',      43, 'CIS 6.1 — File permissions',        'Verify permissions on: passwd, shadow, group, gshadow, grub.cfg, cron dirs, SSH host keys.', 1),
('cis',      44, 'CIS 6.2 — User/group integrity',    'No duplicate UIDs/GIDs. No .netrc/.rhosts. Home dir permissions 750. All users have valid shells.', 1),
-- CIS Level 2 extras
('cis',      50, 'CIS L2 — SELinux / AppArmor',       'Enable and enforce mandatory access control (SELinux enforcing or AppArmor complain→enforce).', 0),
('cis',      51, 'CIS L2 — Bootloader password',      'Set GRUB2 superuser password. Verify /boot/grub2/grub.cfg permissions 400.', 0),
('cis',      52, 'CIS L2 — AIDE / IDS baseline',      'Install AIDE. Initialise database. Schedule daily check. Alert on changes.', 0),
-- Security final checks
('security', 60, 'Vulnerability scan',                 'Run OpenVAS/Nessus scan post-build. Review and remediate any critical/high findings before go-live.', 1),
('security', 61, 'Secrets / credential audit',         'Verify no plaintext passwords in /etc, cron, or home dirs. Confirm SSH keys are per-user, not shared.', 1),
('security', 62, 'Backup agent configured',            'Deploy and test backup agent. Confirm first backup completes and restore is tested.', 0),
-- Final handover
('monitoring', 70, 'Dashboard alert rules set',        'Create node alerts in monitoring platform: CPU, RAM, disk, ping, service health.', 1),
('monitoring', 71, 'Customer notification sent',        'Notify customer of successful provisioning, provide IP/credentials, onboarding guide.', 0),
('monitoring', 72, 'Provisioning sign-off',            'Final operator sign-off. Mark server as production-ready in portal.', 1);

-- ============================================================
--  Authentication — Login audit & Remember-me tokens
-- ============================================================

CREATE TABLE auth_logins (
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

CREATE TABLE auth_remember_tokens (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    selector            VARCHAR(32) NOT NULL UNIQUE,
    validator_hash      VARCHAR(255) NOT NULL,
    expires_at          DATETIME NOT NULL,
    ip_created          VARCHAR(45) NULL,
    user_agent_created  TEXT NULL,
    revoked_at          DATETIME NULL,
    last_used_at        DATETIME NULL,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_remember_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
--  Foreign keys (applied after all tables exist)
-- ============================================================

ALTER TABLE nodes
    ADD CONSTRAINT fk_nodes_datacenter FOREIGN KEY (datacenter_id) REFERENCES datacenters(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_nodes_rack       FOREIGN KEY (rack_id)       REFERENCES racks(id)        ON DELETE SET NULL,
    ADD CONSTRAINT fk_nodes_customer   FOREIGN KEY (customer_id)   REFERENCES customers(id)    ON DELETE SET NULL;

-- ============================================================
--  Missing columns (added after initial table creation)
-- ============================================================

-- automation_runs: execution tracking columns
ALTER TABLE automation_runs
    ADD COLUMN initiated_via       VARCHAR(30) NULL    AFTER duration_ms,   -- web|api|schedule|worker
    ADD COLUMN initiated_by_user_id INT NULL            AFTER initiated_via,
    ADD COLUMN error_code          VARCHAR(50) NULL    AFTER initiated_by_user_id,
    ADD COLUMN error_message       TEXT NULL            AFTER error_code;

-- automations: schedule support
ALTER TABLE automations
    ADD COLUMN schedule_cron VARCHAR(100) NULL AFTER trigger_type;

-- audit_logs: request context
ALTER TABLE audit_logs
    ADD COLUMN ip         VARCHAR(45) NULL AFTER meta,
    ADD COLUMN user_agent TEXT NULL        AFTER ip;
