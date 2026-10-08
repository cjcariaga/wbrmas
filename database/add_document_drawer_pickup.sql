ALTER TABLE tbl_document_requests
    MODIFY COLUMN status ENUM('PENDING','APPROVED','PRINTED','STORED','RELEASED','REJECTED') DEFAULT 'PENDING';

INSERT IGNORE INTO tbl_storage_drawers (drawer_name) VALUES
('Drawer 1'),
('Drawer 2'),
('Drawer 3'),
('Drawer 4');