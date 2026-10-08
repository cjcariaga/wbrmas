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

ALTER TABLE tbl_document_requests
    ADD COLUMN IF NOT EXISTS portal_account_id INT NULL AFTER issued_by_user_id,
    ADD COLUMN IF NOT EXISTS preferred_pickup_date DATE NULL AFTER portal_account_id;
