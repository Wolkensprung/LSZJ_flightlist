<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';

function lszj_require_page_login(): array
{
    $user = auth_user();
    if ($user === null) {
        $target = basename((string)($_SERVER['REQUEST_URI'] ?? 'dashboard.php'));
        header('Location: login.php?reason=expired&return=' . rawurlencode($target));
        exit;
    }
    return $user;
}

function lszj_require_page_role(string $role): array
{
    $user = lszj_require_page_login();
    if (!has_role($role) && !has_role('ADMIN')) {
        http_response_code(403);
        exit('Fehlende Berechtigung.');
    }
    return $user;
}
