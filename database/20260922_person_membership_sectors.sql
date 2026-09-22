-- LSZJ: Vereinsflieger-Mitgliedsstatus und Pilotensparten
ALTER TABLE pilots_master
    ADD COLUMN IF NOT EXISTS sectors_json LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS can_fly_glider TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS can_fly_motor TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS vf_person_synced_at DATETIME NULL,
    ADD INDEX IF NOT EXISTS idx_pilots_can_fly_glider (can_fly_glider),
    ADD INDEX IF NOT EXISTS idx_pilots_can_fly_motor (can_fly_motor);

-- Rückwärtskompatibel und sicher: Personen ohne bisher importierte Sparte bleiben
-- in beiden Pilotensuchen auswählbar. Der erste erfolgreiche Import setzt die
-- Fähigkeiten anhand von sector/Sparte explizit.
UPDATE pilots_master
SET can_fly_glider = 1, can_fly_motor = 1
WHERE sectors_json IS NULL OR sectors_json = '';
