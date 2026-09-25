<?php
declare(strict_types=1);

/**
 * Importiert fehlende kTrax-Tage für einen Von-/Bis-Zeitraum.
 *
 * Jeder Tagesimport läuft in einem separaten PHP-CLI-Prozess über die feste
 * Projektdatei ktrax_day_import_cli.php. Es gibt keinen HTTP-Selbstaufruf
 * und keine temporäre PHP-Datei im Windows-Benutzerprofil.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$config = app_config();
$pdo = db();

function ensure_ktrax_import_log(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ktrax_import_log (
            import_date DATE NOT NULL,
            airfield VARCHAR(16) NOT NULL DEFAULT 'LSZJ',
            status VARCHAR(32) NOT NULL,
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            raw_imported INT NULL,
            operations_created INT NULL,
            operations_skipped_existing INT NULL,
            tow_segments_created INT NULL,
            towplane_own_entries_created INT NULL,
            message TEXT NULL,
            PRIMARY KEY (import_date, airfield)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci"
    );
}

function valid_date_string(?string $date): bool
{
    if ($date === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function valid_airfield(string $airfield): bool
{
    return preg_match('/^[a-z0-9_-]{2,16}$/D', $airfield) === 1;
}

function ktrax_day_exists(PDO $pdo, string $date, string $airfield): bool
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM ktrax_import_log
         WHERE import_date = ? AND airfield = ? AND status = 'success'"
    );
    $statement->execute([$date, strtoupper($airfield)]);

    if ((int)$statement->fetchColumn() > 0) {
        return true;
    }

    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM raw_flights
         WHERE source = 'ktrax' AND flight_date = ?"
    );
    $statement->execute([$date]);

    if ((int)$statement->fetchColumn() > 0) {
        return true;
    }

    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM operations
         WHERE created_from = 'ktrax' AND operation_date = ?"
    );
    $statement->execute([$date]);

    return (int)$statement->fetchColumn() > 0;
}

/** @param array<string,mixed> $data */
function log_ktrax_import(
    PDO $pdo,
    string $date,
    string $airfield,
    string $status,
    array $data = []
): void {
    $statement = $pdo->prepare(
        "INSERT INTO ktrax_import_log (
            import_date, airfield, status, imported_at,
            raw_imported, operations_created,
            operations_skipped_existing, tow_segments_created,
            towplane_own_entries_created, message
        ) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            imported_at = VALUES(imported_at),
            raw_imported = VALUES(raw_imported),
            operations_created = VALUES(operations_created),
            operations_skipped_existing = VALUES(operations_skipped_existing),
            tow_segments_created = VALUES(tow_segments_created),
            towplane_own_entries_created = VALUES(towplane_own_entries_created),
            message = VALUES(message)"
    );

    $statement->execute([
        $date,
        strtoupper($airfield),
        $status,
        $data['raw_imported'] ?? null,
        $data['operations_created'] ?? null,
        $data['operations_skipped_existing'] ?? null,
        $data['tow_segments_created'] ?? null,
        $data['towplane_own_entries_created'] ?? null,
        $data['message'] ?? null,
    ]);
}

/**
 * Ermittelt explizit eine PHP-CLI-Binary.
 *
 * Unter PHP-FPM zeigt PHP_BINARY auf php-fpm. Dieses Programm kann keine
 * CLI-Skripte ausführen und beendet den Aufruf mit Exit-Code 64. Darum wird
 * PHP_BINARY nur verwendet, wenn es tatsächlich eine CLI-Binary ist.
 */
function resolve_php_cli_binary(array $config): string
{
    $configured = trim((string)(
        $config['app']['php_cli_binary']
        ?? getenv('PHP_CLI_BINARY')
        ?: ''
    ));

    $suffix = DIRECTORY_SEPARATOR === '\\' ? '.exe' : '';
    $candidates = [
        $configured,
        PHP_BINDIR . DIRECTORY_SEPARATOR . 'php' . $suffix,
        dirname(dirname(PHP_BINARY)) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'php' . $suffix,
        PHP_BINARY,
    ];

    if (DIRECTORY_SEPARATOR !== '\\') {
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/usr/bin/php';
        $candidates[] = '/bin/php';
    }

    $checked = [];
    foreach ($candidates as $candidate) {
        if ($candidate === '' || in_array($candidate, $checked, true)) {
            continue;
        }
        $checked[] = $candidate;

        $baseName = strtolower(basename($candidate));
        if (str_contains($baseName, 'php-fpm') || str_contains($baseName, 'php-cgi')) {
            continue;
        }

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException(
        'Keine PHP-CLI-Binary gefunden. Setze app.php_cli_binary in config.php '
        . 'oder die Umgebungsvariable PHP_CLI_BINARY auf den vollständigen Pfad '
        . '(zum Beispiel /usr/local/php83/bin/php). Geprüft: '
        . implode(', ', $checked)
    );
}

/** @return array<string,mixed> */
function run_ktrax_day_import(
    string $date,
    string $airfield,
    int $timeoutSeconds
): array {
    if (!function_exists('proc_open')) {
        throw new RuntimeException('proc_open ist deaktiviert.');
    }

    $runner = __DIR__ . DIRECTORY_SEPARATOR . 'ktrax_day_import_cli.php';

    if (!is_file($runner)) {
        throw new RuntimeException('CLI-Runner nicht gefunden: ' . $runner);
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $command = [
        resolve_php_cli_binary($GLOBALS['config']),
        $runner,
        $date,
        strtolower($airfield),
    ];

    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        __DIR__,
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        throw new RuntimeException('PHP-Tagesprozess konnte nicht gestartet werden.');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $startedAt = microtime(true);
    $timedOut = false;
    $reportedExitCode = null;

    while (true) {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);

        if (!$status['running']) {
            $reportedExitCode = (int)$status['exitcode'];
            break;
        }

        if ((microtime(true) - $startedAt) >= $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process);
            usleep(200000);

            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            break;
        }

        usleep(100000);
    }

    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeExitCode = proc_close($process);
    $exitCode = $reportedExitCode !== null && $reportedExitCode >= 0
        ? $reportedExitCode
        : $closeExitCode;

    if ($timedOut) {
        throw new RuntimeException(sprintf(
            'Tagesimport für %s nach %d Sekunden abgebrochen.',
            $date,
            $timeoutSeconds
        ));
    }

    $stdout = remove_ktrax_bom(trim($stdout));
    $stderr = trim($stderr);
    $data = json_decode($stdout, true);

    if (!is_array($data)) {
        $detail = $stderr !== '' ? $stderr : $stdout;
        if ($detail === '') {
            $detail = 'leere Antwort';
        }

        throw new RuntimeException(sprintf(
            'Tagesimport für %s lieferte kein JSON (Exit-Code %d): %s',
            $date,
            $exitCode,
            limit_ktrax_message($detail)
        ));
    }

    if (empty($data['ok'])) {
        throw new RuntimeException(
            (string)($data['error'] ?? 'Tagesimport lieferte kein ok=true.')
        );
    }

    return $data;
}

