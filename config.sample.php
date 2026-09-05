<?php
return [
    'db' => [
        'dsn' => 'mysql:host=localhost;port=3306;dbname=lszj_flightlist;charset=utf8mb4',
        'user' => 'lszj',
        'password' => 'DB-USER_PASWWORT'
    ],
    'ktrax' => [
        'base_url' => 'https://ktrax.kisstech.ch/backend/logbook',
        'api_key' => 'LSZJ-API_KEY',
        'default_airfield' => 'lszj',
    ],
    'app' => [
        'timezone' => 'Europe/Zurich',
    ],
    'vereinsflieger_api' => [
    'base_url' => 'https://www.vereinsflieger.de/interface/rest',
    'username' => 'schnittstelle@lszj.ch',
    'password' => 'STARKES PASSWORT',
    'appkey' => 'APP-KEY-BEISPIEL',
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

];