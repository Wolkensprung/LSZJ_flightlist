<?php
declare(strict_types=1);

function webauthn_config(): array
{
    if (!function_exists('app_config')) {
        throw new RuntimeException('Die zentrale Anwendungskonfiguration ist nicht geladen.');
    }
    $app = app_config();
    $config = $app['webauthn'] ?? null;
    if (!is_array($config)) {
        throw new RuntimeException('WebAuthn-Konfiguration fehlt.');
    }
    $rpName = trim((string)($config['rp_name'] ?? ''));
    $rpId = strtolower(trim((string)($config['rp_id'] ?? '')));
    $origins = $config['allowed_origins'] ?? [];
    if ($rpName === '' || $rpId === '') {
        throw new RuntimeException('WebAuthn rp_name oder rp_id fehlt.');
    }
    if (str_contains($rpId, '://') || str_contains($rpId, '/') || str_contains($rpId, ':')) {
        throw new RuntimeException('WebAuthn rp_id muss ein Hostname ohne Schema, Port oder Pfad sein.');
    }
    if (!is_array($origins) || $origins === []) {
        throw new RuntimeException('Mindestens eine erlaubte WebAuthn-Origin ist erforderlich.');
    }
    $normalized = [];
    foreach ($origins as $origin) {
        $origin = rtrim(trim((string)$origin), '/');
        if ($origin === '' || filter_var($origin, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Ungültige WebAuthn-Origin.');
        }
        $scheme = strtolower((string)parse_url($origin, PHP_URL_SCHEME));
        $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
        if ($scheme !== 'https' && !($scheme === 'http' && $host === 'localhost')) {
            throw new RuntimeException('WebAuthn erfordert HTTPS; HTTP ist nur auf localhost zulässig.');
        }
        $normalized[] = $origin;
    }
    return ['rp_name'=>$rpName, 'rp_id'=>$rpId, 'allowed_origins'=>array_values(array_unique($normalized))];
}
