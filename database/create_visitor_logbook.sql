CREATE TABLE IF NOT EXISTS tbl_visitor_logbook (
    visitor_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name TEXT NOT NULL,
    contact_number TEXT NULL,
    address TEXT NULL,
    purpose VARCHAR(50) NOT NULL,
    person_to_visit VARCHAR(150) NULL,
    time_in DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    time_out DATETIME NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_visitor_time_in_id (time_in, visitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO tbl_permissions (perm_key, perm_label, perm_group, description)
VALUES (
    'view_visitor_logbook',
    'View Visitor Logbook',
    'Administration',
    'View visitor logbook entries and record visitor time out.'
);

INSERT IGNORE INTO tbl_user_permissions (user_id, perm_key, granted)
SELECT u.user_id, 'view_visitor_logbook', 1
FROM tbl_users u
JOIN tbl_roles r ON r.role_id = u.role_id
WHERE r.role_name = 'Barangay Staff';
