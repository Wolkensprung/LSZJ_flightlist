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

        if ($action === 'start') {
            duty_officer_start();
            $message = 'Der Flugdienstleiterdienst wurde übernommen.';
        } elseif ($action === 'end') {
            $reason = trim((string)($_POST['reason'] ?? ''));
            duty_officer_end($reason !== '' ? $reason : null);
            $message = 'Der Flugdienstleiterdienst wurde beendet.';
        } elseif ($action === 'handover') {
            $newUserId = (int)($_POST['new_user_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($newUserId <= 0) {
                throw new RuntimeException('Bitte einen berechtigten Benutzer für die Übergabe auswählen.');
            }
            duty_officer_handover($newUserId, $reason !== '' ? $reason : null);
            $message = 'Der Flugdienstleiterdienst wurde übergeben.';
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$activeDutyOfficer = duty_officer_active();
$isCurrentDutyOfficer = $activeDutyOfficer !== null
    && (int)$activeDutyOfficer['user_id'] === (int)$user['id'];
$canTakeDuty = duty_officer_user_is_eligible((int)$user['id']);

$candidateSql = "SELECT DISTINCT u.id, u.display_name
                 FROM users u
                 INNER JOIN pilots_master pm ON pm.id = u.pilot_master_id
                 INNER JOIN user_roles ur ON ur.user_id = u.id
                 INNER JOIN roles r ON r.id = ur.role_id
                 WHERE u.active = 1
                   AND pm.is_active = 1
                   AND pm.is_selectable = 1
                   AND pm.priority_group IN ('flying_member', 'student', 'gvvc')
                   AND r.code IN ('DUTY_OFFICER', 'ADMIN')
                   AND (ur.valid_from IS NULL OR ur.valid_from <= NOW())
                   AND (ur.valid_until IS NULL OR ur.valid_until >= NOW())
                   AND u.id <> :current_user_id
                 ORDER BY u.display_name";
$candidateStmt = db()->prepare($candidateSql);
$candidateStmt->execute(['current_user_id' => (int)$user['id']]);
$handoverCandidates = $candidateStmt->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>LSZJ Flugdienstleiter</title>
<link rel="stylesheet" href="app.css">
<style>
.duty-status{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.duty-dot{font-size:1.5rem;line-height:1}.duty-meta{color:#667085;font-size:.9rem;margin-top:4px}.duty-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}.duty-actions .card{margin:0}.duty-actions label{display:block;margin:8px 0}.duty-actions select,.duty-actions textarea{display:block;width:100%;margin:5px 0 0}.alert-success{background:#eefaf1;border-left:5px solid #17823b;padding:10px;border-radius:6px}.alert-error{background:#fff5f5;border-left:5px solid #b00020;padding:10px;border-radius:6px}
</style>
</head>
<body>
<div class="card"><div class="row"><div><strong>Angemeldet:</strong> <?= htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8') ?></div><div><strong>Rollen:</strong> <?= htmlspecialchars(implode(', ', $user['roles'] ?? []), ENT_QUOTES, 'UTF-8') ?></div><div><a class="button secondary" href="dashboard.php">Dashboard</a></div><div><a class="button secondary" href="logout.php">Logout</a></div></div></div>
<h1>Flugdienstleiter</h1>
<?php if ($message !== null): ?><p class="alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if ($error !== null): ?><p class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<div class="card">
<?php if ($activeDutyOfficer !== null): ?>
<div class="duty-status"><span class="duty-dot" aria-hidden="true">🟢</span><div><strong>Aktiver Flugdienstleiter:</strong> <?= htmlspecialchars((string)$activeDutyOfficer['display_name'], ENT_QUOTES, 'UTF-8') ?><div class="duty-meta">Seit <?= htmlspecialchars(date('d.m.Y H:i', strtotime((string)$activeDutyOfficer['start_time'])), ENT_QUOTES, 'UTF-8') ?> Uhr</div></div></div>
<?php else: ?>
<div class="duty-status warnbox"><span class="duty-dot" aria-hidden="true">⚠</span><strong>Kein Flugdienstleiter aktiv</strong></div>
<?php endif; ?>
</div>
<div class="duty-actions">
<?php if ($activeDutyOfficer === null): ?>
<div class="card"><h2>Dienst übernehmen</h2>
<?php if ($canTakeDuty): ?>
<p>Mit der Bestätigung übernimmst du die Funktion als Flugdienstleiter.</p>
<form method="post" onsubmit="return confirm('Flugdienstleiterdienst jetzt übernehmen?');"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="start"><button class="ok" type="submit">Dienst übernehmen</button></form>
<?php else: ?>
<p class="warnbox">Zulässig sind nur aktive fliegende Mitglieder, Flugschüler oder GVVC-Mitglieder mit Rolle Flugdienstleiter beziehungsweise Administrator.</p>
<?php endif; ?></div>
<?php elseif ($isCurrentDutyOfficer): ?>
<div class="card"><h2>Dienst übergeben</h2>
<?php if ($handoverCandidates): ?>
<form method="post" onsubmit="return confirm('Flugdienstleiterdienst an die ausgewählte Person übergeben?');"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="handover"><label>Neuer Flugdienstleiter<select name="new_user_id" required><option value="">Bitte wählen</option><?php foreach ($handoverCandidates as $candidate): ?><option value="<?= (int)$candidate['id'] ?>"><?= htmlspecialchars((string)$candidate['display_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label><label>Übergabegrund / Bemerkung<textarea name="reason" maxlength="1000"></textarea></label><button type="submit">Dienst übergeben</button></form>
<?php else: ?><p class="warnbox">Es ist kein weiterer berechtigter Benutzer mit zulässiger Kostenstufe für eine Übergabe vorhanden.</p><?php endif; ?></div>
<div class="card"><h2>Dienst beenden</h2><form method="post" onsubmit="return confirm('Flugdienstleiterdienst wirklich beenden? Danach ist kein Flugdienstleiter aktiv.');"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="end"><label>Begründung / Bemerkung<textarea name="reason" maxlength="1000"></textarea></label><button class="warn" type="submit">Dienst beenden</button></form></div>
<?php else: ?>
<div class="card"><h2>Dienst belegt</h2><p>Nur der aktuell aktive Flugdienstleiter kann den Dienst übergeben oder beenden.</p></div>
<?php endif; ?>
</div>
<script src="i18n_hotfix_02.js?v=20260818_1"></script>
</body></html>
