<?php
declare(strict_types=1);

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
 * - Segelflugzeuge erzeugen glider_flight-Buchungen.
 * - Schlepps erzeugen zusaetzlich tow_charge-Buchungen mit
 *   vf_flight_type_id = 3 (F-Schlepp).
 * - Schleppflugzeug-Fluege ohne gekoppelten Segelflug erzeugen
 *   towplane_own-Buchungen.
 * - Unvollstaendige Rohfluege werden gespeichert, erzeugen aber keine
 *   Operation. Insbesondere wird eine fehlende Landezeit als NULL gespeichert.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function first_non_empty(array $data, array $keys): ?string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }

        $value = $data[$key];

        if (is_array($value)) {
            foreach (
                ['name', 'full_name', 'display_name', 'label', 'text']
                as $nameKey
            ) {
                if (!empty($value[$nameKey])) {
                    return trim((string)$value[$nameKey]);
                }
            }
            continue;
        }

        if ($value !== null && trim((string)$value) !== '') {
            return trim((string)$value);
        }
    }

    return null;
}

function extract_glider_pilot_name(array $sortie): ?string
{
    return first_non_empty(
        $sortie,
        ['pilot_name', 'pilot', 'pic', 'captain', 'cmdr', 'pilotName']
    );
}

function extract_instructor_name(array $sortie): ?string
{
    return first_non_empty(
        $sortie,
        [
            'instructor_name',
            'instructor',
            'teacher',
            'fi',
            'flight_instructor',
        ]
    );
}

function extract_tow_pilot_name(array $sortie): ?string
{
    return first_non_empty(
        $sortie,
        [
            'tow_pilot_name',
            'tow_pilot',
            'pilot_name',
            'pilot',
            'pic',
            'captain',
            'cmdr',
            'pilotName',
        ]
    );
}

function nullable_text(mixed $value): ?string
{
    if ($value === null || is_array($value)) {
        return null;
    }

    $value = trim((string)$value);

    return $value === '' ? null : $value;
}

function nullable_airfield(mixed $value): ?string
{
    $value = nullable_text($value);

    if ($value === null) {
        return null;
    }

    $normalized = trim((string)normalize_airfield($value));

    return $normalized === '' ? null : $normalized;
}

function normalize_ktrax_time(mixed $value, string $field): ?string
{
    $value = nullable_text($value);

    if ($value === null) {
        return null;
    }

    foreach (['H:i:s', 'H:i'] as $format) {
        $time = DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        $valid = $time !== false
            && ($errors === false
                || ($errors['warning_count'] === 0
                    && $errors['error_count'] === 0))
            && $time->format($format) === $value;

        if ($valid) {
            return $time->format('H:i:s');
        }
    }

    throw new RuntimeException(
        sprintf('Ungueltige KTrax-Zeit in %s: %s', $field, $value)
    );
}

function valid_ktrax_date(mixed $value): ?string
{
    $value = nullable_text($value);

    if ($value === null) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException('Ungueltiges KTrax-Flugdatum: ' . $value);
    }

    return $value;
}

function ktrax_timezone_offset_hours(
    string $date,
    string $timezoneName
): float {
    $timezone = new DateTimeZone($timezoneName);
    $dateTime = new DateTime($date . ' 12:00:00', $timezone);

    return $timezone->getOffset($dateTime) / 3600;
}

function complete_raw_flight(array $row): bool
{
    return nullable_text($row['takeoff_time'] ?? null) !== null
        && nullable_text($row['landing_time'] ?? null) !== null;
}

function incomplete_raw_warning(array $row): array
{
    $missing = [];

    if (nullable_text($row['takeoff_time'] ?? null) === null) {
        $missing[] = 'takeoff_time';
    }
    if (nullable_text($row['landing_time'] ?? null) === null) {
        $missing[] = 'landing_time';
    }

    return [
        'raw_flight_id' => (int)($row['id'] ?? 0),
        'source_uid' => (string)($row['source_uid'] ?? ''),
        'aircraft_callsign' => (string)($row['aircraft_callsign'] ?? ''),
        'missing_fields' => $missing,
        'message' => 'Rohflug gespeichert, aber keine Operation erzeugt.',
    ];
}

