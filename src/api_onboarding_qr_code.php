<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        exit;
    }

    $config = app_config();
    $baseUrl = rtrim((string)($config['recovery']['base_url'] ?? ''), '/');
    if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('Recovery base_url fehlt oder ist ungültig.');
    }

    $url = $baseUrl . '/recovery_request.php';
    $options = new QROptions([
        'outputInterface' => QRMarkupSVG::class,
        'outputBase64' => false,
        'eccLevel' => EccLevel::M,
        'svgAddXmlHeader' => true,
        'connectPaths' => true,
    ]);

    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo (new QRCode($options))->render($url);
} catch (Throwable $e) {
    error_log('Onboarding QR: ' . $e->getMessage());
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QR-Code konnte nicht erzeugt werden.';
}
