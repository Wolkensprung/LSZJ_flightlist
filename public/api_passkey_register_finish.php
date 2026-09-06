<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

$implementation = dirname(__DIR__) . '/src/api_passkey_register_finish.php';
if (!is_file($implementation)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Endpoint ist nicht korrekt installiert.']);
    exit;
}
require $implementation;