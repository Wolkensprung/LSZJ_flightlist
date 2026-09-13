-- Paket C4: Tagesexport-Laeufe und Abstimmungsstatus
CREATE TABLE IF NOT EXISTS vf_flight_export_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_token CHAR(36) NOT NULL,
  flight_date DATE NOT NULL,
  status ENUM('running','completed','completed_with_errors','aborted') NOT NULL DEFAULT 'running',
  requested_count INT UNSIGNED NOT NULL DEFAULT 0,
  successful_count INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  reconciliation_count INT UNSIGNED NOT NULL DEFAULT 0,
  started_by BIGINT UNSIGNED DEFAULT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vf_flight_export_runs_token (run_token),
  KEY idx_vf_flight_export_runs_date (flight_date, started_at),
  KEY idx_vf_flight_export_runs_status (status),
  CONSTRAINT fk_vf_flight_export_runs_user FOREIGN KEY (started_by)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE vf_flight_exports
  ADD COLUMN IF NOT EXISTS run_id BIGINT UNSIGNED DEFAULT NULL AFTER operation_id,
  ADD COLUMN IF NOT EXISTS reconciliation_note VARCHAR(1000) DEFAULT NULL AFTER error_message,
  MODIFY COLUMN status ENUM('pending','success','failed','reconciliation_required') NOT NULL DEFAULT 'pending',
  ADD KEY IF NOT EXISTS idx_vf_flight_exports_run (run_id),
  ADD CONSTRAINT fk_vf_flight_exports_run FOREIGN KEY (run_id)
    REFERENCES vf_flight_export_runs(id) ON DELETE SET NULL;
