-- LSZJ Flightlist
-- Migration: 001_create_user_passkeys.sql
-- Zweck: Passkey-/WebAuthn-Credentials pro Benutzer speichern.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS user_passkeys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    user_id BIGINT UNSIGNED NOT NULL,

    credential_id VARBINARY(1024) NOT NULL,
    public_key TEXT NOT NULL,
    user_handle VARBINARY(64) NOT NULL,

    sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,

    transports VARCHAR(255) DEFAULT NULL,
    aaguid CHAR(36) DEFAULT NULL,
    device_name VARCHAR(255) DEFAULT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uq_user_passkey_credential (credential_id),

    KEY idx_user_passkeys_user (user_id),
    KEY idx_user_passkeys_active (user_id, revoked_at),

    CONSTRAINT fk_user_passkeys_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
