-- WBRMAS Database Schema
-- Web-Based Barangay Records Management and Audit System
-- Barangay San Isidro, City of Ilagan, Isabela

CREATE DATABASE IF NOT EXISTS wbrmas_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE wbrmas_db;

-- Roles
CREATE TABLE IF NOT EXISTS tbl_roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Users
CREATE TABLE IF NOT EXISTS tbl_users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARBINARY(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    role_id INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    failed_attempts INT DEFAULT 0,
    locked_until DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES tbl_roles(role_id)
);

-- Fingerprint Templates
CREATE TABLE IF NOT EXISTS tbl_fingerprint_templates (
    template_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    biometric_hash VARBINARY(255) NOT NULL,
    template_blob BLOB,
    device_info VARCHAR(100),
    enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES tbl_users(user_id)
);

-- Residents
CREATE TABLE IF NOT EXISTS tbl_residents (
    resident_id INT AUTO_INCREMENT PRIMARY KEY,
    resident_code VARCHAR(20) NOT NULL UNIQUE,
    first_name VARCHAR(150) NOT NULL,
    middle_name VARCHAR(150),
    last_name VARCHAR(150) NOT NULL,
    birth_date VARCHAR(255) NOT NULL,
    sex VARCHAR(50),
    civil_status VARCHAR(50),
    contact_number VARCHAR(50),
    email VARCHAR(150),
    address TEXT,
    drawer_location VARCHAR(100) NULL,
    years_of_residency INT DEFAULT 0,
    is_indigent BOOLEAN DEFAULT FALSE,
    is_archived BOOLEAN DEFAULT FALSE,
    registered_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (registered_by) REFERENCES tbl_users(user_id)
);

-- External resident portal identities are separate from internal staff users.
CREATE TABLE IF NOT EXISTS tbl_resident_portal_accounts (
    portal_account_id INT AUTO_INCREMENT PRIMARY KEY,
    resident_id INT NOT NULL UNIQUE,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    failed_attempts INT DEFAULT 0,
    locked_until DATETIME NULL,
    last_login DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (resident_id) REFERENCES tbl_residents(resident_id) ON DELETE CASCADE
);

-- Document Types
CREATE TABLE IF NOT EXISTS tbl_document_types (
    type_id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(100) NOT NULL,
    description TEXT,
    requirements TEXT,
    fee DECIMAL(10,2) DEFAULT 0.00,
    is_active BOOLEAN DEFAULT TRUE
);

-- Document Requests
CREATE TABLE IF NOT EXISTS tbl_document_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(20) NOT NULL UNIQUE,
    resident_id INT NOT NULL,
    document_type_id INT NOT NULL,
    purpose TEXT,
    status ENUM('PENDING','APPROVED','PRINTED','STORED','RELEASED','REJECTED') DEFAULT 'PENDING',
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    issued_by_user_id INT NULL,
    portal_account_id INT NULL,
    preferred_pickup_date DATE NULL,
    received_by VARCHAR(150) NULL,
    received_at DATETIME NULL,
    drawer_location VARCHAR(100) NULL,
    remarks TEXT,
    FOREIGN KEY (resident_id) REFERENCES tbl_residents(resident_id),
    FOREIGN KEY (document_type_id) REFERENCES tbl_document_types(type_id),
    FOREIGN KEY (issued_by_user_id) REFERENCES tbl_users(user_id)
);

-- Generated Documents
CREATE TABLE IF NOT EXISTS tbl_generated_documents (
    generation_id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL UNIQUE,
    document_hash VARCHAR(255) NOT NULL,
    document_blob LONGBLOB,
    control_number VARCHAR(50) UNIQUE,
    issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES tbl_document_requests(request_id)
);

-- Blotter Records
CREATE TABLE IF NOT EXISTS tbl_blotter (
    case_id INT AUTO_INCREMENT PRIMARY KEY,
    case_number VARCHAR(20) NOT NULL UNIQUE,
    complainant_id INT NULL,
    complainant_name VARCHAR(500) NULL,
    complainant_address TEXT NULL,
    complainant_contact VARCHAR(255) NULL,
    respondent_resident_id INT NULL,
    respondent_name VARCHAR(200),
    incident_type VARCHAR(100) NOT NULL,
    incident_description TEXT,
    incident_date DATE NOT NULL,
    incident_location VARCHAR(255),
    resolution_status ENUM('Active','Under Mediation','Resolved','Referred') DEFAULT 'Active',
    resolution_notes TEXT,
    filed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    filed_by INT,
    FOREIGN KEY (complainant_id) REFERENCES tbl_residents(resident_id),
    FOREIGN KEY (respondent_resident_id) REFERENCES tbl_residents(resident_id),
    FOREIGN KEY (filed_by) REFERENCES tbl_users(user_id)
);

