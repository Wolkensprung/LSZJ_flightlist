<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

auth_logout();

header('Location: qr_login.php', true, 303);
exit;
