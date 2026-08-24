# Vereinsflieger CSV-Export V2

## Dateien
- `src/export_vf_members_csv.php`
- `src/export_vf_flights_csv.php`

## Voraussetzungen
- `external_contacts.vf_exported_at`
- `accounting_entries.vf_exported_at`

## Aufruf
Personen:
`http://localhost:8000/src/export_vf_members_csv.php`

Flüge, alle freigegebenen und noch nicht exportierten:
`http://localhost:8000/src/export_vf_flights_csv.php`

Optionaler Zeitraum:
`http://localhost:8000/src/export_vf_flights_csv.php?from=2026-08-01&to=2026-08-31`

## Filter
Personen: aktiv und `vf_exported_at IS NULL`.
Flüge: `approval_status = 'approved'` und `vf_exported_at IS NULL`.

Nach Ausgabe werden die exportierten Datensätze markiert.
