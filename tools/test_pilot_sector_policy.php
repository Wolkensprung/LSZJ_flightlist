<?php
declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Vereinsflieger/PilotSectorPolicy.php';

use LSZJ\Vereinsflieger\PilotSectorPolicy as Policy;

$tests = [
    [
        'sectors' => ['SF'],
        'glider' => 1,
        'motor' => 0,
    ],
    [
        'sectors' => ['MS'],
        'glider' => 1,
        'motor' => 1,
    ],
    [
        'sectors' => ['MF'],
        'glider' => 0,
        'motor' => 1,
    ],
    [
        'sectors' => ['Segelflug'],
        'glider' => 1,
        'motor' => 0,
    ],
    [
        'sectors' => ['Motorsegler'],
        'glider' => 1,
        'motor' => 1,
    ],
    [
        'sectors' => ['Motorflug'],
        'glider' => 0,
        'motor' => 1,
    ],
    [
        'sectors' => ['SF', 'MF'],
        'glider' => 1,
        'motor' => 1,
    ],
    [
        'sectors' => [],
        'glider' => 1,
        'motor' => 1,
    ],
    [
        'sectors' => ['Andere Sparte'],
        'glider' => 1,
        'motor' => 1,
    ],
];

foreach ($tests as $test) {
    $result = Policy::capabilities(
        $test['sectors']
    );

    $gliderMatches =
        $result['can_fly_glider']
        === $test['glider'];

    $motorMatches =
        $result['can_fly_motor']
        === $test['motor'];

    if (!$gliderMatches || !$motorMatches) {
        fwrite(
            STDERR,
            'FAIL: '
            . json_encode(
                $test['sectors'],
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            )
            . ' Erwartet Segelflug='
            . $test['glider']
            . ', Motorflug='
            . $test['motor']
            . '; erhalten Segelflug='
            . $result['can_fly_glider']
            . ', Motorflug='
            . $result['can_fly_motor']
            . PHP_EOL
        );

        exit(1);
    }
}

echo 'PASS PilotSectorPolicy', PHP_EOL;