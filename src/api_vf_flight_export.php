<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/api_authenticated_actor.php';
require_once __DIR__ . '/vf_api_config.php';
require_once __DIR__ . '/Vereinsflieger/FlightPayloadBuilder.php';
require_once __DIR__ . '/Vereinsflieger/FlightRestClient.php';
require_once __DIR__ . '/Vereinsflieger/FlightPersonResolver.php';
require_once __DIR__ . '/Vereinsflieger/FlightExportService.php';

use LSZJ\Vereinsflieger\FlightExportService;
use LSZJ\Vereinsflieger\FlightPersonResolver;
use LSZJ\Vereinsflieger\FlightRestClient;

try {
    $actor = api_authenticated_actor(['ADMIN']);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'Methode nicht erlaubt.'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    csrf_require_valid(
        isset($input['csrf_token'])
            ? (string)$input['csrf_token']
            : null
    );

    $pdo = db();
    $service = new FlightExportService(
        $pdo,
        new FlightRestClient(vf_api_config()),
        new FlightPersonResolver($pdo)
    );

    $action = (string)($input['action'] ?? 'preview');

    if ($action === 'preview') {
        json_response($service->preview(
            (string)($input['from'] ?? date('Y-m-d')),
            (string)($input['to'] ?? date('Y-m-d'))
        ));
    }

    if ($action === 'send_one') {
        if (getenv('VF_FLIGHT_EXPORT_ENABLED') !== '1') {
            json_response([
                'ok' => false,
                'error' => 'Testversand ist serverseitig gesperrt. '
                    . 'VF_FLIGHT_EXPORT_ENABLED=1 fehlt.',
            ], 423);
        }

        json_response([
            'ok' => true,
            'result' => $service->sendOne(
                (int)($input['entry_id'] ?? 0),
                (int)$actor['id'],
                ($input['confirm_single_test'] ?? false) === true
            ),
        ]);
    }

    json_response(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
} catch (Throwable $error) {
    error_log('VF flight export: ' . $error->getMessage());
    json_response(['ok' => false, 'error' => $error->getMessage()], 422);
}
