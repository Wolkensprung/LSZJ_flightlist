<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/api_authenticated_actor.php';
require_once __DIR__ . '/../src/vf_api_bootstrap.php';

use LSZJ\Vereinsflieger\MemberSyncService;
use LSZJ\Vereinsflieger\RestClient;

header('Content-Type: application/json; charset=utf-8');

try {
    api_authenticated_actor(['ADMIN']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'Methode nicht erlaubt.'], 405);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    csrf_require_valid(isset($input['csrf_token']) ? (string)$input['csrf_token'] : null);
    $service = new MemberSyncService(db(), new RestClient(vf_api_config()));
    $action = (string)($input['action'] ?? 'preview');
    if ($action === 'preview') {
        json_response($service->preview());
    }
    if ($action === 'import') {
        json_response([
            'ok' => true,
            'result' => $service->execute(($input['confirm_safe_import'] ?? false) === true),
        ]);
    }
    json_response(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
} catch (Throwable $error) {
    error_log('VF person sync: ' . $error->getMessage());
    json_response(['ok' => false, 'error' => $error->getMessage()], 422);
}
