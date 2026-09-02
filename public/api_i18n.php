<?php
declare(strict_types=1);

$implementation = dirname(__DIR__) . '/src/api_i18n.php';

if (!is_file($implementation)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Endpoint ist nicht korrekt installiert.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require $implementation;