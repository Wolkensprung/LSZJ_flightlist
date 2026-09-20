-- D2M: auditierter manueller VF-Synchronisationsabschluss
CREATE TABLE IF NOT EXISTS vf_manual_sync_runs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 sync_token CHAR(64) NOT NULL,
 accounting_entry_id BIGINT UNSIGNED NOT NULL,
 operation_id BIGINT UNSIGNED NULL,
 vf_flight_export_id BIGINT UNSIGNED NOT NULL,
 vf_flight_change_check_id BIGINT UNSIGNED NOT NULL,
 vf_flight_local_change_id BIGINT UNSIGNED NOT NULL,
 original_vf_flid BIGINT UNSIGNED NOT NULL,
 active_vf_flid BIGINT UNSIGNED NOT NULL,
 row_version BIGINT UNSIGNED NOT NULL,
 sync_kind ENUM('manual_edit','billing_replacement') NOT NULL,
 status ENUM('previewed','completed','conflict','cancelled') NOT NULL,
 billing_status ENUM('not_invoiced','invoiced_replaced') NOT NULL,
 old_payload_hash CHAR(64) NOT NULL,
 confirmed_payload_hash CHAR(64) NOT NULL,
 old_payload_json LONGTEXT NOT NULL,
 confirmed_payload_json LONGTEXT NOT NULL,
 differences_json LONGTEXT NOT NULL,
 correction_reason VARCHAR(1000) NOT NULL,
 vf_checked_by BIGINT UNSIGNED NOT NULL,
 vf_checked_at DATETIME NOT NULL,
 confirmed_by BIGINT UNSIGNED NOT NULL,
 confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 notes VARCHAR(1000) NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_vf_manual_sync_token(sync_token),
 KEY idx_vf_manual_sync_entry(accounting_entry_id,id),
 KEY idx_vf_manual_sync_active_flid(active_vf_flid,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE vf_flight_sync_snapshots
 MODIFY COLUMN source ENUM('add','edit','manual_edit','billing_replacement') NOT NULL;
