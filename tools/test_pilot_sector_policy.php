<?php
declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Vereinsflieger/PilotSectorPolicy.php';

use LSZJ\Vereinsflieger\PilotSectorPolicy as Policy;

$tests = [
    ['sectors' => ['SF'], 'glider' => 1, 'motor' => 0],
    ['sectors' => ['MS'], 'glider' => 1, 'motor' => 1],
    ['sectors' => ['MF'], 'glider' => 0, 'motor' => 1],
    ['sectors' => ['Segelflug'], 'glider' => 1, 'motor' => 0],
    ['sectors' => ['Motorsegler'], 'glider' => 1, 'motor' => 1],
    ['sectors' => ['Motorflug'], 'glider' => 0, 'motor' => 1],
    ['sectors' => ['SF', 'MF'], 'glider' => 1, 'motor' => 1],
    ['sectors' => [], 'glider' => 0, 'motor' => 0],
    ['sectors' => ['Andere Sparte'], 'glider' => 0, 'motor' => 0],
];

foreach ($tests as $test) {
    $result = Policy::capabilities($test['sectors']);

    if (
        $result['can_fly_glider'] !== $test['glider']
        || $result['can_fly_motor'] !== $test['motor']
    ) {
        fwrite(
            STDERR,
            'FAIL: '
            . json_encode(
                $test['sectors'],
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            )
            . ' erwartet glider='
            . $test['glider']
            . ', motor='
            . $test['motor']
            . '; erhalten glider='
            . $result['can_fly_glider']
            . ', motor='
            . $result['can_fly_motor']
            . PHP_EOL
        );
        exit(1);
    }
}

echo 'PASS PilotSectorPolicy', PHP_EOL;
