<?php

require_once __DIR__ . '/../src/auth.php';

echo '<pre>';
var_dump(auth_user());
echo '</pre>';