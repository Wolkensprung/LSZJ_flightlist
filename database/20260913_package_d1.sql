-- LSZJ Paket D1
-- Aenderungserkennung und Admin-Korrektur fuer bereits exportierte Fluege
-- Zielsystem: MariaDB
--
-- Eigenschaften:
-- - idempotent fuer Tabellen, Spalten und Indizes
-- - keine Nutzdaten werden geloescht oder veraendert
-- - keine Vereinsflieger-Schreiboperation
-- - Exporthistorie bleibt unveraendert
-- - Audit-Daten bleiben auch erhalten, falls ein lokaler Datensatz spaeter fehlt

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

/*
 * 1. Aktueller lokaler Synchronisationszustand am Buchungseintrag.
 *
 * row_version ist fuer optimistische Parallelitaetskontrolle vorgesehen.
 * Der spaetere D1-Update-Endpunkt muss row_version bei jeder erfolgreichen
 * Admin-Korrektur atomar erhoehen.
 */
ALTER TABLE accounting_entries
    ADD COLUMN IF NOT EXISTS vf_sync_status
        ENUM(
            'in_sync',
            'local_change_pending',
            'blocked_person_resolution',
            'blocked_payload_generation',
            'reconciliation_required',
            'local_entry_missing',
            'local_operation_missing'
        )
        NULL
        COMMENT 'D1-Synchronisationszustand zum letzten erfolgreichen VF-Export'
        AFTER vf_exported_at,
    ADD COLUMN IF NOT EXISTS vf_sync_checked_at
        DATETIME NULL
        COMMENT 'Zeitpunkt der letzten D1-Pruefung'
        AFTER vf_sync_status,
    ADD COLUMN IF NOT EXISTS vf_local_changed_at
        DATETIME NULL
        COMMENT 'Letzte lokale Admin-Korrektur nach VF-Export'
        AFTER vf_sync_checked_at,
    ADD COLUMN IF NOT EXISTS vf_local_changed_by
        BIGINT UNSIGNED NULL
        COMMENT 'Benutzer-ID der letzten lokalen Admin-Korrektur'
        AFTER vf_local_changed_at,
    ADD COLUMN IF NOT EXISTS vf_local_change_reason
        VARCHAR(1000) NULL
        COMMENT 'Grund der letzten lokalen Admin-Korrektur'
        AFTER vf_local_changed_by,
    ADD COLUMN IF NOT EXISTS row_version
        BIGINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Optimistische Parallelitaetskontrolle'
        AFTER vf_local_change_reason,
    ADD INDEX IF NOT EXISTS idx_accounting_entries_vf_sync_status
        (vf_sync_status),
    ADD INDEX IF NOT EXISTS idx_accounting_entries_vf_local_changed_by
        (vf_local_changed_by);

/*
 * 2. Revisionsfaehiges Audit jeder lokalen Admin-Korrektur.
 *
 * accounting_entry_id, operation_id und vf_flight_export_id werden bewusst
 * nicht mit ON DELETE CASCADE verknuepft. Das Audit muss auch dann erhalten
 * bleiben, wenn spaeter ein lokaler Datensatz fehlt. Die Spalten tragen
 * deshalb Indizes, aber keine loeschenden Fremdschluessel.
 */
CREATE TABLE IF NOT EXISTS vf_flight_local_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    accounting_entry_id BIGINT UNSIGNED NOT NULL,
    operation_id BIGINT UNSIGNED NOT NULL,
    vf_flight_export_id BIGINT UNSIGNED NOT NULL,
    vf_flid VARCHAR(64) NOT NULL,
    changed_by BIGINT UNSIGNED NULL,
    change_reason VARCHAR(1000) NOT NULL,
    old_row_version BIGINT UNSIGNED NOT NULL,
    new_row_version BIGINT UNSIGNED NOT NULL,
    old_local_data_json LONGTEXT
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    new_local_data_json LONGTEXT
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    old_payload_json LONGTEXT
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    new_payload_json LONGTEXT
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    differences_json LONGTEXT
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    sync_status ENUM(
        'in_sync',
        'local_change_pending',
        'blocked_person_resolution',
        'blocked_payload_generation',
        'reconciliation_required',
        'local_entry_missing',
        'local_operation_missing'
    ) NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vf_local_changes_entry (accounting_entry_id, changed_at),
    KEY idx_vf_local_changes_operation (operation_id, changed_at),
    KEY idx_vf_local_changes_export (vf_flight_export_id),
    KEY idx_vf_local_changes_flid (vf_flid),
    KEY idx_vf_local_changes_changed_by (changed_by),
    KEY idx_vf_local_changes_sync_status (sync_status, changed_at),
    CONSTRAINT chk_vf_local_changes_reason
        CHECK (CHAR_LENGTH(TRIM(change_reason)) BETWEEN 5 AND 1000),
    CONSTRAINT chk_vf_local_changes_old_local_json
        CHECK (JSON_VALID(old_local_data_json)),
    CONSTRAINT chk_vf_local_changes_new_local_json
        CHECK (JSON_VALID(new_local_data_json)),
    CONSTRAINT chk_vf_local_changes_old_payload_json
        CHECK (old_payload_json IS NULL OR JSON_VALID(old_payload_json)),
    CONSTRAINT chk_vf_local_changes_new_payload_json
        CHECK (new_payload_json IS NULL OR JSON_VALID(new_payload_json)),
    CONSTRAINT chk_vf_local_changes_differences_json
        CHECK (differences_json IS NULL OR JSON_VALID(differences_json)),
    CONSTRAINT chk_vf_local_changes_version_increment
        CHECK (new_row_version = old_row_version + 1)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

