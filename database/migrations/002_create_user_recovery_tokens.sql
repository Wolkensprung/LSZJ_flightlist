-- LSZJ Flightlist
-- Migration: 002_create_user_recovery_tokens.sql
-- Zweck: Einmalige, zeitlich begrenzte Recovery-Tokens speichern.
-- In der Datenbank wird ausschliesslich der SHA-256-Hash des Tokens gespeichert.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS user_recovery_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,

    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uq_recovery_token_hash (token_hash),

    KEY idx_recovery_user (user_id),
    KEY idx_recovery_expiry (expires_at),

    CONSTRAINT fk_recovery_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
