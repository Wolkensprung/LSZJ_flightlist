# Vereinsflieger-Stammdatenimport

## Zweck

Der Importer liest die standardisierten Vereinsflieger-Dateien `Mitglieder.xlsx` und `Luftfahrzeuge.xlsx` ein.

Gespeicherte Mitgliederfelder:
- MitgliedsNr
- Name im Vereinsflieger-Format
- Mitgliedsstatus
- Kostenstufe

Nicht gespeichert werden Adressen, E-Mail-Adressen oder Telefonnummern.

Primaer auswählbar sind Personen der Kostenstufen:
- Fliegendes Mitglied
- Flugschüler
- GVVC Mitglied

Alle anderen Personen bleiben als optionale Auswahl vorhanden. Nicht personenbezogene Einträge ohne Komma im Namensfeld werden nicht als Pilot auswählbar markiert.

## Installation

1. Den Inhalt dieses Pakets in das Root-Verzeichnis von `LSZJ_flightlist` kopieren.
2. In PowerShell im Projektverzeichnis:

```powershell
composer install
```

Falls Composer noch nicht installiert ist:

```powershell
winget install Composer.Composer
```

3. Falls Composer fehlende PHP-Erweiterungen meldet, in der aktiven `php.ini` folgende Erweiterungen aktivieren:

```ini
extension=mbstring
extension=zip
extension=gd
```

Danach ein neues Terminal öffnen.

4. Tabellen anlegen. In PowerShell funktioniert die SQL-Weiterleitung zuverlässig via Pipeline:

```powershell
Get-Content .\sql\010_master_data.sql | mariadb -h localhost -P 3306 -u root lszj_flightlist
```

## Import über Browser

PHP-Server im Projekt-Root starten:

```powershell
php -S localhost:8000
```

Dann öffnen:

```text
http://localhost:8000/public/master_data_import.php
```

Beide Excel-Dateien auswählen und importieren.

## Import über Kommandozeile

```powershell
php .\bin\import_master_data.php .\Mitglieder.xlsx .\Luftfahrzeuge.xlsx
```

## Ergebnis

- `pilots_master`: kanonische Vereinsflieger-Namen und Prioritätsgruppe
- `aircraft_master`: normalisierte Callsigns und Luftfahrzeugstammdaten
- `master_data_import_runs`: Audit-Log jedes Imports

Vorhandene Datensätze werden per Upsert aktualisiert. Datensätze, die in einem späteren Vollimport fehlen, werden deaktiviert und nicht gelöscht.