-- Audit Logs
CREATE TABLE IF NOT EXISTS tbl_audit_logs (
    log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action_type VARCHAR(100) NOT NULL,
    affected_record VARCHAR(255),
    ip_address VARCHAR(45),
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    details TEXT,
    hmac_signature TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS tbl_permissions (
    perm_id INT AUTO_INCREMENT PRIMARY KEY,
    perm_key VARCHAR(80) NOT NULL UNIQUE,
    perm_label VARCHAR(120) NOT NULL,
    perm_group VARCHAR(60) NOT NULL,
    description TEXT NULL
);

CREATE TABLE IF NOT EXISTS tbl_user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    perm_key VARCHAR(80) NOT NULL,
    granted BOOLEAN DEFAULT TRUE,
    UNIQUE KEY uniq_user_perm (user_id, perm_key),
    FOREIGN KEY (user_id) REFERENCES tbl_users(user_id) ON DELETE CASCADE
);

-- Physical Drawer Index
CREATE TABLE IF NOT EXISTS tbl_storage_drawers (
    drawer_id INT AUTO_INCREMENT PRIMARY KEY,
    drawer_name VARCHAR(100) NOT NULL UNIQUE,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES tbl_users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS tbl_storage_entries (
    entry_id INT AUTO_INCREMENT PRIMARY KEY,
    drawer_id INT NOT NULL,
    entry_label VARCHAR(200) NOT NULL,
    entry_notes TEXT NULL,
    resident_id INT NULL,
    request_id INT NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_storage_entry_label (entry_label),
    FOREIGN KEY (drawer_id) REFERENCES tbl_storage_drawers(drawer_id) ON DELETE CASCADE,
    FOREIGN KEY (resident_id) REFERENCES tbl_residents(resident_id) ON DELETE SET NULL,
    FOREIGN KEY (request_id) REFERENCES tbl_document_requests(request_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES tbl_users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES tbl_users(user_id) ON DELETE SET NULL
);

-- Health record fields are AES-encrypted by the application before insertion.
CREATE TABLE IF NOT EXISTS tbl_health_records (
    health_record_id INT AUTO_INCREMENT PRIMARY KEY,
    resident_id INT NOT NULL,
    record_type_enc TEXT NOT NULL,
    item_name_enc TEXT NOT NULL,
    event_date_enc TEXT NULL,
    details_enc TEXT NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_health_resident (resident_id),
    FOREIGN KEY (resident_id) REFERENCES tbl_residents(resident_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES tbl_users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES tbl_users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS tbl_financial_entries (
    financial_entry_id INT AUTO_INCREMENT PRIMARY KEY,
    entry_type ENUM('INCOME','EXPENSE') NOT NULL,
    category VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    entry_date DATE NOT NULL,
    description TEXT NOT NULL,
    source_request_id INT NULL UNIQUE,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_financial_date_type (entry_date, entry_type),
    FOREIGN KEY (source_request_id) REFERENCES tbl_document_requests(request_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES tbl_users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES tbl_users(user_id) ON DELETE SET NULL
);

INSERT IGNORE INTO tbl_permissions (perm_key, perm_label, perm_group, description) VALUES
('view_drawer_index', 'View Drawer Index', 'Records', 'View physical drawer folders and filing index entries.'),
('manage_drawer_index', 'Manage Drawer Index', 'Records', 'Create and edit physical drawer folders and filing index entries.'),
('view_health_records', 'View Health Records', 'Health', 'View resident-linked encrypted health records and descriptive analytics.'),
('manage_health_records', 'Manage Health Records', 'Health', 'Add and edit resident-linked health records.'),
('view_financial_reports', 'View Financial Reports', 'Finance', 'View financial entries, period summaries, and reports.'),
('manage_financial_reports', 'Manage Financial Reports', 'Finance', 'Create and edit income and expense entries.');

-- Rate Limits
CREATE TABLE IF NOT EXISTS tbl_rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(100) NOT NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier (identifier),
    INDEX idx_attempted_at (attempted_at)
);

-- ─── Seed Data ──────────────────────────────────────────────────────────────
INSERT INTO tbl_roles (role_name, description) VALUES
('System Administrator', 'Full system access including user management, audit trails, and configuration.'),
('Barangay Staff', 'Access to resident records, document issuance, and blotter management.'),
('Barangay Treasurer', 'Access to financial reports and financial ledger only.');

-- Default admin: admin / Admin@1234
INSERT INTO tbl_users (username, password_hash, full_name, role_id, is_active) VALUES
('admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 1, TRUE);

INSERT INTO tbl_document_types (type_name, description, requirements, fee) VALUES
('Barangay Clearance', 'Certifies the resident has no pending complaints within the barangay.', 'Valid ID, 1 Passport Size Photo', 50.00),
('Certificate of Indigency', 'Certifies the resident belongs to an indigent family in the barangay.', 'Valid ID, Proof of Residency', 0.00);

-- Cleanup old rate limit entries (event)
CREATE EVENT IF NOT EXISTS cleanup_rate_limits
ON SCHEDULE EVERY 1 HOUR
DO DELETE FROM tbl_rate_limits WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 HOUR);
