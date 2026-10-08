INSERT IGNORE INTO tbl_roles (role_name, description)
VALUES ('Barangay Treasurer', 'Access to financial reports and financial ledger only.');

INSERT IGNORE INTO tbl_permissions (perm_key, perm_label, perm_group, description) VALUES
('view_financial_reports', 'View Financial Reports', 'Finance', 'View financial entries, period summaries, and reports.'),
('manage_financial_reports', 'Manage Financial Reports', 'Finance', 'Create and edit income and expense entries.');

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

INSERT IGNORE INTO tbl_user_permissions (user_id, perm_key, granted)
SELECT u.user_id, 'view_financial_reports', 1
FROM tbl_users u
JOIN tbl_roles r ON r.role_id=u.role_id
WHERE r.role_name='Barangay Treasurer';

INSERT IGNORE INTO tbl_user_permissions (user_id, perm_key, granted)
SELECT u.user_id, 'manage_financial_reports', 1
FROM tbl_users u
JOIN tbl_roles r ON r.role_id=u.role_id
WHERE r.role_name='Barangay Treasurer';
