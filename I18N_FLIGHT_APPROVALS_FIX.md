# Flugfreigaben: Französisch und Encoding-Fix

## Behoben

- `flight_approvals.php` lud noch `i18n_hotfix_02.js` statt der neuen `i18n.js`.
- beschädigte DB-Werte wie `P??riode`, `Valid??`, `Export??`, `Aujourd???hui` werden entfernt und korrekt neu geschrieben.
- fehlende Texte der untersten Kachel und dynamischen Flugkarten sind ergänzt.
- exakte Übersetzung verhindert gemischte DE/FR-Begriffe.

## Migration unter PowerShell

```powershell
mariadb --default-character-set=utf8mb4 -u lszj -p LSZJ_flightlist -e "SOURCE C:/Projekte/LSZJ_flightlist/database/migrations/007_fix_flight_approvals_french_and_encoding.sql"
```
