-- Paket D2: kontrollierte Aktualisierung bestehender VF-Fluege
CREATE TABLE IF NOT EXISTS vf_flight_sync_snapshots (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 accounting_entry_id BIGINT UNSIGNED NOT NULL,
 operation_id BIGINT UNSIGNED NULL,
 vf_flid BIGINT UNSIGNED NOT NULL,
 source ENUM('add','edit') NOT NULL,
 source_record_id BIGINT UNSIGNED NULL,
 payload_hash CHAR(64) NOT NULL,
 payload_json LONGTEXT NOT NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 KEY idx_vf_snapshot_entry (accounting_entry_id,id),
 KEY idx_vf_snapshot_flid (vf_flid,id),
 UNIQUE KEY uq_vf_snapshot_source (source,source_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vf_flight_edit_runs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 edit_token CHAR(64) NOT NULL,
 accounting_entry_id BIGINT UNSIGNED NOT NULL,
 operation_id BIGINT UNSIGNED NULL,
 vf_flight_export_id BIGINT UNSIGNED NOT NULL,
 vf_flight_change_check_id BIGINT UNSIGNED NOT NULL,
 vf_flight_local_change_id BIGINT UNSIGNED NOT NULL,
 vf_flid BIGINT UNSIGNED NOT NULL,
 row_version BIGINT UNSIGNED NOT NULL,
 status ENUM('previewed','sending','edit_accepted','verifying','success','skipped_identical','blocked_invoiced','failed','conflict','verification_failed','reconciliation_required','aborted') NOT NULL,
 old_payload_hash CHAR(64) NOT NULL,
 sent_payload_hash CHAR(64) NOT NULL,
 old_payload_json LONGTEXT NOT NULL,
 sent_payload_json LONGTEXT NOT NULL,
 differences_json LONGTEXT NOT NULL,
 preflight_response_json LONGTEXT NULL,
 preflight_invoice_count INT UNSIGNED NULL,
 preflight_checked_at DATETIME NULL,
 edit_response_json LONGTEXT NULL,
 verification_response_json LONGTEXT NULL,
 verification_checked_at DATETIME NULL,
 http_status SMALLINT UNSIGNED NULL,
 error_message VARCHAR(1000) NULL,
 transfer_reason VARCHAR(1000) NOT NULL,
 requested_by BIGINT UNSIGNED NOT NULL,
 requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_vf_edit_token(edit_token),
 KEY idx_vf_edit_entry(accounting_entry_id,id),
 KEY idx_vf_edit_status(status,requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Einmalige Baseline-Uebernahme aus erfolgreichen C4-Exporten.
INSERT IGNORE INTO vf_flight_sync_snapshots
(accounting_entry_id,operation_id,vf_flid,source,source_record_id,payload_hash,payload_json,created_at)
SELECT accounting_entry_id,operation_id,vf_flid,'add',id,
 SHA2(payload_json,256),payload_json,COALESCE(succeeded_at,NOW())
FROM vf_flight_exports
WHERE status='success' AND vf_flid IS NOT NULL AND payload_json IS NOT NULL;
