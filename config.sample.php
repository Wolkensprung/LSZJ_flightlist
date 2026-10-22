<?php
return [
    'db' => [
        'dsn' => 'mysql:host=localhost;port=3306;dbname=lszj_flightlist;charset=utf8mb4',
        'user' => 'lszj',
        'password' => 'PASSWORT'
    ],
    'ktrax' => [
        'base_url' => 'https://ktrax.kisstech.ch/backend/logbook',
        'api_key' => 'PASSWORT',
        'default_airfield' => 'lszj',
    ],
    'app' => [
        'timezone' => 'Europe/Zurich',
    ],
    'vereinsflieger_api' => [
    'base_url' => 'https://www.vereinsflieger.de/interface/rest',
    'username' => 'schnittstelle@lszj.ch',
    'password' => 'PASSWORT',
    'appkey' => 'PASSWORT',
    'cid' => '1488',
    'auth_secret' => '',
    ],

    'webauthn' => [
        'rp_name' => 'LSZJ Startliste',
        'rp_id' => 'localhost',
        'allowed_origins' => [
            'http://localhost:8000',
        ],
    ],

    'auth' => [
    'allow_legacy_login' => true,
    ],

'smtp' => [
    'host' => 'asmtp.mail.hostpoint.ch',
    'port' => 587,
    'encryption' => 'tls',
    'username' => 'startlist@lszj.ch',
    'password' => 'PASSWORT',
    'from_address' => 'startlist@lszj.ch',
    'from_name' => 'LSZJ Startlist',
],

'recovery' => [
    'base_url' => 'http://localhost:8000',
],

'qr_login' => [
    'base_url' => 'http://localhost:8000',
    'ttl_seconds' => 120,
],

];