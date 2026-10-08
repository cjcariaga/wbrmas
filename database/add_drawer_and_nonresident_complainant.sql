ALTER TABLE tbl_residents
    ADD COLUMN IF NOT EXISTS drawer_location VARCHAR(100) NULL AFTER address;

ALTER TABLE tbl_document_requests
    ADD COLUMN IF NOT EXISTS drawer_location VARCHAR(100) NULL AFTER received_at;

ALTER TABLE tbl_blotter
    MODIFY COLUMN complainant_id INT NULL,
    ADD COLUMN IF NOT EXISTS complainant_name VARCHAR(500) NULL AFTER complainant_id,
    ADD COLUMN IF NOT EXISTS complainant_address TEXT NULL AFTER complainant_name,
    ADD COLUMN IF NOT EXISTS complainant_contact VARCHAR(255) NULL AFTER complainant_address;