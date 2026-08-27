USE lszj_flightlist;

CREATE TABLE IF NOT EXISTS flight_day_states (
    operation_date DATE NOT NULL PRIMARY KEY,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    closed_at DATETIME NULL,
    closed_by BIGINT UNSIGNED NULL,
    close_note VARCHAR(1000) NULL,
    reopened_at DATETIME NULL,
    reopened_by BIGINT UNSIGNED NULL,
    reopen_reason VARCHAR(1000) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_flight_day_states_closed_by FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_flight_day_states_reopened_by FOREIGN KEY (reopened_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS flight_day_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    operation_date DATE NOT NULL,
    action ENUM('close','reopen') NOT NULL,
    performed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    performed_by BIGINT UNSIGNED NULL,
    reason VARCHAR(1000) NULL,
    red_count INT UNSIGNED NOT NULL DEFAULT 0,
    yellow_count INT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_flight_day_audit_date (operation_date, performed_at),
    CONSTRAINT fk_flight_day_audit_user FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
