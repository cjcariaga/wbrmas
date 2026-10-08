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

INSERT IGNORE INTO tbl_permissions (perm_key, perm_label, perm_group, description) VALUES
('view_health_records', 'View Health Records', 'Health', 'View resident-linked encrypted health records and descriptive analytics.'),
('manage_health_records', 'Manage Health Records', 'Health', 'Add and edit resident-linked health records.');

INSERT IGNORE INTO tbl_user_permissions (user_id, perm_key, granted)
SELECT u.user_id, 'view_health_records', 1
FROM tbl_users u
JOIN tbl_roles r ON r.role_id = u.role_id
WHERE r.role_name = 'Barangay Staff';

INSERT IGNORE INTO tbl_user_permissions (user_id, perm_key, granted)
SELECT u.user_id, 'manage_health_records', 1
FROM tbl_users u
JOIN tbl_roles r ON r.role_id = u.role_id
WHERE r.role_name = 'Barangay Staff';
