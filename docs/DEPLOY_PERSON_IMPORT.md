# Deployment Personenimport

1. DB sichern.
2. Migration `database/20260922_person_membership_sectors.sql` ausführen.
3. Overlay-Dateien deployen.
4. `php tools/test_pilot_sector_policy.php` und PHP-Lint ausführen.
5. REST-Vorschau in `vf_person_sync.php` prüfen und einmal manuell importieren.
6. Suchtests: Segelflug, Motorflug, Motorsegler, Person mit Status Sonstiger, Person ohne Sparte.
7. Erst danach Cron aktivieren.

Cron auf Hostpoint nach Prüfung von `command -v php`:

```cron
5 0 * * * /usr/local/bin/php /home/sgbielc/www/test-startliste.lszj.ch/bin/vf_person_sync.php >> /home/sgbielc/logs/vf-person-sync.log 2>&1
```

Fachregel: Mitgliedsstatus wird gespeichert und angezeigt, blockiert aber nie die Pilotenauswahl. Explizite Sparten steuern den Flugkontext. Motorsegler gilt für beide Kontexte. Fehlende Sparte bleibt für beide Kontexte auswählbar, damit externe/sonstige Piloten nicht ausgesperrt werden.
