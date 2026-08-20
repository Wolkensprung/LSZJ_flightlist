<?php
/**
 * api_charge_modes.php
 *
 * Liefert die Vereinsflieger-Abrechnungsarten aus charge_modes.
 *
 * UX-Anpassung LSZJ:
 * - In der Datenbank und im Vereinsflieger-Export bleibt charge_mode = 2 weiterhin "Pilot".
 * - In der UI wird charge_mode = 2 kontextabhaengig angezeigt als:
 *   - Segelflugpilot bei Segelflugbuchungen und F-Schlepp-Buchungen
 *   - Motorflugpilot bei Schlepp-/Motorflugzeug-Fluegen ohne Segelflug
 *
 * Parameter:
 * - context=glider        -> id 2 wird als Segelflugpilot angezeigt
 * - context=tow_charge    -> id 2 wird als Segelflugpilot angezeigt
 * - context=towplane_own  -> id 2 wird als Motorflugpilot angezeigt
 * - context=default       -> Originalnamen aus charge_modes
 */

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$pdo = db();
$context = $_GET['context'] ?? 'default';

$allowedContexts = ['default', 'glider', 'tow_charge', 'towplane_own'];
if (!in_array($context, $allowedContexts, true)) {
    json_response(['ok' => false, 'error' => 'Ungueltiger context'], 400);
}

$stmt = $pdo->prepare(
    "SELECT id, name
     FROM charge_modes
     ORDER BY FIELD(id, 1, 2, 3, 5, 4, 6, 7), id"
);
$stmt->execute();
$modes = $stmt->fetchAll();

foreach ($modes as &$mode) {
    $mode['export_id'] = (int) $mode['id'];
    $mode['export_name'] = $mode['name'];
    $mode['display_name'] = $mode['name'];

    if ((int) $mode['id'] === 2) {
        if ($context === 'glider' || $context === 'tow_charge') {
            $mode['display_name'] = 'Segelflugpilot';
        } elseif ($context === 'towplane_own') {
            $mode['display_name'] = 'Motorflugpilot';
        }
    }
}
unset($mode);

json_response([
    'ok' => true,
    'context' => $context,
    'charge_modes' => $modes,
]);
