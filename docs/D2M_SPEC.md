# D2M: Manuelle VF-Synchronisation

## Zweck
D2M schliesst einen D1-Unterschied nach einer manuellen Korrektur in Vereinsflieger revisionssicher ab. D2M ruft keinerlei VF-REST-Endpunkt auf.

## Fälle
### Nicht abgerechnet
Admin korrigiert den bestehenden VF-Flug manuell. `sync_kind=manual_edit`; aktive VF-flid bleibt gleich.

### Bereits abgerechnet
Admin storniert den alten VF-Flug, bestätigt die Gutschrift, erfasst den korrekten Flug manuell neu und erfasst die neue VF-flid. `sync_kind=billing_replacement`; alte und neue VF-flid bleiben erhalten.

## Vorbedingungen
- ADMIN
- lokaler Status `local_change_pending`
- aktueller D1-Check und D1-Audit
- erfolgreicher ursprünglicher C4-Export
- unveränderte row_version und Payload-Hash
- Pflichtgrund 5 bis 1000 Zeichen
- ausdrückliche Bestätigung der manuellen Prüfung in VF
- beim Ersatz: neue positive VF-flid, verschieden von der alten und nicht anderweitig als aktive Baseline verwendet

## Wirkung
- Audit in `vf_manual_sync_runs`
- neuer Snapshot mit `source=manual_edit` oder `billing_replacement`
- `vf_sync_status=in_sync`
- ursprünglicher C4-Export bleibt unverändert
- keine REST-Anfrage
