<?php
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $configFile = __DIR__ . '/../config.php';
    if (!file_exists($configFile)) {
        throw new RuntimeException('config.php fehlt. config.sample.php kopieren und Werte setzen.');
    }
    $config = require $configFile;
    date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Zurich');
    $pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}
function app_config(): array {
    return require __DIR__ . '/../config.php';
}
