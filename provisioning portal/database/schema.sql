CREATE TABLE nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    site VARCHAR(50),
    provider VARCHAR(50),
    mgmt_ip VARCHAR(45),
    status VARCHAR(20) DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE automations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    enabled TINYINT DEFAULT 1,
    trigger_type VARCHAR(20),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE automation_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    automation_id INT,
    status VARCHAR(20) DEFAULT 'queued',
    meta JSON,
    locked_by VARCHAR(100),
    locked_at DATETIME,
    started_at DATETIME,
    finished_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (automation_id) REFERENCES automations(id)
);

CREATE TABLE automation_run_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT,
    level VARCHAR(20),
    message TEXT,
    context JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (run_id) REFERENCES automation_runs(id)
);