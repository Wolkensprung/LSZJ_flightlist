<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$pdo = db();
$filename = 'member_import_' . date('Ymd_His') . '.csv';

$header = [
    'title','lastname','firstname','street','zipcode','town','cyid','email','gender','birthday',
    'homenumber','mobilenumber','phonenumber','phonenumber2','memberid','msid','memberbegin',
    'memberend','comment','bank','clid','bankaccountname','bankaccountinfo','lettertitle',
    'invoiceshippingmode','directdebitauth','iban','bic','mandate','mandatedate',
    'function_1','function_2','function_3','function_4','function_5','function_6','function_7',
    'function_8','function_9','function_10','sector_1','sector_2','sector_3','sector_4',
    'sector_5','sector_6','sector_7','sector_8','sector_9','sector_10','nickname','roid'
];

$stmt = $pdo->query(
    "SELECT id, last_name, first_name, email, phone, street, postal_code, city, country
     FROM external_contacts
     WHERE is_active = 1
       AND vf_exported_at IS NULL
     ORDER BY last_name, first_name, id"
);
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out = fopen('php://output', 'wb');
if ($out === false) {
    http_response_code(500);
    exit('CSV-Ausgabe konnte nicht geöffnet werden.');
}

fputcsv($out, $header, ';', '"', '\\');
$ids = [];

foreach ($contacts as $contact) {
    $row = array_fill_keys($header, '');
    $row['lastname'] = (string)($contact['last_name'] ?? '');
    $row['firstname'] = (string)($contact['first_name'] ?? '');
    $row['street'] = (string)($contact['street'] ?? '');
    $row['zipcode'] = (string)($contact['postal_code'] ?? '');
    $row['town'] = (string)($contact['city'] ?? '');
    $row['cyid'] = 3; // Schweiz
    $row['email'] = (string)($contact['email'] ?? '');
    $row['mobilenumber'] = (string)($contact['phone'] ?? '');
    $row['msid'] = 6; // Sonstige
    $row['comment'] = 'Externer Pilot/FI, importiert aus LSZJ Startlisten';
    $row['clid'] = 16574; // Nichtmitglied
    $row['invoiceshippingmode'] = 1; // E-Mail
    $row['directdebitauth'] = 0;

    fputcsv($out, array_values($row), ';', '"', '\\');
    $ids[] = (int)$contact['id'];
}

fflush($out);
fclose($out);

if ($ids !== []) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $update = $pdo->prepare(
        "UPDATE external_contacts
         SET vf_exported_at = NOW()
         WHERE vf_exported_at IS NULL
           AND id IN ($placeholders)"
    );
    $update->execute($ids);
}
