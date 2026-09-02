<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
$showAll = (string)($_GET['all'] ?? '') === '1';
$context = strtolower(trim((string)($_GET['context'] ?? '')));

if ($q === '') {
    echo json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/*
 * Im Login-Kontext gelten immer die fachlich zugelassenen Gruppen.
 * In den bestehenden Erfassungsmasken kann der Schalter "Alle bekannten
 * Piloten anzeigen" weiterhin mit all=1 verwendet werden.
 */
$priorityFilter = (!$showAll || $context === 'login')
    ? "AND pm.priority_group IN ('flying_member', 'student', 'gvvc')"
    : '';

$sql = "
    SELECT *
    FROM (
        SELECT
            u.id AS user_id,
            pm.id AS source_id,
            'pilot' AS source_type,
            pm.display_name,
            pm.vf_user_no,
            pm.vf_member_no,
            pm.email,
            pm.priority_group,
            NULL AS external_contact_id
        FROM pilots_master pm
        LEFT JOIN users u
               ON u.pilot_master_id = pm.id
              AND u.active = 1
        WHERE pm.is_active = 1
          AND pm.is_selectable = 1
          {$priorityFilter}
          AND (
                pm.display_name LIKE :pilot_q1
             OR pm.search_name LIKE :pilot_q2
             OR pm.vf_user_no LIKE :pilot_q3
             OR pm.vf_member_no LIKE :pilot_q4
          )

        UNION ALL

        SELECT
            u.id AS user_id,
            ec.id AS source_id,
            'external_contact' AS source_type,
            COALESCE(
                NULLIF(ec.name, ''),
                CONCAT_WS(', ', NULLIF(ec.last_name, ''), NULLIF(ec.first_name, ''))
            ) AS display_name,
            NULL AS vf_user_no,
            NULL AS vf_member_no,
            ec.email,
            'external_contact' AS priority_group,
            ec.id AS external_contact_id
        FROM external_contacts ec
        LEFT JOIN users u
               ON u.external_contact_id = ec.id
              AND u.active = 1
        WHERE ec.is_active = 1
          AND (
                ec.name LIKE :external_q1
             OR ec.last_name LIKE :external_q2
             OR ec.first_name LIKE :external_q3
             OR ec.email LIKE :external_q4
          )
    ) results
    ORDER BY display_name
    LIMIT 20";

$like = '%' . $q . '%';
$stmt = db()->prepare($sql);
$stmt->execute([
    'pilot_q1' => $like,
    'pilot_q2' => $like,
    'pilot_q3' => $like,
    'pilot_q4' => $like,
    'external_q1' => $like,
    'external_q2' => $like,
    'external_q3' => $like,
    'external_q4' => $like,
]);

echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
