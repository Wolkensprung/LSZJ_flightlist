<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function current_user_roles(bool $refresh = false): array
{
    $user = auth_require_login();
    if ($refresh) {
        return auth_refresh_roles();
    }
    return is_array($user['roles'] ?? null) ? $user['roles'] : [];
}

function has_role(string $role): bool
{
    return in_array(strtoupper($role), current_user_roles(), true);
}

function has_any_role(array $roles): bool
{
    $current = current_user_roles();
    foreach ($roles as $role) {
        if (in_array(strtoupper((string)$role), $current, true)) {
            return true;
        }
    }
    return false;
}

function require_role(string $role, bool $adminOverride = true): void
{
    $allowed = has_role($role) || ($adminOverride && has_role('ADMIN'));
    if (!$allowed) {
        http_response_code(403);
        exit('Fehlende Berechtigung.');
    }
}

function require_any_role(array $roles, bool $adminOverride = true): void
{
    $allowed = has_any_role($roles) || ($adminOverride && has_role('ADMIN'));
    if (!$allowed) {
        http_response_code(403);
        exit('Fehlende Berechtigung.');
    }
}
