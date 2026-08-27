<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/duty_officer.php';

try {
    $count = duty_officer_close_stale_at_midnight();
    fwrite(STDOUT, sprintf("%s: %d offene Flugdienstleiter-Schicht(en) geschlossen.%s", date('c'), $count, PHP_EOL));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
