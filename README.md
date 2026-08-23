# LSZJ Autocomplete Fix: Begleiter/FI und Externe

Ersetzt:
- public/master_data_autocomplete.js
- public/master_data_autocomplete.css

Änderungen:
- Begleiter/FI wird auch über Labeltext robust erkannt.
- Dynamisch direkt eingefügte Input-Felder werden erkannt.
- Pro Pilotenfeld ist „Externer Pilot / FI“ immer sichtbar.
- Bei „Keine Treffer“ erscheint zusätzlich ein Button zum Aktivieren des Freitextmodus.
- Im Freitextmodus wird die Trefferliste geschlossen und Freitext akzeptiert.
- Der globale Schalter „Alle bekannten Piloten anzeigen“ wiederholt die aktuelle Suche.
- Bereits gespeicherte, unveränderte Altwerte bleiben speicherbar.

Nach dem Kopieren PHP-Server neu starten und Browser mit Strg+F5 neu laden.
