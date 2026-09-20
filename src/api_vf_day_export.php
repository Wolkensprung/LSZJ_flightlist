<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/api_authenticated_actor.php';
require_once __DIR__ . '/vf_api_config.php';
require_once __DIR__ . '/flight_day.php';

foreach ([
    'FlightPayloadBuilder',
    'FlightRestClient',
    'FlightPersonResolver',
    'FlightExportService',
    'FlightSpecialCaseGuard',
    'DuplicateFlightResult',
    'FlightDayExportService',
] as $class) {
    require_once __DIR__ . '/Vereinsflieger/' . $class . '.php';
}

use LSZJ\Vereinsflieger\FlightDayExportService;
use LSZJ\Vereinsflieger\FlightExportService;
use LSZJ\Vereinsflieger\FlightPersonResolver;
use LSZJ\Vereinsflieger\FlightRestClient;
use LSZJ\Vereinsflieger\FlightSpecialCaseGuard;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        vf_day_export_respond([
            'ok' => false,
            'error' => 'Methode nicht erlaubt.',
        ], 405);
    }

    $actor = api_authenticated_actor(['ADMIN']);

    $rawInput = file_get_contents('php://input');
    $input = [];

    if ($rawInput !== false && trim($rawInput) !== '') {
        $decoded = json_decode($rawInput, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            vf_day_export_respond([
                'ok' => false,
                'error' => 'JSON-Objekt erwartet.',
            ], 400);
        }
        $input = $decoded;
    } elseif ($_POST !== []) {
        $input = $_POST;
    }

    csrf_require_valid(
        isset($input['csrf_token'])
            ? (string)$input['csrf_token']
            : null
    );

    $date = trim((string)($input['date'] ?? date('Y-m-d')));
    $action = trim((string)($input['action'] ?? 'preview'));

    $pdo = db();
    $service = new FlightDayExportService(
        $pdo,
        new FlightExportService(
            $pdo,
            new FlightRestClient(vf_api_config()),
            new FlightPersonResolver($pdo)
        ),
        new FlightSpecialCaseGuard()
    );

    if ($action === 'preview') {
        vf_day_export_respond($service->preview($date));
    }

    if ($action === 'run') {
        /*
         * Serverseitige Freigabesperre:
         * Der C4-Export ist erst erlaubt, wenn der Flugdienstleiter den
         * fachlich geprüften Betriebstag abgeschlossen und damit für den
         * Export nach Vereinsflieger freigegeben hat.
         */
        $dayStatus = flight_day_status($pdo, $date);
        $dayState = $dayStatus['state'] ?? [];

        if (($dayState['status'] ?? 'open') !== 'closed') {
            vf_day_export_respond([
                'ok' => false,
                'error' =>
                    'C4 gesperrt: Der Flugdienstleiter hat den '
                    . 'Betriebstag noch nicht abgeschlossen und '
                    . 'für VF freigegeben.',
            ], 423);
        }

        if (!($dayStatus['green'] ?? false)) {
            vf_day_export_respond([
                'ok' => false,
                'error' =>
                    'C4 gesperrt: Die Tagesabschlussprüfung '
                    . 'ist nicht grün.',
            ], 423);
        }

        if (getenv('VF_FLIGHT_EXPORT_ENABLED') !== '1') {
            vf_day_export_respond([
                'ok' => false,
                'error' =>
                    'Versand gesperrt. '
                    . 'VF_FLIGHT_EXPORT_ENABLED=1 fehlt.',
            ], 423);
        }

        $entryIds = $input['entry_ids'] ?? [];
        if (!is_array($entryIds)) {
            vf_day_export_respond([
                'ok' => false,
                'error' => 'entry_ids muss eine Liste sein.',
            ], 422);
        }

        $normalizedIds = [];
        foreach ($entryIds as $entryId) {
            $validId = filter_var($entryId, FILTER_VALIDATE_INT);
            if ($validId === false || (int)$validId <= 0) {
                vf_day_export_respond([
                    'ok' => false,
                    'error' => 'Ungültige Buchungs-ID in entry_ids.',
                ], 422);
            }
            $normalizedIds[] = (int)$validId;
        }
        $normalizedIds = array_values(array_unique($normalizedIds));

        if ($normalizedIds === []) {
            vf_day_export_respond([
                'ok' => false,
                'error' => 'Es wurden keine Flüge für den Export ausgewählt.',
            ], 422);
        }

        $confirmDayExport =
            ($input['confirm_day_export'] ?? false) === true;

        if (!$confirmDayExport) {
            vf_day_export_respond([
                'ok' => false,
                'error' =>
                    'Die ausdrückliche Bestätigung des Tagesexports fehlt.',
            ], 422);
        }

        $result = $service->run(
            $date,
            $normalizedIds,
            (int)$actor['id'],
            true
        );

        vf_day_export_respond([
            'ok' => true,
            'result' => $result,
        ]);
    }

    vf_day_export_respond([
        'ok' => false,
        'error' => 'Unbekannte Aktion. Erlaubt sind preview und run.',
    ], 400);
} catch (JsonException $error) {
    vf_day_export_respond([
        'ok' => false,
        'error' => 'Ungültiges JSON: ' . $error->getMessage(),
    ], 400);
} catch (Throwable $error) {
    error_log('VF day export: ' . $error->getMessage());

    vf_day_export_respond([
        'ok' => false,
        'error' => vf_day_export_limit_message($error->getMessage()),
    ], 422);
}

/**
 * @param array<string,mixed> $payload
 */
function vf_day_export_respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    );
    exit;
}

function vf_day_export_limit_message(string $message): string
{
    $message = trim($message);
    if ($message === '') {
        return 'Der VF-Tagesexport konnte nicht verarbeitet werden.';
    }

    return function_exists('mb_substr')
        ? mb_substr($message, 0, 1000, 'UTF-8')
        : substr($message, 0, 1000);
}
