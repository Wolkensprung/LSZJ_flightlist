USE lszj_flightlist;

ALTER TABLE accounting_entries
    ADD COLUMN IF NOT EXISTS approved_by_user_id BIGINT UNSIGNED NULL AFTER approved_by;

ALTER TABLE operations
    ADD COLUMN IF NOT EXISTS approved_by_user_id BIGINT UNSIGNED NULL AFTER approved_by;

CREATE INDEX IF NOT EXISTS idx_accounting_entries_approved_by_user ON accounting_entries (approved_by_user_id);
CREATE INDEX IF NOT EXISTS idx_operations_approved_by_user ON operations (approved_by_user_id);
