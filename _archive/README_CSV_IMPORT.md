# LSZJ Vereinsflieger CSV-Stammdatenimport

## Installation

Den Inhalt dieses Pakets in `C:\Projekte\LSZJ_flightlist` kopieren.

### 1. Tabellen anlegen

```powershell
Get-Content .\sql\010_master_data.sql | mariadb -h localhost -P 3306 -u root lszj_flightlist
```

### 2. Import über Browser

PHP wie gewohnt im Projekt-Root starten:

```powershell
php -S localhost:8000
```

Öffnen:

```text
http://localhost:8000/public/master_data_import.php
```

Dann `Mitglieder.csv` und `Luftfahrzeuge.csv` auswählen.

### 3. Alternativ per Kommandozeile

```powershell
php .\bin\import_master_data.php .\Mitglieder.csv .\Luftfahrzeuge.csv
```

## Gespeicherte Personenfelder

- Vereinsflieger-Benutzernummer als mandantenübergreifend eindeutiger Schlüssel
- Mitgliedsnummer
- Name im Vereinsflieger-Format
- Mailadresse
- Mobilnummer
- Mitgliedsstatus
- Kostenstufe

Primär angezeigt werden künftig:
- Fliegende Mitglieder
- Flugschüler
- GVVC-Mitglieder

Alle übrigen aktiven Datensätze bleiben für die optionale erweiterte Suche erhalten.

## Datenschutz und Git

Die CSV-Dateien enthalten personenbezogene Daten und gehören nicht ins öffentliche Repository. Ergänzen:

```gitignore
Mitglieder.csv
Luftfahrzeuge.csv
/vendor/
*.sql
```

Das SQL-Schema `sql/010_master_data.sql` muss trotz `*.sql` committed werden. Falls `*.sql` bereits ignoriert wird:

```powershell
git add -f .\sql\010_master_data.sql
```
