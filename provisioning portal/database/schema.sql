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
--  Foreign keys (applied after all tables exist)
-- ============================================================

ALTER TABLE nodes
    ADD CONSTRAINT fk_nodes_datacenter FOREIGN KEY (datacenter_id) REFERENCES datacenters(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_nodes_rack       FOREIGN KEY (rack_id)       REFERENCES racks(id)        ON DELETE SET NULL,
    ADD CONSTRAINT fk_nodes_customer   FOREIGN KEY (customer_id)   REFERENCES customers(id)    ON DELETE SET NULL;
