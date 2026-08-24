# LSZJ Flightlist

Automatisierte Verarbeitung von Flugbewegungen für die Segelfluggruppe LSZJ.

Die Anwendung importiert Flugdaten aus kTrax, ergänzt und validiert diese durch die Flugleitung und exportiert die Daten anschliessend für den Import nach Vereinsflieger.

---

## Funktionen

### Flugdaten

- Import von Flugbewegungen aus kTrax
- Manuelle Flugerfassung
- Flugfreigaben
- Korrekturworkflow
- Segelflug
- Motorflug
- F-Schlepp
- Eigenstart

### Stammdaten

- Vereinsflieger-Mitglieder
- Flugzeuge
- Externe Piloten und FI

### Autocomplete

Autocomplete für:

- Segelflugpilot
- Begleiter / FI
- Motorpilot
- Segelflugzeug
- Motorflugzeug

### Vereinsflieger

Export von:

- Personen (Mitgliederimport)
- Flügen (Flugimport)

Unterstützt:

- Segelflug
- Motorflug
- F-Schlepp
- Externe Piloten
- Externe FI

---

## Systemübersicht

```text
kTrax
  ↓
LSZJ Flightlist
  ↓
Flugfreigabe
  ↓
CSV Export
  ↓
Vereinsflieger
```

---

## Datenbank

### Stammdaten

```text
pilots_master
aircraft_master
external_contacts
```

### Flugdaten

```text
flight_operations
accounting_entries
```

---

## Externe Piloten

Externe Piloten und FI werden in:

```text
external_contacts
```

gespeichert.

Für den Vereinsflieger-Export werden sie als:

```text
Status      = Sonstige
Kostenstufe = Nichtmitglied
```

exportiert.

Doppelte Exporte werden verhindert über:

```text
vf_exported_at
```

---

## Vereinsflieger Export

### Personen

```text
src/export_vf_members_csv.php
```

Exportiert:

```text
external_contacts
WHERE vf_exported_at IS NULL
```

### Flüge

```text
src/export_vf_flights_csv.php
```

Exportiert:

```text
accounting_entries
WHERE approval_status = 'approved'
AND vf_exported_at IS NULL
```

Nach erfolgreichem Export:

```text
approval_status = exported
vf_exported_at gesetzt
```

---

## Lokale Entwicklung

Starten:

```powershell
start_lszj_dev.bat
```

Anwendung:

```text
http://localhost:8000
```

---

## Projektstatus

### Fertig

✅ kTrax Import

✅ Flugfreigaben

✅ Korrekturworkflow

✅ Manuelle Flugerfassung

✅ Mitgliederimport

✅ Flugzeugimport

✅ Externe Piloten / FI

✅ Vereinsflieger Personenexport

✅ Vereinsflieger Flugexport

✅ F-Schlepp-Unterstützung

### Geplant

- Benutzerverwaltung
- Rollen und Berechtigungen
- GUI Cleanup
- Repository Cleanup
- GitGuardian Bereinigung

---

## Lizenz

Interne Entwicklung für die Segelfluggruppe LSZJ.