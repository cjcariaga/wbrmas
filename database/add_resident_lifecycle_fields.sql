ALTER TABLE tbl_residents
    ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) NULL AFTER is_archived,
    ADD COLUMN IF NOT EXISTS is_head_of_family BOOLEAN DEFAULT FALSE AFTER photo_path,
    ADD COLUMN IF NOT EXISTS household_head_id INT NULL AFTER is_head_of_family,
    ADD COLUMN IF NOT EXISTS record_status ENUM('Active','Pending Verification','Archived') NOT NULL DEFAULT 'Pending Verification' AFTER household_head_id,
    ADD COLUMN IF NOT EXISTS archive_reason TEXT NULL AFTER record_status,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER archive_reason,
    ADD COLUMN IF NOT EXISTS archived_by INT NULL AFTER archived_at;

-- Existing rows predate record_status; treat already-active residents as Active rather than Pending.
UPDATE tbl_residents SET record_status='Active' WHERE is_archived=0 AND record_status='Pending Verification';
UPDATE tbl_residents SET record_status='Archived' WHERE is_archived=1 AND record_status<>'Archived';

SET @hh_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_residents'
      AND CONSTRAINT_NAME = 'fk_residents_household_head'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @hh_fk_sql = IF(
    @hh_fk_exists = 0,
    'ALTER TABLE tbl_residents ADD CONSTRAINT fk_residents_household_head FOREIGN KEY (household_head_id) REFERENCES tbl_residents(resident_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE hh_fk_stmt FROM @hh_fk_sql;
EXECUTE hh_fk_stmt;
DEALLOCATE PREPARE hh_fk_stmt;

SET @archived_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_residents'
      AND CONSTRAINT_NAME = 'fk_residents_archived_by'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @archived_fk_sql = IF(
    @archived_fk_exists = 0,
    'ALTER TABLE tbl_residents ADD CONSTRAINT fk_residents_archived_by FOREIGN KEY (archived_by) REFERENCES tbl_users(user_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE archived_fk_stmt FROM @archived_fk_sql;
EXECUTE archived_fk_stmt;
DEALLOCATE PREPARE archived_fk_stmt;
