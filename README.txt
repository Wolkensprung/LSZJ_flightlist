LSZJ API Auth Welle 2

Ersetzt:
- src/api_approve_flight_operation.php
- src/api_update_flight_data.php
- public/flight_approvals.php

Migration:
- sql/021_api_auth_wave2.sql

Voraussetzung:
- src/api_authenticated_actor.php aus Welle 1

Installation:
1. Datenbank und drei Dateien sichern.
2. ZIP im Projektstamm entpacken.
3. Migration ausfuehren:
   mariadb -u lszj -p lszj_flightlist -e "source sql/021_api_auth_wave2.sql"
4. Ctrl+F5.

Wirkung:
- Freigaben setzen approved_by und approved_by_user_id aus auth_user().
- Korrektur und Reset leeren beide Freigabeidentitaeten.
- Änderungen protokollieren changed_by aus auth_user().
- user-Feld, URL-Parameter und Payloads wurden aus flight_approvals.php entfernt.
