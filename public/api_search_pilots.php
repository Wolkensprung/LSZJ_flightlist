<?php
declare(strict_types=1);

/**
 * Public HTTP entry point for pilot search.
 * The actual implementation remains outside the document root in /src.
 */
$implementation = dirname(__DIR__) . '/src/api_search_pilots.php';

if (!is_file($implementation)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Pilot search endpoint is not installed correctly.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require $implementation;
