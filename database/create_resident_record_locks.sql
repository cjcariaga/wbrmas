CREATE TABLE IF NOT EXISTS tbl_record_locks (
    lock_id INT AUTO_INCREMENT PRIMARY KEY,
    resident_id INT NOT NULL,
    locked_by INT NOT NULL,
    locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uq_record_lock_resident (resident_id),
    INDEX idx_record_lock_expiry (expires_at),
    CONSTRAINT fk_record_lock_resident
        FOREIGN KEY (resident_id) REFERENCES tbl_residents(resident_id) ON DELETE CASCADE,
    CONSTRAINT fk_record_lock_user
        FOREIGN KEY (locked_by) REFERENCES tbl_users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;
