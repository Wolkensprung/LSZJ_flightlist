<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/permissions.php';
require_once __DIR__ . '/../src/duty_officer.php';

$user = auth_user();
if ($user === null) {
    header('Location: login.php?reason=expired');
    exit;
}
$message = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_require_valid($_POST['csrf_token'] ?? null);
        $action = trim((string)($_POST['action'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($action === 'start') {
            duty_officer_start();
            $message = 'Der Flugdienstleiterdienst wurde übernommen.';
        } elseif ($action === 'end') {
            duty_officer_end($reason !== '' ? $reason : null);
            $message = 'Der Flugdienstleiterdienst wurde beendet.';
        } elseif ($action === 'handover') {
            $newUserId = (int)($_POST['new_user_id'] ?? 0);
            if ($newUserId <= 0) throw new RuntimeException('Bitte einen berechtigten Benutzer auswählen.');
            duty_officer_handover($newUserId, $reason !== '' ? $reason : null);
            $message = 'Der Flugdienstleiterdienst wurde übergeben.';
        } elseif ($action === 'admin_end') {
            duty_officer_admin_end($reason);
            $message = 'Der Flugdienstleiterdienst wurde administrativ beendet.';
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$activeDutyOfficer = duty_officer_active();
$isCurrentDutyOfficer = $activeDutyOfficer !== null && (int)$activeDutyOfficer['user_id'] === (int)$user['id'];
$isAdmin = has_role('ADMIN');
$canTakeDuty = duty_officer_user_is_eligible((int)$user['id']);
$candidateSql = "SELECT DISTINCT u.id, u.display_name
                 FROM users u
                 JOIN pilots_master pm ON pm.id=u.pilot_master_id
                 JOIN user_roles ur ON ur.user_id=u.id
                 JOIN roles r ON r.id=ur.role_id
                 WHERE u.active=1 AND pm.is_active=1 AND pm.is_selectable=1
                   AND pm.priority_group IN ('flying_member','student','gvvc')
                   AND r.code IN ('DUTY_OFFICER','ADMIN')
                   AND (ur.valid_from IS NULL OR ur.valid_from<=NOW())
                   AND (ur.valid_until IS NULL OR ur.valid_until>=NOW())
                   AND u.id<>:current_user_id ORDER BY u.display_name";
$stmt=db()->prepare($candidateSql);
$stmt->execute(['current_user_id'=>(int)$user['id']]);
$handoverCandidates=$stmt->fetchAll();
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>LSZJ Flugdienstleiter</title><link rel="stylesheet" href="app.css"><style>
.duty-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}.duty-actions .card{margin:0}.duty-actions label{display:block;margin:8px 0}.duty-actions select,.duty-actions textarea{display:block;width:100%;box-sizing:border-box}.alert-success{background:#eefaf1;padding:10px}.alert-error{background:#fff0f0;color:#a00018;padding:10px}
</style></head><body>
<div class="card"><div class="row"><div><strong>Angemeldet:</strong> <?= htmlspecialchars((string)$user['display_name'],ENT_QUOTES,'UTF-8') ?></div><div><strong>Rollen:</strong> <?= htmlspecialchars(implode(', ',$user['roles']??[]),ENT_QUOTES,'UTF-8') ?></div><div><a class="button secondary" href="dashboard.php">Dashboard</a></div><?php if($isAdmin): ?><div><a class="button secondary" href="user_admin.php">Benutzerverwaltung</a></div><?php endif; ?><div><a class="button secondary" href="logout.php">Logout</a></div></div></div>
<h1>Flugdienstleiter</h1>
<?php if($message): ?><p class="alert-success"><?= htmlspecialchars($message,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
<?php if($error): ?><p class="alert-error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
<div class="card"><?php if($activeDutyOfficer): ?><strong>Aktiver Flugdienstleiter:</strong> <?= htmlspecialchars((string)$activeDutyOfficer['display_name'],ENT_QUOTES,'UTF-8') ?><div>Seit <?= htmlspecialchars(date('d.m.Y H:i',strtotime((string)$activeDutyOfficer['start_time'])),ENT_QUOTES,'UTF-8') ?> Uhr</div><?php else: ?><strong>⚠ Kein Flugdienstleiter aktiv</strong><?php endif; ?></div>
<div class="duty-actions">
<?php if(!$activeDutyOfficer): ?>
<div class="card"><h2>Dienst übernehmen</h2><?php if($canTakeDuty): ?><form method="post" onsubmit="return confirm('Dienst jetzt übernehmen?');"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="start"><button class="ok" type="submit">Dienst übernehmen</button></form><?php else: ?><p class="warnbox">Keine Berechtigung zur Dienstübernahme.</p><?php endif; ?></div>
<?php elseif($isCurrentDutyOfficer): ?>
<div class="card"><h2>Dienst übergeben</h2><?php if($handoverCandidates): ?><form method="post" onsubmit="return confirm('Dienst übergeben?');"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="handover"><label>Neuer Flugdienstleiter<select name="new_user_id" required><option value="">Bitte wählen</option><?php foreach($handoverCandidates as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars((string)$c['display_name'],ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?></select></label><label>Bemerkung<textarea name="reason" maxlength="1000"></textarea></label><button type="submit">Dienst übergeben</button></form><?php endif; ?></div>
<div class="card"><h2>Dienst beenden</h2><form method="post" onsubmit="return confirm('Dienst wirklich beenden?');"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="end"><label>Bemerkung<textarea name="reason" maxlength="1000"></textarea></label><button class="warn" type="submit">Dienst beenden</button></form></div>
<?php else: ?>
<div class="card"><h2>Dienst belegt</h2><p>Nur der aktive Flugdienstleiter kann regulär übergeben oder beenden.</p></div>
<?php endif; ?>
<?php if($activeDutyOfficer && $isAdmin && !$isCurrentDutyOfficer): ?>
<div class="card"><h2>Administrativ beenden</h2><p>Diese Aktion ist nur für Ausnahmefälle vorgesehen und wird mit Administrator und Begründung protokolliert.</p><form method="post" onsubmit="return confirm('Fremden Flugdienstleiterdienst administrativ beenden?');"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="admin_end"><label>Begründung (Pflicht)<textarea name="reason" maxlength="1000" required></textarea></label><button class="warn" type="submit">Administrativ beenden</button></form></div>
<?php endif; ?>
</div></body></html>