function remove_ktrax_bom(string $value): string
{
    return str_starts_with($value, "\xEF\xBB\xBF")
        ? substr($value, 3)
        : $value;
}

function limit_ktrax_message(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

    return function_exists('mb_substr')
        ? mb_substr($value, 0, 1000, 'UTF-8')
        : substr($value, 0, 1000);
}

try {
    $from = $_GET['from'] ?? $_POST['from'] ?? ($_GET['date'] ?? null);
    $to = $_GET['to'] ?? $_POST['to'] ?? $from;
    $airfield = strtolower((string)(
        $_GET['airfield']
        ?? $_POST['airfield']
        ?? $config['ktrax']['default_airfield']
        ?? 'lszj'
    ));
    $force = !empty($_GET['force']) || !empty($_POST['force']);

    if (
        !valid_date_string(is_string($from) ? $from : null)
        || !valid_date_string(is_string($to) ? $to : null)
    ) {
        json_response([
            'ok' => false,
            'error' => 'Ungültiger Datumsbereich. Erwartet YYYY-MM-DD.',
        ], 400);
    }

    if (!valid_airfield($airfield)) {
        json_response([
            'ok' => false,
            'error' => 'Ungültiger Flugplatz.',
        ], 400);
    }

    if ($to < $from) {
        json_response([
            'ok' => false,
            'error' => 'Bis darf nicht vor Von liegen.',
        ], 400);
    }

    $start = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    $days = $start->diff($end)->days + 1;
    $maxDays = max(1, (int)(
        $config['ktrax']['max_range_import_days'] ?? 31
    ));

    if ($days > $maxDays) {
        json_response([
            'ok' => false,
            'error' => 'Der Zeitraum ist zu gross. Maximal erlaubt: '
                . $maxDays . ' Tage.',
        ], 400);
    }

    $perDayTimeout = max(10, (int)(
        $config['ktrax']['day_import_timeout_seconds'] ?? 90
    ));
    $requestLimit = min(3600, max(120, ($days * $perDayTimeout) + 30));
    @set_time_limit($requestLimit);

    ensure_ktrax_import_log($pdo);

    $details = [];
    $daysImported = 0;
    $daysSkipped = 0;
    $daysFailed = 0;

    for (
        $current = $start;
        $current <= $end;
        $current = $current->modify('+1 day')
    ) {
        $date = $current->format('Y-m-d');

        if (!$force && ktrax_day_exists($pdo, $date, $airfield)) {
            $daysSkipped++;
            $details[] = [
                'date' => $date,
                'status' => 'skipped',
                'message' => 'bereits vorhanden',
            ];
            continue;
        }

        try {
            $data = run_ktrax_day_import($date, $airfield, $perDayTimeout);
            $daysImported++;

            log_ktrax_import(
                $pdo,
                $date,
                $airfield,
                'success',
                $data + ['message' => 'importiert']
            );

            $details[] = [
                'date' => $date,
                'status' => 'imported',
                'raw_imported' => $data['raw_imported'] ?? null,
                'operations_created' => $data['operations_created'] ?? null,
                'operations_skipped_existing' =>
                    $data['operations_skipped_existing'] ?? null,
                'tow_segments_created' =>
                    $data['tow_segments_created'] ?? null,
                'towplane_own_entries_created' =>
                    $data['towplane_own_entries_created'] ?? null,
                'message' => 'importiert',
            ];
        } catch (Throwable $error) {
            $daysFailed++;
            $message = limit_ktrax_message($error->getMessage());

            log_ktrax_import(
                $pdo,
                $date,
                $airfield,
                'error',
                ['message' => $message]
            );

            $details[] = [
                'date' => $date,
                'status' => 'error',
                'message' => $message,
            ];
        }
    }

    json_response([
        'ok' => true,
        'from' => $from,
        'to' => $to,
        'airfield' => strtoupper($airfield),
        'days_checked' => $days,
        'days_imported' => $daysImported,
        'days_skipped' => $daysSkipped,
        'days_failed' => $daysFailed,
        'details' => $details,
    ]);
} catch (Throwable $error) {
    error_log('kTrax range import: ' . $error->getMessage());

    json_response([
        'ok' => false,
        'error' => limit_ktrax_message($error->getMessage()),
    ], 500);
}
