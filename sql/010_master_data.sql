CREATE TABLE IF NOT EXISTS pilots_master (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vf_user_no VARCHAR(32) NOT NULL,
    vf_member_no VARCHAR(32) NULL,
    display_name VARCHAR(255) NOT NULL,
    search_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL,
    mobile VARCHAR(64) NULL,
    membership_status VARCHAR(100) NULL,
    cost_level VARCHAR(100) NULL,
    priority_group ENUM('flying_member','student','gvvc','other') NOT NULL DEFAULT 'other',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    source_hash CHAR(64) NOT NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pilots_master_vf_user_no (vf_user_no),
    UNIQUE KEY uq_pilots_master_member_no (vf_member_no),
    KEY idx_pilots_master_primary_name (is_active, is_primary, display_name),
    KEY idx_pilots_master_search_name (search_name),
    KEY idx_pilots_master_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aircraft_master (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    callsign VARCHAR(32) NOT NULL,
    search_key VARCHAR(128) NOT NULL,
    competition_code VARCHAR(32) NULL,
    aircraft_type VARCHAR(100) NULL,
    model_designation VARCHAR(255) NULL,
    owner_name VARCHAR(255) NULL,
    is_club_aircraft TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    source_hash CHAR(64) NOT NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_aircraft_master_callsign (callsign),
    KEY idx_aircraft_master_club_callsign (is_active, is_club_aircraft, callsign),
    KEY idx_aircraft_master_search_key (search_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS master_data_import_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_type ENUM('members','aircraft') NOT NULL,
    source_filename VARCHAR(255) NOT NULL,
    source_sha256 CHAR(64) NOT NULL,
    rows_read INT UNSIGNED NOT NULL DEFAULT 0,
    rows_imported INT UNSIGNED NOT NULL DEFAULT 0,
    rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
    warnings_json LONGTEXT NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_master_data_import_runs_type_date (import_type, imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
