# LSZJ Manual Duplicate Checks Update

Dieses Paket ergänzt die Duplikat- und Plausibilitätsprüfungen für die manuelle Flugerfassung.

## Neu

- Exakte Duplikate werden blockiert.
- Mögliche Duplikate erzeugen eine Warnung.
- Zeitüberschneidungen für dasselbe Flugzeug erzeugen eine Warnung.
- Mögliche KTrax-Treffer erzeugen eine Warnung.
- Plausibilitaeten werden geprüft:
  - Segelflug > 12h gibt Warnung.
  - Flugdauer < 1 min gibt Warnung.
  - Schleppdauer > 20 min gibt Warnung.

## Force-Workflow

Wenn eine Warnung kommt, fragt manual_flight.php:

Trotzdem speichern?

Bei Ja wird derselbe Request mit force=1 erneut gesendet.

## Dateien

Nach src/ hochladen:
- api_create_manual_flight.php

Nach public/ hochladen:
- manual_flight.php
