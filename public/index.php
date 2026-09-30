<?php
declare(strict_types=1);

// Zentrale Startseite der LSZJ-Testumgebung.
// qr_login.php leitet bereits angemeldete Benutzer zum Dashboard weiter.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: qr_login.php', true, 302);
exit;
