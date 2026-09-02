<?php
declare(strict_types=1);

/**
 * TEMPORAERE Hostpoint-TEST-Variante fuer /public/src.
 * Erwartet config.php zwei Ebenen oberhalb von public/src/ und damit ausserhalb des Webroots.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $configFile = dirname(__DIR__, 2) . '/config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('config.php fehlt: ' . $configFile);
    }

    $config = require $configFile;
    if (!is_array($config) || !isset($config['db']['dsn'], $config['db']['user'], $config['db']['password'])) {
        throw new RuntimeException('config.php enthaelt keine vollstaendige DB-Konfiguration.');
    }

    date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Zurich');

    $pdo = new PDO(
        (string) $config['db']['dsn'],
        (string) $config['db']['user'],
        (string) $config['db']['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function app_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $configFile = dirname(__DIR__, 2) . '/config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('config.php fehlt: ' . $configFile);
    }

    $loaded = require $configFile;
    if (!is_array($loaded)) {
        throw new RuntimeException('config.php muss ein Array zurueckgeben.');
    }

    return $config = $loaded;
}
