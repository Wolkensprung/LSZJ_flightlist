-- QR-Login V2 fÃ¼r LSZJ Flightlist.
-- ACHTUNG: CREATE TABLE IF NOT EXISTS verÃ¤ndert eine bereits vorhandene alte Tabelle nicht.
-- Vor AusfÃ¼hrung zuerst SHOW COLUMNS FROM qr_login_sessions; prÃ¼fen.

CREATE TABLE IF NOT EXISTS qr_login_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    device_type ENUM('C_BUERO') NOT NULL DEFAULT 'C_BUERO',
    status ENUM('pending','approved','consumed','expired','cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    approved_at DATETIME DEFAULT NULL,
    consumed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qr_login_token_hash (token_hash),
    KEY idx_qr_status_expiry (status, expires_at),
    KEY idx_qr_user (user_id),
    CONSTRAINT fk_qr_login_v2_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
