LSZJ Fachliche Pflichtfeldpruefung

Ersetzt src/api_approve_flight_operation.php und src/api_export_status.php, ergänzt src/flight_validation.php. Danach optional Dashboard-Meldung patchen.

Installation: ZIP im Projektstamm entpacken. Danach: powershell -ExecutionPolicy Bypass -File .\tools\patch_dashboard_export_message.ps1

Pflichtfelder glider_flight: Flugzeug, Start, Landung, Flugzeit >0, Segelflugpilot, Flugart, Abrechnung. Motorminuten optional.
towplane_own: Flugzeug, Start, Landung, Flugzeit >0, Motorpilot, Flugart, Abrechnung.
tow_charge: Flugzeug, Start, Schleppzeit >0, Schleppflugzeug, Schlepppilot, Flugart, Abrechnung.
