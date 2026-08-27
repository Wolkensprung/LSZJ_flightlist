Korrektur der Seitenvalidierung in der Flugkorrektur.

Direkte Ersatzdateien:
- public/flight_correction.php
- src/api_save_operation_correction.php

Behebt:
- Standardwert charge_mode aktiviert nicht mehr fälschlich die leere Segelflugseite.
- Nicht zusammenführen bei towplane_only prüft und speichert nur Motorflug.
- Zusammenführung erzwingt beide Seiten.
- Bestehende Motor-Flugart und Abrechnung bleiben bei Motor-only erhalten.
- Alter user-Payload entfernt.
