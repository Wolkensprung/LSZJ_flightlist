USE lszj_flightlist;

ALTER TABLE duty_officer_shifts
    ADD COLUMN IF NOT EXISTS ended_by BIGINT UNSIGNED NULL AFTER end_time;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'duty_officer_shifts'
      AND CONSTRAINT_NAME = 'fk_shift_ended_by'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @fk_sql := IF(
    @fk_exists = 0,
    'ALTER TABLE duty_officer_shifts ADD CONSTRAINT fk_shift_ended_by FOREIGN KEY (ended_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);

PREPARE stmt FROM @fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE INDEX IF NOT EXISTS idx_shift_open_start
    ON duty_officer_shifts (end_time, start_time);
