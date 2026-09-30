<?php
declare(strict_types=1);

require_once __DIR__ . '/qr_login.php';
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

    $token = (string)($_GET['token'] ?? '');
    $session = qr_login_get_status($token);

    if ((string)$session['status'] !== 'pending') {
        throw new RuntimeException('QR-Login-Session ist nicht mehr offen.');
    }

    $config = qr_login_config();
    $approveUrl = $config['base_url']
        . '/qr_approve.php?token='
        . rawurlencode($token);

    // chillerlan/php-qrcode v5: Ausgabeklasse statt alter QRCode-Konstante.
    // SVG benötigt keine GD-Erweiterung.
    $options = new QROptions([
        'outputInterface' => QRMarkupSVG::class,
        'outputBase64' => false,
        'eccLevel' => EccLevel::M,
        'svgAddXmlHeader' => true,
        'connectPaths' => true,
    ]);

    $svg = (new QRCode($options))->render($approveUrl);

    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $svg;
} catch (Throwable $e) {
    error_log('QR image: ' . $e->getMessage());
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'QR-Code konnte nicht erzeugt werden: ' . $e->getMessage();
}
