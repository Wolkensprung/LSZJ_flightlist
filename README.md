# LSZJ Dropdowns für Piloten und Luftfahrzeuge

Das Paket ergänzt die drei Masken:
- `public/flight_approvals.php`
- `public/manual_flight.php`
- `public/flight_correction.php`

## Installation

1. Paketinhalt in das Projekt-Root kopieren.
2. PowerShell im Projekt-Root:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\tools\install_dropdowns.ps1
```

3. Syntax prüfen:

```powershell
php -l .\src\api_search_pilots.php
php -l .\src\api_search_aircraft.php
php -l .\public\flight_approvals.php
php -l .\public\manual_flight.php
php -l .\public\flight_correction.php
```

4. PHP-Server neu starten und Browser mit Strg+F5 laden.

## Verhalten

- Textfelder werden automatisch anhand von `id`/`name` erkannt.
- Pilotensuche durchsucht standardmässig Fliegende Mitglieder, Flugschüler und GVVC-Mitglieder.
- Checkbox `Alle bekannten Piloten anzeigen` erweitert auf alle aktiven, auswählbaren Personen.
- Luftfahrzeuge werden über Callsign, Wettbewerbskennzeichen und Muster gefunden; Vereinsflugzeuge stehen zuerst.
- Bestehende Feldnamen und Save-APIs bleiben unverändert. Nach Auswahl steht weiterhin der kanonische Name bzw. das kanonische Callsign im bestehenden Textfeld.

## Datensicherung

Das Installationsskript erstellt je Maske automatisch eine Sicherung mit Endung `.bak-dropdowns`.
