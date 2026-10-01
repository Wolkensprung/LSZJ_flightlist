# Systematischer LSZJ-i18n-Audit

Grundlage: angehängter Projektstand `LSZJ_flightlist.txt`.

## Ergebnis

- 198 deutsche UI-/System-/Technikschlüssel mit französischer Anzeige erfasst
- 396 idempotente DB-Upsert-Zeilen
- technische Werte bleiben intern unverändert und werden nur in der UI übersetzt
- native kTrax `confirm()`/`alert()`-Dialoge durch eigenen zweisprachigen Dialog ersetzt
- lange kTrax-Resultate erhalten einen Scrollbereich
- Datumswerte werden als `TT.MM.JJJJ` dargestellt
- `i18n_audit.js` meldet mögliche deutsche Resttexte in der Browserkonsole

## Bewusste Ausnahmen

- Namen, Kennzeichen, Flugplätze und echte Benutzerkommentare werden nicht übersetzt.
- Externe Fehlermeldungen von Vereinsflieger bleiben unverändert, weil sie Fremdsysteminhalt sind.
- Datenbank- und API-Feldnamen bleiben technisch unverändert.

## Installation

1. Paket im Projektstamm entpacken.
2. `tools\apply_i18n_complete_fix.ps1` ausführen.
3. Migration `008_complete_french_ui_inventory.sql` mit `SOURCE` und `utf8mb4` ausführen.
4. Syntax prüfen und committen.
