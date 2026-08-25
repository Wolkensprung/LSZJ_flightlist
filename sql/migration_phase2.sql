-- LSZJ Flightlist Phase 2 Migration
-- Version 1.0
-- Target: MariaDB 12.x
-- NON-DESTRUCTIVE MIGRATION

USE lszj_flightlist;

CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pilot_master_id BIGINT UNSIGNED NULL,
    external_contact_id BIGINT UNSIGNED NULL,
    display_name VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_pilot FOREIGN KEY (pilot_master_id) REFERENCES pilots_master(id),
    CONSTRAINT fk_users_external FOREIGN KEY (external_contact_id) REFERENCES external_contacts(id),
    UNIQUE KEY uk_users_pilot (pilot_master_id),
    UNIQUE KEY uk_users_external (external_contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    role_id INT NOT NULL,
    valid_from DATETIME NULL,
    valid_until DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE KEY uk_user_role (user_id, role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qr_login_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token CHAR(64) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    device_type ENUM('SMARTPHONE','C_BUERO') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    CONSTRAINT fk_qr_login_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_qr_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS duty_officer_shifts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL,
    handover_to BIGINT UNSIGNED NULL,
    handover_reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_shift_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_shift_handover FOREIGN KEY (handover_to) REFERENCES users(id),
    KEY idx_shift_active (end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS flight_days (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation_date DATE NOT NULL,
    status ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
    closed_by BIGINT UNSIGNED NULL,
    closed_at DATETIME NULL,
    reopened_by BIGINT UNSIGNED NULL,
    reopened_at DATETIME NULL,
    reopen_reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_operation_date (operation_date),
    CONSTRAINT fk_day_closed_by FOREIGN KEY (closed_by) REFERENCES users(id),
    CONSTRAINT fk_day_opened_by FOREIGN KEY (reopened_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS day_closure_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flight_day_id BIGINT UNSIGNED NOT NULL,
    severity ENUM('GREEN','YELLOW','RED') NOT NULL,
    comment TEXT NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_day_note_day FOREIGN KEY (flight_day_id) REFERENCES flight_days(id) ON DELETE CASCADE,
    CONSTRAINT fk_day_note_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO roles(code,name,description) VALUES
('PILOT','Pilot','Kann eigene Flüge bearbeiten und freigeben'),
('DUTY_OFFICER','Flugdienstleiter','Aktiver Flugdienstleiter'),
('ADMIN','Administrator','Vollzugriff');

INSERT INTO users(pilot_master_id,display_name)
SELECT id, display_name
FROM pilots_master
WHERE is_active = 1
AND NOT EXISTS (
  SELECT 1 FROM users u WHERE u.pilot_master_id = pilots_master.id
);
