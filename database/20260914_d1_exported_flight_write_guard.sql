-- D1-Hardening: exportierte Fluege auf Datenbankebene schuetzen.
-- Alte Freigabe-Endpunkte koennen danach fachliche Felder exportierter
-- Buchungen nicht mehr veraendern. Nur der D1-Admin-Endpunkt setzt fuer
-- seine eigene DB-Verbindung kurzzeitig die Freigabevariable.

DELIMITER $$
DROP TRIGGER IF EXISTS trg_accounting_entries_protect_exported_update$$
CREATE TRIGGER trg_accounting_entries_protect_exported_update
BEFORE UPDATE ON accounting_entries
FOR EACH ROW
BEGIN
    IF (
        (OLD.approval_status = 'exported' OR OLD.vf_exported_at IS NOT NULL)
        AND COALESCE(@lszj_allow_exported_flight_update, 0) <> 1
        AND (
            NOT (NEW.callsign <=> OLD.callsign)
            OR NOT (NEW.pilot_name <=> OLD.pilot_name)
            OR NOT (NEW.attendant_name <=> OLD.attendant_name)
            OR NOT (NEW.tow_pilot_name <=> OLD.tow_pilot_name)
            OR NOT (NEW.departure_time <=> OLD.departure_time)
            OR NOT (NEW.departure_location <=> OLD.departure_location)
            OR NOT (NEW.arrival_time <=> OLD.arrival_time)
            OR NOT (NEW.arrival_location <=> OLD.arrival_location)
            OR NOT (NEW.flight_minutes <=> OLD.flight_minutes)
            OR NOT (NEW.landing_count <=> OLD.landing_count)
            OR NOT (NEW.start_type <=> OLD.start_type)
            OR NOT (NEW.comment <=> OLD.comment)
            OR NOT (NEW.tow_height_m <=> OLD.tow_height_m)
            OR NOT (NEW.tow_callsign <=> OLD.tow_callsign)
            OR NOT (NEW.tow_minutes <=> OLD.tow_minutes)
            OR NOT (NEW.motor_minutes <=> OLD.motor_minutes)
            OR NOT (NEW.vf_flight_type_id <=> OLD.vf_flight_type_id)
            OR NOT (NEW.charge_mode <=> OLD.charge_mode)
            OR NOT (NEW.invoiced <=> OLD.invoiced)
        )
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Exportierter Flug ist gesperrt; Korrektur nur ueber D1-Admin-Endpunkt.';
    END IF;
END$$
DELIMITER ;
