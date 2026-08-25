<?php

require_once __DIR__ . '/../src/auth.php';

$user = auth_login(321, 'C_BUERO');

echo '<pre>';
print_r($user);
echo '</pre>';