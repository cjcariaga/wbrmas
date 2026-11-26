INSERT IGNORE INTO tbl_permissions (perm_key, perm_label, perm_group, description) VALUES
('view_dashboard', 'View Dashboard', 'General', 'View the staff dashboard.'),
('view_stats', 'View Statistics', 'General', 'View dashboard statistics.'),
('view_residents', 'View Residents', 'Residents', 'View resident records.'),
('view_resident_sensitive', 'View Sensitive Resident Data', 'Residents', 'View sensitive resident information.'),
('view_documents', 'View Documents', 'Documents', 'View document requests.'),
('create_document', 'Create Document Requests', 'Documents', 'Create document requests.'),
('approve_document', 'Approve Document Requests', 'Documents', 'Approve document requests.'),
('print_document', 'Print Documents', 'Documents', 'Print approved documents.'),
('view_blotter', 'View Blotter', 'Blotter', 'View blotter records.'),
('add_blotter', 'Add Blotter Records', 'Blotter', 'Add blotter records.'),
('update_blotter', 'Update Blotter Records', 'Blotter', 'Update blotter records.'),
('view_blotter_details', 'View Blotter Details', 'Blotter', 'View sensitive blotter details.');
