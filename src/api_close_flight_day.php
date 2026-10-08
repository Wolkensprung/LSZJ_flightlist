<?php

declare(strict_types=1);

require_once __DIR__ . '/api_authenticated_actor.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/flight_day.php';

header('Content-Type: application/json; charset=utf-8');

try {
    api_authenticated_actor([
        'DUTY_OFFICER',
        'ADMIN',
    ]);

    $rawInput = file_get_contents('php://input');
    $input = json_decode(
        $rawInput !== false ? $rawInput : '',
        true
    );

    if (!is_array($input)) {
        $input = $_POST;
    }

    csrf_require_valid(
        isset($input['csrf_token'])
            ? (string)$input['csrf_token']
            : null
    );

    $date = trim((string)($input['date'] ?? ''));

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        throw new RuntimeException('Ungültiges Betriebsdatum.');
    }

    $reason = trim((string)(
        $input['reason']
        ?? $input['note']
        ?? ''
    ));

    $result = flight_day_close(
        db(),
        $date,
        $reason
    );

    $result['ok'] = $result['ok'] ?? true;

    json_response($result);
} catch (Throwable $e) {
    error_log(
        'api_close_flight_day: '
        . $e->getMessage()
    );

    json_response(
        [
            'ok' => false,
            'error' => $e->getMessage(),
        ],
        422
    );
}
