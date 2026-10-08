ALTER TABLE tbl_blotter
    ADD COLUMN IF NOT EXISTS respondent_resident_id INT NULL AFTER complainant_contact;

SET @respondent_fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_blotter'
      AND CONSTRAINT_NAME = 'fk_blotter_respondent_resident'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @respondent_fk_sql = IF(
    @respondent_fk_exists = 0,
    'ALTER TABLE tbl_blotter ADD CONSTRAINT fk_blotter_respondent_resident FOREIGN KEY (respondent_resident_id) REFERENCES tbl_residents(resident_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE respondent_fk_stmt FROM @respondent_fk_sql;
EXECUTE respondent_fk_stmt;
DEALLOCATE PREPARE respondent_fk_stmt;