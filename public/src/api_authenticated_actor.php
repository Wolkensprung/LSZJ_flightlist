<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';

/**
 * Liefert den angemeldeten Bearbeiter für schreibende APIs.
 * Der Client darf die Identität nicht vorgeben.
 */
function api_authenticated_actor(array $allowedRoles = ['PILOT', 'DUTY_OFFICER', 'ADMIN']): array
{
    $user = auth_user();
    if ($user === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Anmeldung erforderlich.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $roles = is_array($user['roles'] ?? null) ? $user['roles'] : [];
    if (!array_intersect($allowedRoles, $roles)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Fehlende Berechtigung.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    return [
        'id' => (int)$user['id'],
        'display_name' => (string)$user['display_name'],
        'roles' => $roles,
    ];
}
