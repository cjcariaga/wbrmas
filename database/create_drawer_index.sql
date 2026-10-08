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

INSERT IGNORE INTO tbl_storage_drawers (drawer_name) VALUES
('Drawer 1'),
('Drawer 2'),
('Drawer 3'),
('Drawer 4');

INSERT IGNORE INTO tbl_permissions (perm_key, perm_label, perm_group, description) VALUES
('view_drawer_index', 'View Drawer Index', 'Records', 'View physical drawer folders and filing index entries.'),
('manage_drawer_index', 'Manage Drawer Index', 'Records', 'Create and edit physical drawer folders and filing index entries.');

INSERT IGNORE INTO tbl_user_permissions (user_id, perm_key, granted)
SELECT u.user_id, 'view_drawer_index', 1
FROM tbl_users u
JOIN tbl_roles r ON r.role_id = u.role_id
WHERE r.role_name = 'Barangay Staff';

INSERT IGNORE INTO tbl_user_permissions (user_id, perm_key, granted)
SELECT u.user_id, 'manage_drawer_index', 1
FROM tbl_users u
JOIN tbl_roles r ON r.role_id = u.role_id
WHERE r.role_name = 'Barangay Staff';
