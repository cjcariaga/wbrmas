ALTER TABLE tbl_users
    ADD COLUMN first_name VARCHAR(80) NOT NULL DEFAULT '' AFTER full_name,
    ADD COLUMN middle_name VARCHAR(80) NOT NULL DEFAULT '' AFTER first_name,
    ADD COLUMN last_name VARCHAR(80) NOT NULL DEFAULT '' AFTER middle_name;
