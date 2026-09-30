<?php
declare(strict_types=1);

$implementation = dirname(__DIR__) . '/src/api_onboarding_qr_code.php';
if (!is_file($implementation)) {
    http_response_code(500);
    exit('Endpoint ist nicht korrekt installiert.');
}
require $implementation;
