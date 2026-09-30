# QR-Login Abschlusssprint

## Inhalt

- Iteration 3: approved -> consumed -> C_BUERO-Sitzung -> Dashboard
- `public/logout_qr.php`: beendet die Sitzung und leitet zu `qr_login.php`
- `tools/apply_dashboard_qr_logout.ps1`: ergänzt den Logout-Knopf in `public/dashboard.php`
- optionale Migration 004 zum späteren Löschen der Legacy-Tabelle

## Dashboard-Patch anwenden

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\tools\apply_dashboard_qr_logout.ps1
```

Danach `public/dashboard.php` visuell prüfen. Das Skript fügt den Link vor `</header>` ein; falls kein Header vorhanden ist, direkt nach `<body>`.

## Syntaxprüfung

```powershell
php -l .\src\qr_login_consume.php
php -l .\src\api_qr_login_consume.php
php -l .\public\api_qr_login_consume.php
php -l .\public\qr_login.php
php -l .\public\logout_qr.php
php -l .\public\dashboard.php
```

## Legacy-Tabelle

Migration 004 erst nach erfolgreichem Ende-zu-Ende-Test ausführen. Sie ist absichtlich nicht automatisch erforderlich.
