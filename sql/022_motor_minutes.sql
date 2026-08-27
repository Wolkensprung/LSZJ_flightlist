USE lszj_flightlist;

ALTER TABLE accounting_entries
    ADD COLUMN IF NOT EXISTS motor_minutes INT UNSIGNED NULL AFTER flight_minutes;

CREATE INDEX IF NOT EXISTS idx_accounting_entries_motor_minutes
    ON accounting_entries (motor_minutes);
