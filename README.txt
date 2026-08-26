LSZJ Phase 2: Navigation und Seitenabsicherung

Inhalt
- src/page_security.php
- tools/install_navigation_security.ps1

Was installiert wird
- Login-Pflicht für public/flight_approvals.php
- Login-Pflicht für public/manual_flight.php
- Login-Pflicht für public/flight_correction.php
- ADMIN-Pflicht für public/master_data_import.php
- Navigationspunkt Flugdienstleiter in Dashboard, Flugfreigaben und manueller Erfassung

Installation in PowerShell
1. ZIP in C:\Projekte\LSZJ_flightlist entpacken.
2. Im Projektstamm ausführen:

   powershell -ExecutionPolicy Bypass -File .	ools\install_navigation_security.ps1

Das Skript erstellt vor jeder geänderten PHP-Datei automatisch ein zeitgestempeltes Backup.
Es ist idempotent und fügt Schutz beziehungsweise Navigation nicht doppelt ein.

Tests
1. Logout aufrufen und jede der drei Arbeitsseiten direkt öffnen. Erwartung: Weiterleitung zu login.php.
2. Als Pilot anmelden. Flugfreigaben, manuelle Erfassung und Flugkorrektur müssen erreichbar sein.
3. master_data_import.php als Pilot ohne ADMIN öffnen. Erwartung: HTTP 403 / Fehlende Berechtigung.
4. Als Administrator neu anmelden. master_data_import.php muss erreichbar sein.
5. In der Navigation muss Flugdienstleiter erscheinen und public/duty_officer.php öffnen.

Hinweis
Die APIs werden mit diesem Paket bewusst noch nicht pauschal geschützt. Das erfolgt zusammen mit dem vorgemerkten Refactoring, bei dem Bearbeitername und Freigaben serverseitig aus auth_user() bezogen werden und der user-Parameter aus URLs/Formularen entfernt wird.
