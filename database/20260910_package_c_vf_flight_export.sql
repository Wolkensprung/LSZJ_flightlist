-- Paket C: Protokoll und Dublettenschutz fuer den VF-REST-Flugexport
-- Enthaelt keine Nutzdaten und veraendert bestehende Flugtabellen nicht.

CREATE TABLE IF NOT EXISTS vf_flight_exports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    accounting_entry_id BIGINT UNSIGNED NOT NULL,
    operation_id BIGINT UNSIGNED NOT NULL,
    vf_flid VARCHAR(64) DEFAULT NULL,
    api_action ENUM('flight/add','flight/edit') NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    payload_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
        CHECK (JSON_VALID(payload_json)),
    status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
    http_status SMALLINT UNSIGNED DEFAULT NULL,
    error_message VARCHAR(1000) DEFAULT NULL,
    response_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
        CHECK (response_json IS NULL OR JSON_VALID(response_json)),
    attempted_by BIGINT UNSIGNED DEFAULT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    succeeded_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vf_flight_exports_operation (operation_id),
    KEY idx_vf_flight_exports_flid (vf_flid),
    KEY idx_vf_flight_exports_status_date (status, attempted_at),
    CONSTRAINT fk_vf_flight_exports_entry
        FOREIGN KEY (accounting_entry_id)
        REFERENCES accounting_entries (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_vf_flight_exports_operation
        FOREIGN KEY (operation_id)
        REFERENCES operations (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_vf_flight_exports_user
        FOREIGN KEY (attempted_by)
        REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