$config = app_config();
$pdo = db();

$date = (string)($_GET['date'] ?? date('Y-m-d'));
$airfield = strtolower((string)(
    $_GET['airfield']
    ?? $config['ktrax']['default_airfield']
    ?? 'lszj'
));
$timezoneName = (string)(
    $config['ktrax']['timezone']
    ?? $config['app']['timezone']
    ?? 'Europe/Zurich'
);

try {
    $validatedDate = valid_ktrax_date($date);

    if ($validatedDate === null) {
        json_response(['error' => 'Flugdatum fehlt.'], 400);
    }

    $date = $validatedDate;
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

    $context = stream_context_create([
        'http' => [
            'timeout' => (int)($config['ktrax']['http_timeout_seconds'] ?? 30),
            'ignore_errors' => true,
        ],
    ]);

    $json = @file_get_contents($url, false, $context);

    if ($json === false) {
        json_response(['error' => 'KTrax konnte nicht gelesen werden.'], 502);
    }

    $payload = json_decode($json, true);

    if (!is_array($payload)) {
        json_response(['error' => 'KTrax-Antwort ist kein JSON.'], 502);
    }

    $sorties = $payload['sorties'] ?? [];

    if (!is_array($sorties)) {
        json_response(['error' => 'KTrax-Antwort enthaelt keine Sortie-Liste.'], 502);
    }

    $pdo->beginTransaction();

    try {
        $rawStmt = $pdo->prepare(
            "INSERT INTO raw_flights
                (source, source_uid, flight_date, aircraft_callsign,
                 aircraft_type, takeoff_time, takeoff_airfield,
                 landing_time, landing_airfield, duration_text,
                 tow_id, tow_callsign, tow_height_m, raw_json)
             VALUES
                ('ktrax', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                raw_json = VALUES(raw_json),
                aircraft_callsign = VALUES(aircraft_callsign),
                aircraft_type = VALUES(aircraft_type),
                takeoff_time = VALUES(takeoff_time),
                takeoff_airfield = VALUES(takeoff_airfield),
                landing_time = VALUES(landing_time),
                landing_airfield = VALUES(landing_airfield),
                duration_text = VALUES(duration_text),
                tow_id = VALUES(tow_id),
                tow_callsign = VALUES(tow_callsign),
                tow_height_m = VALUES(tow_height_m)"
        );

        foreach ($sorties as $index => $sortie) {
            if (!is_array($sortie)) {
                throw new RuntimeException(
                    'KTrax-Sortie an Position ' . $index . ' ist ungueltig.'
                );
            }

            $sourceUid = isset($sortie['seq'])
                && trim((string)$sortie['seq']) !== ''
                ? trim((string)$sortie['seq'])
                : 'fallback:' . sha1(json_encode([
                    $sortie['date'] ?? $date,
                    $sortie['id'] ?? '',
                    $sortie['cs'] ?? '',
                    $sortie['type'] ?? '',
                    $sortie['tkof']['time'] ?? '',
                    $sortie['tkof']['loc'] ?? '',
                    $sortie['ldg']['time'] ?? '',
                    $sortie['ldg']['loc'] ?? '',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $flightDate = valid_ktrax_date($sortie['date'] ?? $date) ?? $date;
            $takeoffTime = normalize_ktrax_time(
                $sortie['tkof']['time'] ?? null,
                'tkof.time bei ' . $sourceUid
            );
            $landingTime = normalize_ktrax_time(
                $sortie['ldg']['time'] ?? null,
                'ldg.time bei ' . $sourceUid
            );

            $rawJson = json_encode(
                $sortie,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );

            $rawStmt->execute([
                $sourceUid,
                $flightDate,
                nullable_text($sortie['cs'] ?? null),
                isset($sortie['type']) && $sortie['type'] !== ''
                    ? (int)$sortie['type']
                    : null,
                $takeoffTime,
                nullable_airfield($sortie['tkof']['loc'] ?? null),
                $landingTime,
                nullable_airfield($sortie['ldg']['loc'] ?? null),
                nullable_text($sortie['dt'] ?? null),
                nullable_text($sortie['tow_id'] ?? null),
                nullable_text($sortie['tow_cs'] ?? null),
                isset($sortie['dalt']) && $sortie['dalt'] !== ''
                    ? (int)$sortie['dalt']
                    : null,
                $rawJson,
            ]);
        }

        $rows = $pdo->prepare(
            "SELECT *
             FROM raw_flights
             WHERE source = 'ktrax'
               AND flight_date = ?
             ORDER BY takeoff_time, aircraft_callsign"
        );
        $rows->execute([$date]);
        $raw = $rows->fetchAll(PDO::FETCH_ASSOC);

        $referencedTowSequences = [];
        foreach ($raw as $row) {
            if ((int)$row['aircraft_type'] !== 1) {
                continue;
            }

            $rowJson = json_decode((string)($row['raw_json'] ?? '{}'), true);
            if (!is_array($rowJson)) {
                continue;
            }

            $towSequence = nullable_text($rowJson['tow_seq'] ?? null);
            if ($towSequence !== null) {
                $referencedTowSequences[$towSequence] = true;
            }
        }

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

        $findTowRawBySeqStmt = $pdo->prepare(
            "SELECT *
             FROM raw_flights
             WHERE source = 'ktrax'
               AND source_uid = ?
               AND aircraft_type = 2
             LIMIT 1"
        );

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
                (operation_date, kind, glider_callsign, glider_pilot_name,
                 instructor_name, tow_callsign, takeoff_time,
                 takeoff_airfield, glider_landing_time,
                 glider_landing_airfield, tow_height_m,
                 created_from, approval_status)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ktrax', 'pending')"
        );

        $insertTowplaneOnlyOperationStmt = $pdo->prepare(
            "INSERT INTO operations
                (operation_date, kind, glider_callsign, glider_pilot_name,
                 instructor_name, tow_callsign, takeoff_time,
                 takeoff_airfield, glider_landing_time,
                 glider_landing_airfield, tow_height_m,
                 created_from, approval_status)
             VALUES
                (?, 'towplane_only', NULL, NULL, NULL, ?, ?, ?,
                 NULL, NULL, NULL, 'ktrax', 'pending')"
        );

        $insertRawLinkStmt = $pdo->prepare(
            "INSERT IGNORE INTO operation_raw_links
                (operation_id, raw_flight_id, role)
             VALUES (?, ?, ?)"
        );

        $insertTowSegmentStmt = $pdo->prepare(
            "INSERT INTO tow_segments
                (operation_id, glider_raw_flight_id, tow_raw_flight_id,
                 glider_callsign, tow_callsign, tow_pilot_name,
                 segment_start, segment_end, tow_minutes, tow_height_m,
                 cost_center, approval_status)
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
                (operation_id, entry_type, callsign, pilot_name,
                 attendant_name, departure_time, departure_location,
                 arrival_time, arrival_location, flight_minutes,
                 landing_count, start_type, comment, tow_height_m,
                 tow_callsign, vf_flight_type_id, approval_role,
                 approval_status)
             VALUES
                (?, 'glider_flight', ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, '',
                 ?, ?, NULL, 'glider_pilot', 'pending')"
        );

        $insertTowChargeEntryStmt = $pdo->prepare(
            "INSERT INTO accounting_entries
                (operation_id, entry_type, callsign, tow_pilot_name,
                 departure_time, departure_location, flight_minutes,
                 tow_minutes, landing_count, start_type, charge_mode,
                 invoiced, tow_height_m, tow_callsign, vf_flight_type_id,
                 approval_role, comment, approval_status)
             VALUES
                (?, 'tow_charge', ?, ?, ?, ?, NULL, ?, 1, 3, 2, 0, ?, ?,
                 3, 'tow_pilot', 'Schleppanteil bitte pruefen', 'pending')"
        );

        $insertTowplaneOwnEntryStmt = $pdo->prepare(
            "INSERT INTO accounting_entries
                (operation_id, entry_type, callsign, tow_pilot_name,
                 departure_time, departure_location, arrival_time,
                 arrival_location, flight_minutes, landing_count,
                 start_type, charge_mode, invoiced, comment,
                 vf_flight_type_id, approval_role, approval_status)
             VALUES
                (?, 'towplane_own', ?, ?, ?, ?, ?, ?, ?, 1, 1, 2, 0,
                 'Schleppflugzeug ohne gekoppelten Segelflug - '
                 'Flugart bitte pruefen', NULL, 'tow_pilot', 'pending')"
        );

        $createdOperations = 0;
        $skippedExistingOperations = 0;
        $createdTowSegments = 0;
        $createdTowplaneOwnEntries = 0;
        $towPairsBySeq = 0;
        $towPairsByFallback = 0;
        $towPairsMissing = 0;
        $incompleteRawFlights = [];
        $incompleteTowplanesReferenced = 0;

        foreach ($raw as $row) {
            if ((int)$row['aircraft_type'] !== 1) {
                continue;
            }

            if (!complete_raw_flight($row)) {
                $incompleteRawFlights[] = incomplete_raw_warning($row);
                continue;
            }

            $existingGliderOperationStmt->execute([$row['id']]);
            if ($existingGliderOperationStmt->fetch()) {
                $skippedExistingOperations++;
                continue;
            }

            $rowJson = json_decode((string)($row['raw_json'] ?? '{}'), true);
            $rowJson = is_array($rowJson) ? $rowJson : [];

            $gliderPilotName = extract_glider_pilot_name($rowJson);
            $instructorName = extract_instructor_name($rowJson);
            $kind = !empty($row['tow_callsign'])
                ? 'glider_tow'
                : 'self_launch';

            $towRawId = null;
            $towPilotName = null;
            $towMinutes = null;
            $towSegmentStart = null;
            $towSegmentEnd = null;

            if ($kind === 'glider_tow') {
                $towRaw = null;
                $towSequence = nullable_text($rowJson['tow_seq'] ?? null);

                if ($towSequence !== null) {
                    $findTowRawBySeqStmt->execute([$towSequence]);
                    $towRaw = $findTowRawBySeqStmt->fetch(PDO::FETCH_ASSOC)
                        ?: null;
                    if ($towRaw) {
                        $towPairsBySeq++;
                    }
                }

                if (!$towRaw) {
                    $findTowRawFallbackStmt->execute([
                        $row['flight_date'],
                        $row['tow_callsign'],
                        $row['aircraft_callsign'],
                        $row['takeoff_time'],
                    ]);
                    $towRaw = $findTowRawFallbackStmt
                        ->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($towRaw) {
                        $towPairsByFallback++;
                    }
                }

                if ($towRaw && complete_raw_flight($towRaw)) {
                    $towRawId = (int)$towRaw['id'];
                    $towRawJson = json_decode(
                        (string)($towRaw['raw_json'] ?? '{}'),
                        true
                    );
                    $towRawJson = is_array($towRawJson) ? $towRawJson : [];
                    $towPilotName = extract_tow_pilot_name($towRawJson);
                    $towMinutes = minutes_between(
                        $towRaw['flight_date'],
                        $towRaw['takeoff_time'],
                        $towRaw['landing_time']
                    );
                    $towSegmentStart = dt_string(
                        $towRaw['flight_date'],
                        $towRaw['takeoff_time']
                    );
                    $towSegmentEnd = dt_string(
                        $towRaw['flight_date'],
                        $towRaw['landing_time']
                    );
                } else {
                    $towPairsMissing++;
                    $towSegmentStart = dt_string(
                        $row['flight_date'],
                        $row['takeoff_time']
                    );
                }
            }

            $insertGliderOperationStmt->execute([
                $row['flight_date'],
                $kind,
                $row['aircraft_callsign'],
                $gliderPilotName,
                $instructorName,
                $row['tow_callsign'],
                $row['takeoff_time'],
                $row['takeoff_airfield'],
                $row['landing_time'],
                $row['landing_airfield'],
                $row['tow_height_m'],
            ]);

            $operationId = (int)$pdo->lastInsertId();
            $createdOperations++;
            $insertRawLinkStmt->execute([
                $operationId,
                $row['id'],
                'glider',
            ]);

            if ($towRawId !== null) {
                $insertRawLinkStmt->execute([
                    $operationId,
                    $towRawId,
                    'towplane',
                ]);
            }

            if ($kind === 'glider_tow') {
                $insertTowSegmentStmt->execute([
                    $operationId,
                    $row['id'],
                    $towRawId,
                    $row['aircraft_callsign'],
                    $row['tow_callsign'],
                    $towPilotName,
                    $towSegmentStart,
                    $towSegmentEnd,
                    $towMinutes,
                    $row['tow_height_m'],
                    'tow',
                ]);
                $createdTowSegments++;
            }

            $gliderMinutes = minutes_between(
                $row['flight_date'],
                $row['takeoff_time'],
                $row['landing_time']
            );

            $insertGliderEntryStmt->execute([
                $operationId,
                $row['aircraft_callsign'],
                $gliderPilotName,
                $instructorName,
                dt_string($row['flight_date'], $row['takeoff_time']),
                $row['takeoff_airfield'],
                dt_string($row['flight_date'], $row['landing_time']),
                $row['landing_airfield'],
                $gliderMinutes,
                $kind === 'glider_tow' ? 3 : 1,
                $row['tow_height_m'],
                $row['tow_callsign'],
            ]);

            if ($kind === 'glider_tow') {
                $insertTowChargeEntryStmt->execute([
                    $operationId,
                    $row['aircraft_callsign'],
                    $towPilotName,
                    dt_string($row['flight_date'], $row['takeoff_time']),
                    $row['takeoff_airfield'],
                    $towMinutes,
                    $row['tow_height_m'],
                    $row['tow_callsign'],
                ]);
            }
        }

        foreach ($raw as $row) {
            if ((int)$row['aircraft_type'] !== 2) {
                continue;
            }

            if (!complete_raw_flight($row)) {
                $incompleteRawFlights[] = incomplete_raw_warning($row);
                continue;
            }

            if (isset($referencedTowSequences[(string)$row['source_uid']])) {
                $existingTowplaneOperationStmt->execute([$row['id']]);
                if (!$existingTowplaneOperationStmt->fetch()) {
                    $incompleteTowplanesReferenced++;
                    continue;
                }
            }

            $existingTowplaneOperationStmt->execute([$row['id']]);
            if ($existingTowplaneOperationStmt->fetch()) {
                continue;
            }

            $rowJson = json_decode((string)($row['raw_json'] ?? '{}'), true);
            $rowJson = is_array($rowJson) ? $rowJson : [];
            $towPilotName = extract_tow_pilot_name($rowJson);
            $towplaneMinutes = minutes_between(
                $row['flight_date'],
                $row['takeoff_time'],
                $row['landing_time']
            );

            $insertTowplaneOnlyOperationStmt->execute([
                $row['flight_date'],
                $row['aircraft_callsign'],
                $row['takeoff_time'],
                $row['takeoff_airfield'],
            ]);

            $operationId = (int)$pdo->lastInsertId();
            $createdOperations++;
            $insertRawLinkStmt->execute([
                $operationId,
                $row['id'],
                'towplane',
            ]);

            $insertTowplaneOwnEntryStmt->execute([
                $operationId,
                $row['aircraft_callsign'],
                $towPilotName,
                dt_string($row['flight_date'], $row['takeoff_time']),
                $row['takeoff_airfield'],
                dt_string($row['flight_date'], $row['landing_time']),
                $row['landing_airfield'],
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
            'operations_skipped_incomplete' => count($incompleteRawFlights),
            'towplanes_skipped_referenced_incomplete' =>
                $incompleteTowplanesReferenced,
            'tow_segments_created' => $createdTowSegments,
            'towplane_own_entries_created' => $createdTowplaneOwnEntries,
            'tow_pairs_by_seq' => $towPairsBySeq,
            'tow_pairs_by_fallback' => $towPairsByFallback,
            'tow_pairs_missing' => $towPairsMissing,
            'warnings' => $incompleteRawFlights,
        ]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        json_response(['error' => $error->getMessage()], 500);
    }
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(['error' => $error->getMessage()], 500);
}
