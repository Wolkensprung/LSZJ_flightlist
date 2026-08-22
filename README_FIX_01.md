# Fix 01

## Ursache

`Pflichtspalte fehlt: MitgliedsNr` entsteht beim Lesen der Kopfzeile, bevor Datensätze verarbeitet werden. Mitgliedsnummer `0` ist deshalb nicht die Ursache.

## Änderungen

1. `CsvReader.php` entfernt UTF-8-BOM vor dem Parsing, erkennt Semikolon, Komma oder Tab und zeigt gefundene Header an.
2. Exakt dieser Datensatz wird übersprungen:
   - MitgliedsNr `0`
   - Benutzernummer `306375`
   - Name `Segelfluggruppe Biel, Segelfluggruppe Biel`
3. Kein anderer Datensatz wird ausgeschlossen.

## Installation

Den Inhalt dieses Fix-Pakets in das Root-Verzeichnis `C:\Projekte\LSZJ_flightlist` kopieren. `CsvReader.php` ersetzen lassen.

Danach im Projekt-Root:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\apply_fix_01.ps1
php -l .\src\MasterData\CsvReader.php
php -l .\src\MasterData\VereinsfliegerCsvImporter.php
```

PHP-Server neu starten und Import erneut ausführen.
