<?php

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/permissions.php';

auth_login(321, 'SMARTPHONE');

require_role('ADMIN');

echo "ADMIN OK";