/*
 * 3. Historie der D1-Vergleichspruefungen.
 *
 * Jede Pruefung erzeugt einen neuen Snapshot. Der neueste Check pro Export
 * kann ueber den Index (vf_flight_export_id, checked_at) ermittelt werden.
 */
CREATE TABLE IF NOT EXISTS vf_flight_export_change_checks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    accounting_entry_id BIGINT UNSIGNED NOT NULL,
    operation_id BIGINT UNSIGNED NULL,
    vf_flight_export_id BIGINT UNSIGNED NOT NULL,
    vf_flid VARCHAR(64) NOT NULL,
    check_status ENUM(
        'in_sync',
        'local_change_pending',
        'blocked_person_resolution',
        'blocked_payload_generation',
        'reconciliation_required',
        'local_entry_missing',
        'local_operation_missing'
    ) NOT NULL,
    old_payload_hash CHAR(64) NOT NULL,
    current_payload_hash CHAR(64) NULL,
    difference_count INT UNSIGNED NOT NULL DEFAULT 0,
    highest_risk ENUM('low', 'medium', 'high') NULL,
    differences_json LONGTEXT
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    current_payload_json LONGTEXT
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    person_resolution_json LONGTEXT
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    special_case_json LONGTEXT
        CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    error_message VARCHAR(1000) NULL,
    checked_by BIGINT UNSIGNED NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vf_change_checks_entry (accounting_entry_id, checked_at),
    KEY idx_vf_change_checks_operation (operation_id, checked_at),
    KEY idx_vf_change_checks_export (vf_flight_export_id, checked_at),
    KEY idx_vf_change_checks_flid (vf_flid),
    KEY idx_vf_change_checks_status (check_status, checked_at),
    KEY idx_vf_change_checks_risk (highest_risk, checked_at),
    KEY idx_vf_change_checks_checked_by (checked_by),
    CONSTRAINT chk_vf_change_checks_old_hash
        CHECK (old_payload_hash REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_vf_change_checks_current_hash
        CHECK (
            current_payload_hash IS NULL
            OR current_payload_hash REGEXP '^[0-9a-f]{64}$'
        ),
    CONSTRAINT chk_vf_change_checks_differences_json
        CHECK (differences_json IS NULL OR JSON_VALID(differences_json)),
    CONSTRAINT chk_vf_change_checks_current_payload_json
        CHECK (
            current_payload_json IS NULL
            OR JSON_VALID(current_payload_json)
        ),
    CONSTRAINT chk_vf_change_checks_person_resolution_json
        CHECK (
            person_resolution_json IS NULL
            OR JSON_VALID(person_resolution_json)
        ),
    CONSTRAINT chk_vf_change_checks_special_case_json
        CHECK (
            special_case_json IS NULL
            OR JSON_VALID(special_case_json)
        ),
    CONSTRAINT chk_vf_change_checks_difference_count
        CHECK (
            (check_status = 'in_sync' AND difference_count = 0)
            OR check_status <> 'in_sync'
        )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

/*
 * 4. Fremdschluessel zu Benutzern nur dann anlegen, wenn sie noch fehlen.
 * ON DELETE SET NULL erhaelt die Audit-Historie bei geloeschtem Benutzer.
 */
DELIMITER $$

DROP PROCEDURE IF EXISTS d1_add_optional_foreign_keys$$
CREATE PROCEDURE d1_add_optional_foreign_keys()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'fk_accounting_entries_vf_local_changed_by'
    ) THEN
        ALTER TABLE accounting_entries
            ADD CONSTRAINT fk_accounting_entries_vf_local_changed_by
            FOREIGN KEY (vf_local_changed_by)
            REFERENCES users(id)
            ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'fk_vf_local_changes_changed_by'
    ) THEN
        ALTER TABLE vf_flight_local_changes
            ADD CONSTRAINT fk_vf_local_changes_changed_by
            FOREIGN KEY (changed_by)
            REFERENCES users(id)
            ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'fk_vf_change_checks_checked_by'
    ) THEN
        ALTER TABLE vf_flight_export_change_checks
            ADD CONSTRAINT fk_vf_change_checks_checked_by
            FOREIGN KEY (checked_by)
            REFERENCES users(id)
            ON DELETE SET NULL;
    END IF;
END$$

CALL d1_add_optional_foreign_keys()$$
DROP PROCEDURE d1_add_optional_foreign_keys$$

DELIMITER ;

/*
 * 5. Bestehende erfolgreiche Exporte initial als in_sync markieren.
 *
 * Nur Datensaetze ohne bisherigen D1-Status werden initialisiert. Diese
 * Initialisierung behauptet nicht, dass VF live abgefragt wurde; sie setzt
 * lediglich den Ausgangspunkt vor der ersten lokalen D1-Korrektur.
 */
UPDATE accounting_entries AS ae
SET
    ae.vf_sync_status = 'in_sync',
    ae.vf_sync_checked_at = COALESCE(ae.vf_exported_at, ae.exported_at)
WHERE ae.vf_sync_status IS NULL
  AND (
      ae.approval_status = 'exported'
      OR ae.vf_exported_at IS NOT NULL
  )
  AND EXISTS (
      SELECT 1
      FROM vf_flight_exports AS vfe
      WHERE vfe.accounting_entry_id = ae.id
        AND vfe.status = 'success'
        AND vfe.vf_flid IS NOT NULL
  );

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- Ende Paket D1
