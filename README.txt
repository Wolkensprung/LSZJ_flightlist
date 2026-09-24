Direkte Ersatzdateien:
- src/Vereinsflieger/PilotSectorPolicy.php
- src/api_search_pilots.php
- tools/test_pilot_sector_policy.php

Fachregel:
- Keine oder unbekannte Sparte: kein Flugbereich (0/0)
- SF: Segelflug
- MS: Segelflug und Motorflug
- MF: Motorflug
- all=1: Backend hebt den Spartenfilter auf

Hinweis zu public/master_data_autocomplete.js:
Die vorhandene LSZJ-Datei sendet bereits all=1 bei aktivierter Checkbox.
Sie wird deshalb nicht ersetzt; so bleiben die bestehende Autocomplete-
und Externer-Kontakt-Logik unverändert.
