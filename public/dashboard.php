<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/page_security.php';
require_once __DIR__ . '/../src/duty_officer.php';

$currentUser = lszj_require_page_login();
$isAdmin = has_role('ADMIN');
$isDutyOfficer = has_role('DUTY_OFFICER');
$isPilot = has_role('PILOT');
$activeDutyOfficer = duty_officer_active();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LSZJ Dashboard</title>
    <link rel="stylesheet" href="app.css">
    <style>
        .dashboard-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:1rem; margin-top:1rem; }
        .dashboard-card { display:flex; flex-direction:column; gap:.75rem; }
        .dashboard-card h2 { margin:0; }
        .dashboard-actions { display:flex; flex-wrap:wrap; gap:.6rem; margin-top:auto; }
        .dashboard-note { color:#4b5563; line-height:1.45; }
        .role-badge { display:inline-block; margin-right:.4rem; padding:.2rem .5rem; border-radius:999px; background:#e2e8f0; font-size:.85rem; font-weight:700; }
        .workflow-steps { margin:0; padding-left:1.25rem; }
        .workflow-steps li { margin:.25rem 0; }
        .duty-status { border-left:6px solid #17823b; background:#eefaf1; }
        .duty-status.vacant { border-left-color:#b36b00; background:#fff8e5; }
        .duty-status strong { font-size:1.1rem; }
    </style>
</head>
<body>
<div class="card">
    <div class="row">
        <div><strong>Angemeldet:</strong> <?= htmlspecialchars((string)($currentUser['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
        <div>
            <?php if ($isPilot): ?><span class="role-badge">Pilot</span><?php endif; ?>
            <?php if ($isDutyOfficer): ?><span class="role-badge">Flugdienstleiter</span><?php endif; ?>
            <?php if ($isAdmin): ?><span class="role-badge">Administrator</span><?php endif; ?>
        </div>
    </div>
</div>

<h1>LSZJ Dashboard</h1>

<?php if ($activeDutyOfficer !== null): ?>
    <section class="card duty-status">
        <strong>Aktiver Flugdienstleiter:</strong>
        <?= htmlspecialchars((string)$activeDutyOfficer['display_name'], ENT_QUOTES, 'UTF-8') ?>
        <div>Seit <?= htmlspecialchars(date('d.m.Y H:i', strtotime((string)$activeDutyOfficer['start_time'])), ENT_QUOTES, 'UTF-8') ?> Uhr</div>
    </section>
<?php else: ?>
    <section class="card duty-status vacant">
        <strong>Kein Flugdienstleiter aktiv</strong>
        <p>Der Flugdienstleiterdienst ist derzeit nicht besetzt.</p>
        <?php if ($isPilot): ?>
            <a class="button" href="duty_officer.php">Dienst übernehmen</a>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="dashboard-grid">
    <section class="card dashboard-card">
        <h2>Flugbetrieb</h2>
        <p class="dashboard-note">Flüge prüfen, freigeben und bei Bedarf manuell erfassen.</p>
        <div class="dashboard-actions">
            <a class="button" href="flight_approvals.php">Flugfreigaben</a>
            <a class="button secondary" href="manual_flight.php">Flug manuell erfassen</a>
            <a class="button secondary" href="duty_officer.php">Flugdienstleiter</a>
        </div>
    </section>

    <?php if ($isPilot || $isAdmin): ?>
        <section class="card dashboard-card">
            <h2>Tagesabschluss Flugdienstleiter</h2>
            <p class="dashboard-note">Der Betriebstag wird erst nach vollständiger Prüfung und Freigabe aller Flüge abgeschlossen. Der Abschluss gibt die Tagesdaten für den Export nach Vereinsflieger frei.</p>
            <ol class="workflow-steps"><li>Flugdaten kontrollieren</li><li>Alle Flüge freigeben</li><li>Tagesabschlussprüfung auf Grün bringen</li><li>Betriebstag abschliessen und für VF freigeben</li></ol>
            <div class="dashboard-actions"><a class="button" href="flight_day.php">Tagesabschluss öffnen</a><a class="button secondary" href="duty_officer.php">Flugdienstleiterdienst</a></div>
        </section>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <section class="card dashboard-card">
            <h2>C4-Tagesexport nach VF</h2>
            <p class="dashboard-note">Im Pilotbetrieb führt ein Administrator den Export nach jedem abgeschlossenen Flugtag manuell aus.</p>
            <div class="dashboard-actions"><a class="button" href="vf_day_export.php">C4-Tagesexport öffnen</a><a class="button secondary" href="vf_export_journal.php">Exportjournal</a></div>
        </section>
        <section class="card dashboard-card">
            <h2>Korrekturen exportierter Flüge</h2>
            <p class="dashboard-note">Lokale Korrekturen und die manuelle Abstimmung mit VF werden revisionssicher geführt.</p>
            <div class="dashboard-actions"><a class="button" href="vf_export_changes.php">D1-Änderungsliste</a><a class="button secondary" href="vf_manual_sync.php">Manuelle VF-Synchronisation</a></div>
        </section>
        <section class="card dashboard-card">
            <h2>Administration</h2>
            <div class="dashboard-actions"><a class="button" href="user_admin.php">Benutzerverwaltung</a></div>
        </section>
    <?php endif; ?>
</div>
</body>
</html>
