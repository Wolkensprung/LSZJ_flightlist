<?php
/**
 * import_ktrax.php
 *
 * Importiert KTrax-Logbook-Daten fuer einen Flugtag.
 *
 * Erzeugt:
 * - raw_flights: unveraenderte KTrax-Rohdaten
 * - operations: Segelflug-Operationen und Schleppflugzeug-Alleinfluege
 * - tow_segments: abrechenbare Schleppsegmente
 * - accounting_entries: Freigabe- und Exportbuchungen
 *
 * Fachlogik:
 * - Segelflugzeuge erzeugen glider_flight Buchungen.
 * - Schlepps erzeugen zusaetzlich tow_charge Buchungen mit vf_flight_type_id = 3 (F-Schlepp).
 * - Schleppflugzeug-Fluege ohne gekoppelten Segelflug erzeugen towplane_own Buchungen.
 *   Diese koennen spaeter als Privatflug, Werkverkehr oder Ueberflug klassifiziert werden.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

function first_non_empty(array $data, array $keys): ?string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }

        $value = $data[$key];

        if (is_array($value)) {
            foreach (['name', 'full_name', 'display_name', 'label', 'text'] as $nameKey) {
                if (!empty($value[$nameKey])) {
                    return trim((string) $value[$nameKey]);
                }
            }
            continue;
        }

        if ($value !== null && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }

    return null;
}

function extract_glider_pilot_name(array $sortie): ?string
{
    return first_non_empty($sortie, ['pilot_name', 'pilot', 'pic', 'captain', 'cmdr', 'pilotName']);
}

function extract_instructor_name(array $sortie): ?string
{
    return first_non_empty($sortie, ['instructor_name', 'instructor', 'teacher', 'fi', 'flight_instructor']);
}

function extract_tow_pilot_name(array $sortie): ?string
{
    return first_non_empty($sortie, ['tow_pilot_name', 'tow_pilot', 'pilot_name', 'pilot', 'pic', 'captain', 'cmdr', 'pilotName']);
}


function ktrax_timezone_offset_hours(string $date, string $timezoneName): float
{
    $timezone = new DateTimeZone($timezoneName);
    // Use midday to avoid edge cases around daylight-saving transitions at night.
    $dt = new DateTime($date . ' 12:00:00', $timezone);
    return $timezone->getOffset($dt) / 3600;
}

$config = app_config();
$pdo = db();

$date = $_GET['date'] ?? date('Y-m-d');
$airfield = strtolower($_GET['airfield'] ?? $config['ktrax']['default_airfield']);
$timezoneName = $config['ktrax']['timezone'] ?? $config['app']['timezone'] ?? 'Europe/Zurich';
$ktraxTzOffset = ktrax_timezone_offset_hours($date, $timezoneName);

$url = $config['ktrax']['base_url'] . '?' . http_build_query([
    'db' => 'sortie',
    'query_type' => 'ap',
    'id' => strtoupper($airfield),
    'tz' => $ktraxTzOffset,
    'dbeg' => $date,
    'dend' => $date,
    'apikey' => $config['ktrax']['api_key'],
]);

$json = file_get_contents($url);

if ($json === false) {
    json_response(['error' => 'KTrax konnte nicht gelesen werden'], 502);
}

$payload = json_decode($json, true);

if (!is_array($payload)) {
    json_response(['error' => 'KTrax Antwort ist kein JSON'], 502);
}

$sorties = $payload['sorties'] ?? [];

$pdo->beginTransaction();

try {
    /**
     * 1. Rohdaten idempotent speichern.
     */
    $rawStmt = $pdo->prepare(
        "INSERT INTO raw_flights
            (source, source_uid, flight_date, aircraft_callsign, aircraft_type,
             takeoff_time, takeoff_airfield, landing_time, landing_airfield,
             duration_text, tow_id, tow_callsign, tow_height_m, raw_json)
         VALUES
            ('ktrax', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            raw_json = VALUES(raw_json),
            landing_time = VALUES(landing_time),
            landing_airfield = VALUES(landing_airfield),
            duration_text = VALUES(duration_text),
            tow_height_m = VALUES(tow_height_m)"
    );

    foreach ($sorties as $idx => $f) {
        // KTrax dokumentiert seq als eindeutige Sequenznummer pro Sortie.
        // Eine FLARM-/Aircraft-ID ist nicht flugspezifisch und darf deshalb
        // nie als alleiniger Dublettenschluessel verwendet werden.
        $sourceUid = isset($f['seq']) && (string) $f['seq'] !== ''
            ? (string) $f['seq']
            : 'fallback:' . sha1(json_encode([
                $f['date'] ?? $date,
                $f['id'] ?? '',
                $f['cs'] ?? '',
                $f['type'] ?? '',
                $f['tkof']['time'] ?? '',
                $f['tkof']['loc'] ?? '',
                $f['ldg']['time'] ?? '',
                $f['ldg']['loc'] ?? '',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $rawStmt->execute([
            $sourceUid,
            $f['date'] ?? $date,
            $f['cs'] ?? '',
            $f['type'] ?? null,
            $f['tkof']['time'] ?? null,
            normalize_airfield($f['tkof']['loc'] ?? ''),
            $f['ldg']['time'] ?? null,
            normalize_airfield($f['ldg']['loc'] ?? ''),
            $f['dt'] ?? null,
            $f['tow_id'] ?? null,
            $f['tow_cs'] ?? null,
            isset($f['dalt']) ? (int) $f['dalt'] : null,
            json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * 2. Rohdaten des Tages laden.
     */
    $rows = $pdo->prepare(
        "SELECT *
         FROM raw_flights
         WHERE source = 'ktrax'
           AND flight_date = ?
         ORDER BY takeoff_time, aircraft_callsign"
    );
    $rows->execute([$date]);
    $raw = $rows->fetchAll();

    /**
     * 3. Vorbereitete Statements.
     */
    $existingGliderOperationStmt = $pdo->prepare(
        "SELECT operation_id
         FROM operation_raw_links
         WHERE raw_flight_id = ?
           AND role = 'glider'
         LIMIT 1"
    );

    $existingTowplaneOperationStmt = $pdo->prepare(
        "SELECT operation_id
         FROM operation_raw_links
         WHERE raw_flight_id = ?
           AND role = 'towplane'
         LIMIT 1"
    );

    // Primaere Paarung gemaess KTrax-Schnittstelle: glider.tow_seq -> towplane.seq.
    $findTowRawBySeqStmt = $pdo->prepare(
        "SELECT *
         FROM raw_flights
         WHERE source = 'ktrax'
           AND source_uid = ?
           AND aircraft_type = 2
         LIMIT 1"
    );

    // Fallback nur fuer unvollstaendige historische KTrax-Datensaetze ohne tow_seq.
    $findTowRawFallbackStmt = $pdo->prepare(
        "SELECT *
         FROM raw_flights
         WHERE source = 'ktrax'
           AND flight_date = ?
           AND aircraft_type = 2
           AND aircraft_callsign = ?
           AND tow_callsign = ?
           AND takeoff_time = ?
         LIMIT 1"
    );

    $insertGliderOperationStmt = $pdo->prepare(
        "INSERT INTO operations
            (operation_date, kind, glider_callsign, glider_pilot_name, instructor_name,
             tow_callsign, takeoff_time, takeoff_airfield, glider_landing_time,
             glider_landing_airfield, tow_height_m, created_from, approval_status)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ktrax', 'pending')"
    );

    $insertTowplaneOnlyOperationStmt = $pdo->prepare(
        "INSERT INTO operations
            (operation_date, kind, glider_callsign, glider_pilot_name, instructor_name,
             tow_callsign, takeoff_time, takeoff_airfield, glider_landing_time,
             glider_landing_airfield, tow_height_m, created_from, approval_status)
         VALUES
            (?, 'towplane_only', NULL, NULL, NULL, ?, ?, ?, NULL, NULL, NULL, 'ktrax', 'pending')"
    );

    $insertRawLinkStmt = $pdo->prepare(
        "INSERT IGNORE INTO operation_raw_links
            (operation_id, raw_flight_id, role)
         VALUES
            (?, ?, ?)"
    );

    $insertTowSegmentStmt = $pdo->prepare(
        "INSERT INTO tow_segments
            (operation_id, glider_raw_flight_id, tow_raw_flight_id,
             glider_callsign, tow_callsign, tow_pilot_name, segment_start, segment_end,
             tow_minutes, tow_height_m, cost_center, approval_status)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
         ON DUPLICATE KEY UPDATE
            tow_raw_flight_id = VALUES(tow_raw_flight_id),
            tow_pilot_name = VALUES(tow_pilot_name),
            segment_start = VALUES(segment_start),
            segment_end = VALUES(segment_end),
            tow_minutes = VALUES(tow_minutes),
            tow_height_m = VALUES(tow_height_m),
            updated_at = CURRENT_TIMESTAMP"
    );

    $insertGliderEntryStmt = $pdo->prepare(
        "INSERT INTO accounting_entries
            (operation_id, entry_type, callsign, pilot_name, attendant_name,
             departure_time, departure_location, arrival_time, arrival_location,
             flight_minutes, landing_count, start_type, comment, tow_height_m,
             tow_callsign, vf_flight_type_id, approval_role, approval_status)
         VALUES
            (?, 'glider_flight', ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, '', ?, ?, NULL,
             'glider_pilot', 'pending')"
    );

    $insertTowChargeEntryStmt = $pdo->prepare(
        "INSERT INTO accounting_entries
            (operation_id, entry_type, callsign, tow_pilot_name, departure_time,
             departure_location, flight_minutes, tow_minutes, landing_count,
             start_type, charge_mode, invoiced, tow_height_m, tow_callsign,
             vf_flight_type_id, approval_role, comment, approval_status)
         VALUES
            (?, 'tow_charge', ?, ?, ?, ?, NULL, ?, 1, 3, 2, 0, ?, ?, 3,
             'tow_pilot', 'Schleppanteil bitte pruefen', 'pending')"
    );

    $insertTowplaneOwnEntryStmt = $pdo->prepare(
        "INSERT INTO accounting_entries
            (operation_id, entry_type, callsign, tow_pilot_name, departure_time,
             departure_location, arrival_time, arrival_location, flight_minutes,
             landing_count, start_type, charge_mode, invoiced, comment,
             vf_flight_type_id, approval_role, approval_status)
         VALUES
            (?, 'towplane_own', ?, ?, ?, ?, ?, ?, ?, 1, 1, 2, 0,
             'Schleppflugzeug ohne gekoppelten Segelflug - Flugart bitte pruefen',
             NULL, 'tow_pilot', 'pending')"
    );

    $createdOperations = 0;
    $skippedExistingOperations = 0;
    $createdTowSegments = 0;
    $createdTowplaneOwnEntries = 0;
    $towPairsBySeq = 0;
    $towPairsByFallback = 0;
    $towPairsMissing = 0;

    /**
     * 4. Segelflug-Operationen und F-Schlepp-Buchungen erzeugen.
     */
    foreach ($raw as $r) {
        if ((int) $r['aircraft_type'] !== 1) {
            continue;
        }

        $existingGliderOperationStmt->execute([$r['id']]);
        if ($existingGliderOperationStmt->fetch()) {
            $skippedExistingOperations++;
            continue;
        }

        $rawJson = json_decode($r['raw_json'] ?? '{}', true);
        $rawJson = is_array($rawJson) ? $rawJson : [];

        $gliderPilotName = extract_glider_pilot_name($rawJson);
        $instructorName = extract_instructor_name($rawJson);
        $kind = !empty($r['tow_callsign']) ? 'glider_tow' : 'self_launch';

        $towRawId = null;
        $towPilotName = null;
        $towMinutes = null;
        $towSegmentStart = null;
        $towSegmentEnd = null;

        if ($kind === 'glider_tow') {
            $towRaw = null;
            $towSeq = isset($rawJson['tow_seq']) && (string) $rawJson['tow_seq'] !== ''
                ? (string) $rawJson['tow_seq']
                : null;

            if ($towSeq !== null) {
                $findTowRawBySeqStmt->execute([$towSeq]);
                $towRaw = $findTowRawBySeqStmt->fetch() ?: null;
                if ($towRaw) {
                    $towPairsBySeq++;
                }
            }

            if (!$towRaw) {
                $findTowRawFallbackStmt->execute([
                    $r['flight_date'],
                    $r['tow_callsign'],
                    $r['aircraft_callsign'],
                    $r['takeoff_time'],
                ]);
                $towRaw = $findTowRawFallbackStmt->fetch() ?: null;
                if ($towRaw) {
                    $towPairsByFallback++;
                }
            }

            if ($towRaw) {
                $towRawId = (int) $towRaw['id'];
                $towRawJson = json_decode($towRaw['raw_json'] ?? '{}', true);
                $towRawJson = is_array($towRawJson) ? $towRawJson : [];
                $towPilotName = extract_tow_pilot_name($towRawJson);
                $towMinutes = minutes_between($towRaw['flight_date'], $towRaw['takeoff_time'], $towRaw['landing_time']);
                $towSegmentStart = dt_string($towRaw['flight_date'], $towRaw['takeoff_time']);
                $towSegmentEnd = dt_string($towRaw['flight_date'], $towRaw['landing_time']);
            } else {
                // Paarung fehlt: Operation bleibt korrigierbar, aber es wird kein
                // erfundener Schleppflug verknuepft.
                $towPairsMissing++;
                $towSegmentStart = dt_string($r['flight_date'], $r['takeoff_time']);
            }
        }

        $insertGliderOperationStmt->execute([
            $r['flight_date'],
            $kind,
            $r['aircraft_callsign'],
            $gliderPilotName,
            $instructorName,
            $r['tow_callsign'],
            $r['takeoff_time'],
            $r['takeoff_airfield'],
            $r['landing_time'],
            $r['landing_airfield'],
            $r['tow_height_m'],
        ]);

        $opId = (int) $pdo->lastInsertId();
        $createdOperations++;

        $insertRawLinkStmt->execute([$opId, $r['id'], 'glider']);

        if ($towRawId) {
            $insertRawLinkStmt->execute([$opId, $towRawId, 'towplane']);
        }

        if ($kind === 'glider_tow') {
            $insertTowSegmentStmt->execute([
                $opId,
                $r['id'],
                $towRawId,
                $r['aircraft_callsign'],
                $r['tow_callsign'],
                $towPilotName,
                $towSegmentStart,
                $towSegmentEnd,
                $towMinutes,
                $r['tow_height_m'],
                'tow',
            ]);
            $createdTowSegments++;
        }

        $gliderMinutes = minutes_between($r['flight_date'], $r['takeoff_time'], $r['landing_time']);

        $insertGliderEntryStmt->execute([
            $opId,
            $r['aircraft_callsign'],
            $gliderPilotName,
            $instructorName,
            dt_string($r['flight_date'], $r['takeoff_time']),
            $r['takeoff_airfield'],
            dt_string($r['flight_date'], $r['landing_time']),
            $r['landing_airfield'],
            $gliderMinutes,
            $kind === 'glider_tow' ? 3 : 1,
            $r['tow_height_m'],
            $r['tow_callsign'],
        ]);

        if ($kind === 'glider_tow') {
            $insertTowChargeEntryStmt->execute([
                $opId,
                $r['aircraft_callsign'],
                $towPilotName,
                dt_string($r['flight_date'], $r['takeoff_time']),
                $r['takeoff_airfield'],
                $towMinutes,
                $r['tow_height_m'],
                $r['tow_callsign'],
            ]);
        }
    }

    /**
     * 5. Schleppflugzeug-Fluege ohne gekoppelten Segelflug erzeugen.
     *
     * Ein aircraft_type = 2 Rohflug ist gekoppelt, wenn bereits ein
     * operation_raw_links Eintrag mit role = towplane existiert.
     * Nicht gekoppelte Typ-2-Fluege werden als towplane_own Buchung erzeugt.
     */
    foreach ($raw as $r) {
        if ((int) $r['aircraft_type'] !== 2) {
            continue;
        }

        $existingTowplaneOperationStmt->execute([$r['id']]);
        if ($existingTowplaneOperationStmt->fetch()) {
            continue;
        }

        $rawJson = json_decode($r['raw_json'] ?? '{}', true);
        $rawJson = is_array($rawJson) ? $rawJson : [];
        $towPilotName = extract_tow_pilot_name($rawJson);
        $towplaneMinutes = minutes_between($r['flight_date'], $r['takeoff_time'], $r['landing_time']);

        $insertTowplaneOnlyOperationStmt->execute([
            $r['flight_date'],
            $r['aircraft_callsign'],
            $r['takeoff_time'],
            $r['takeoff_airfield'],
        ]);

        $opId = (int) $pdo->lastInsertId();
        $createdOperations++;

        $insertRawLinkStmt->execute([$opId, $r['id'], 'towplane']);

        $insertTowplaneOwnEntryStmt->execute([
            $opId,
            $r['aircraft_callsign'],
            $towPilotName,
            dt_string($r['flight_date'], $r['takeoff_time']),
            $r['takeoff_airfield'],
            dt_string($r['flight_date'], $r['landing_time']),
            $r['landing_airfield'],
            $towplaneMinutes,
        ]);

        $createdTowplaneOwnEntries++;
    }

    $pdo->commit();

    json_response([
        'ok' => true,
        'date' => $date,
        'ktrax_timezone' => $timezoneName,
        'ktrax_tz_offset' => $ktraxTzOffset,
        'raw_imported' => count($sorties),
        'operations_created' => $createdOperations,
        'operations_skipped_existing' => $skippedExistingOperations,
        'tow_segments_created' => $createdTowSegments,
        'towplane_own_entries_created' => $createdTowplaneOwnEntries,
        'tow_pairs_by_seq' => $towPairsBySeq,
        'tow_pairs_by_fallback' => $towPairsByFallback,
        'tow_pairs_missing' => $towPairsMissing,
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_response(['error' => $e->getMessage()], 500);
}
